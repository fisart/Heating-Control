<?php
declare(strict_types=1);

trait HeatingControlWebhook
{
    private const WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const PORTAL_VAULT = '{7C5A3841-3F7B-4D2A-9E1C-5B6D8F9A0E12}';

    private function webPath(): string
    {
        return '/hook/heating_' . $this->InstanceID;
    }

    private function webOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) return '';
        return 'https://' . strtolower($parts['host'])
            . ((int)($parts['port'] ?? 443) === 443 ? '' : ':' . $parts['port']);
    }

    private function webVault(): int
    {
        return $this->webVaultForSelection($this->ReadPropertyInteger('VaultInstanceID'));
    }

    private function webVaultForSelection(int $selected): int
    {
        if ($selected < 0) return 0;
        $candidates = $selected > 1 ? [$selected] : IPS_GetInstanceListByModuleID(self::PORTAL_VAULT);
        $eligible = [];
        foreach ($candidates as $id) {
            $id = (int)$id;
            if (!IPS_InstanceExists($id)) continue;
            try {
                if (strtoupper((string)IPS_GetInstance($id)['ModuleInfo']['ModuleID']) !== self::PORTAL_VAULT
                    || IPS_GetProperty($id, 'PortalEnabled') !== true
                    || $this->webOrigin((string)IPS_GetProperty($id, 'PortalOrigin')) === '') continue;
                $eligible[] = $id;
            } catch (Throwable $e) { continue; }
        }
        return count($eligible) === 1 ? $eligible[0] : 0;
    }

    /** Link only to one registered, enabled AlarmPortal using this page's vault. */
    private function webPortalPath(int $vault): string
    {
        try {
            $controls = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
            if (count($controls) !== 1) return '';
            $hooks = json_decode((string)IPS_GetProperty((int)$controls[0], 'Hooks'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($hooks) || !array_is_list($hooks)) return '';
            $eligible = [];
            foreach (IPS_GetInstanceListByModuleID('{922E5A10-A048-463D-A973-97B4BBD9096B}') as $id) {
                $id = (int)$id;
                try {
                    if ($id <= 1 || !IPS_InstanceExists($id) || IPS_GetProperty($id, 'Enabled') !== true
                        || $this->webVaultForSelection((int)IPS_GetProperty($id, 'VaultInstanceID')) !== $vault) continue;
                    $path = '/hook/alarm_portal_' . $id;
                    $matches = array_values(array_filter($hooks, static fn($hook) => is_array($hook) && ($hook['Hook'] ?? '') === $path));
                    if (count($matches) === 1 && (int)($matches[0]['TargetID'] ?? 0) === $id) $eligible[$id] = $path;
                } catch (Throwable $e) { continue; }
            }
            return count($eligible) === 1 ? array_values($eligible)[0] : '';
        } catch (Throwable $e) { return ''; }
    }

    public function SetupHook(): void
    {
        $this->SetTimerInterval('HookSetup', 0);
        if (IPS_GetKernelRunlevel() !== 10103) return;
        if (!IPS_SemaphoreEnter('ALPORT.WebhookControl', 1000)) {
            $this->SetTimerInterval('HookSetup', 1500);
            $this->debug('WebhookControl busy, retry scheduled.');
            return;
        }
        try {
            $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL);
            if (count($ids) !== 1) {
                throw new RuntimeException('WebhookControl unavailable or pending changes.');
            }
            $id = (int)$ids[0];
            if (IPS_HasChanges($id)) {
                $this->SetTimerInterval('HookSetup', 1500);
                return; // Preserve pending registry edits.
            }
            $current = json_decode((string)IPS_GetProperty($id, 'Hooks'), true);
            if (!is_array($current) || array_values($current) !== $current) {
                throw new RuntimeException('Invalid webhook registry.');
            }
            $path = $this->webPath();
            $enabled = $this->ReadPropertyBoolean('WebEnabled');
            $next = [];
            $found = false;
            foreach ($current as $entry) {
                if (($entry['Hook'] ?? '') === $path) {
                    if ((int)($entry['TargetID'] ?? 0) !== $this->InstanceID) {
                        throw new RuntimeException('Webhook path already belongs to another object.');
                    }
                    if (!$enabled || $found) continue;
                    $found = true;
                }
                $next[] = $entry;
            }
            if ($enabled && !$found) $next[] = ['Hook' => $path, 'TargetID' => $this->InstanceID];
            if ($next !== $current) {
                IPS_SetProperty($id, 'Hooks', json_encode($next, JSON_THROW_ON_ERROR));
                IPS_ApplyChanges($id);
            }
            $this->SetValue('WebPath', $enabled ? $path : 'Web page disabled');
            $this->debug('Heating page webhook ' . ($enabled ? 'enabled' : 'disabled') . ': ' . $path);
        } catch (Throwable $e) {
            $this->SetValue('WebPath', 'Webhook setup failed: ' . $e->getMessage());
            $this->SetTimerInterval('HookSetup', 0); // Explicit repair after configuration/ownership errors.
            $this->debug('Webhook setup failed: ' . $e->getMessage());
        } finally {
            IPS_SemaphoreLeave('ALPORT.WebhookControl');
        }
    }

    private function webCSRF(int $vault, int $slot): string
    {
        $cookie = (string)($_COOKIE['SEC_PORTAL_V2_' . $vault] ?? '');
        if ($cookie === '' || strlen($cookie) > 1024) throw new RuntimeException('Portal session cookie missing.');
        return $slot . ':' . hash_hmac('sha256',
            $this->webPath() . '|' . $vault . '|' . $cookie . '|' . $slot,
            $this->ReadAttributeString('WebCSRFKey'));
    }

    private function webValidPOST(array $server, int $vault): bool
    {
        $expected = $this->webOrigin('https://' . ($server['HTTP_HOST'] ?? ''));
        if ($expected === '' || !in_array($expected, $this->webOrigins($vault), true)
            || $this->webOrigin((string)($server['HTTP_ORIGIN'] ?? '')) !== $expected
            || ($server['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') return false;
        $token = (string)($server['HTTP_X_HEATING_CSRF'] ?? '');
        $slot = (int)floor(time() / 3600);
        return $token !== '' && (
            hash_equals($this->webCSRF($vault, $slot), $token)
            || hash_equals($this->webCSRF($vault, $slot - 1), $token));
    }

    private function webOrigins(int $vault): array
    {
        $origins = [$this->webOrigin((string)IPS_GetProperty($vault, 'PortalOrigin'))];
        try { $origins[] = $this->webOrigin((string)IPS_GetProperty($vault, 'PortalBackupOrigin')); }
        catch (Throwable $e) { /* Older vaults have only one origin. */ }
        return array_values(array_filter(array_unique($origins)));
    }

    /** Only these configured input variables can be changed through the page. */
    private function webControls(array $rooms): array
    {
        $definitions = [
            'AutomaticSourceSelection'=>['Automatic heating source selection', 'bool'],
            'MasterDisableID'=>['Master disable', 'bool'],
            'WinterID'=>['Winter / heating season', 'bool'],
            'NightDisableID'=>['Night shutdown', 'bool'],
            'HolidayID'=>['Holiday override', 'bool'],
            'RecoveryEnableID'=>['Allow residual heat recovery', 'bool'],
            'OperatingModeID'=>['Heat source', 'mode'],
            'HysteresisID'=>['Heating & recovery hysteresis', 'number', 0, 5, 0.05, '°C'],
            'ResidualDeltaID'=>['Heat recovery / source fan difference', 'number', 0, 30, 0.1, '°C'],
            'FanDesiredSpeedID'=>['Requested fan speed', 'number', 0, 100, 1, '%'],
            'HeatPumpDesiredPowerID'=>['Requested heat pump power', 'number', 0, 100, 1, '%'],
            'GasFlowHeatingID'=>['Gas flow target while heating', 'number', 5, 90, 0.5, '°C'],
            'GasFlowIdleID'=>['Gas flow target while idle', 'number', 5, 90, 0.5, '°C'],
        ];
        foreach ($rooms as $index=>$room) {
            $definitions['room:'.$index] = [$room['name'].' target', 'number', 5, 30, 0.1, '°C'];
        }
        $blocked = [];
        foreach (['OutsideTempID','OutgoingAirTempID','IncomingAirTempID','HeatExchangerTempID','HeatPumpTempID',
                  'AtHomeID','FanOnID','FanSpeedID','HeatPumpOnID','HeatPumpPowerID','HeatPumpHeatModeID',
                  'GasMixerID','GasPumpID','GasFlowTargetID'] as $property) $blocked[] = $this->id($property);
        foreach (array_keys(self::DEFAULT_STATUS_IDS) as $property) $blocked[] = $this->id($property);
        foreach ($rooms as $room) $blocked = array_merge($blocked, $room['sensors'], [$room['flapID'], $room['statusID']]);
        $controls = [];
        foreach ($definitions as $key=>$definition) {
            $id = str_starts_with($key,'room:') ? $rooms[(int)substr($key,5)]['targetID'] : ($key === 'AutomaticSourceSelection' ? $this->GetIDForIdent($key) : $this->id($key));
            $meta = $id > 1 && IPS_VariableExists($id) ? IPS_GetVariable($id) : null;
            $kind = $definition[1];
            $validType = $meta !== null && ($kind === 'bool' ? $meta['VariableType'] === 0 :
                ($kind === 'mode' ? $meta['VariableType'] === 1 : in_array($meta['VariableType'],[1,2],true)));
            $controls[$key] = ['key'=>$key, 'label'=>$definition[0], 'kind'=>$kind, 'id'=>$id,
                'value'=>$meta !== null ? GetValue($id) : null,
                'available'=>$validType && !in_array($id,$blocked,true),
                'min'=>$definition[2] ?? null, 'max'=>$definition[3] ?? null,
                'step'=>$meta !== null && $meta['VariableType'] === 1 ? 1 : ($definition[4] ?? null),
                'unit'=>$definition[5] ?? '', 'updated'=>$meta['VariableUpdated'] ?? null];
            if ($kind === 'mode') $controls[$key]['options'] = [
                ['value'=>$this->ReadPropertyInteger('CoolingModeValue'), 'label'=>'Cooling / heating off'],
                ['value'=>$this->ReadPropertyInteger('HeatPumpModeValue'), 'label'=>'Heat pump'],
                ['value'=>$this->ReadPropertyInteger('GasModeValue'), 'label'=>'Gas heating'],
            ];
        }
        if ($this->GetValue('AutomaticSourceSelection')) {
            $controls['OperatingModeID']['available'] = false;
            $controls['HeatPumpDesiredPowerID']['available'] = false;
        }
        if($this->ReadAttributeString('HeatPumpTestState')!==''||$this->ReadAttributeString('FlapCalibrationState')!=='')foreach($controls as &$control)$control['available']=false;
        unset($control);
        return $controls;
    }

    private function webReading(int $id, bool $sensor = false): array
    {
        if ($id <= 1 || !IPS_VariableExists($id)) return ['id'=>$id, 'name'=>'Unavailable', 'chart'=>false, 'value'=>null, 'updated'=>null, 'stale'=>false];
        $meta = IPS_GetVariable($id);
        $age = $this->ReadPropertyInteger('MaxSensorAgeSeconds');
        return ['id'=>$id, 'name'=>$sensor ? $this->webSensorName($id) : IPS_GetName($id), 'chart'=>$sensor && $this->webArchiveForSensor($id) > 0,
            'value'=>GetValue($id), 'updated'=>$meta['VariableUpdated'] ?? null,
            'stale'=>$age > 0 && time() - ($meta['VariableUpdated'] ?? 0) > $age];
    }

    private function webState(): array
    {
        $rooms = $this->rooms();
        $errors = [];
        try { $this->validateConfiguration($rooms); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
        $controls = $this->webControls($rooms);
        $latch = json_decode($this->ReadAttributeString('DemandLatch'), true) ?: [];
        $roomData = [];
        foreach ($rooms as $index=>$room) {
            $sensors = array_map(fn($id)=>$this->webReading($id,true),$room['sensors']);
            $numbers = array_filter(array_column($sensors,'value'),fn($v)=>is_int($v)||is_float($v));
            $flap = $this->webReading($room['flapID']);
            $roomData[] = ['name'=>$room['name'], 'key'=>'room:'.$index,
                'actual'=>count($numbers) === count($sensors) ? array_sum($numbers)/count($numbers) : null,
                'sensors'=>$sensors, 'target'=>$controls['room:'.$index]['value'],
                'demand'=>array_key_exists($room['name'],$latch) ? (bool)$latch[$room['name']] : null,
                'flap'=>$flap, 'flapClosed'=>$flap['value'] === null ? null : $flap['value'] === $room['closed'], 'flapOpen'=>$flap['value'] === null ? null : $flap['value'] === $room['open']];
        }
        $environment = $equipment = [];
        foreach (['OutsideTempID','IncomingAirTempID','OutgoingAirTempID','HeatExchangerTempID','HeatPumpTempID'] as $key) {
            $environment[$key] = $this->webReading($this->id($key),true);
        }
        foreach (['FanOnID','FanSpeedID','HeatPumpOnID','HeatPumpPowerID','HeatPumpHeatModeID',
                  'GasPumpID','GasMixerID','GasFlowTargetID','AtHomeID'] as $key) $equipment[$key] = $this->webReading($this->id($key));
        $editable = $this->ReadPropertyBoolean('Enabled') && !$this->ReadPropertyBoolean('DryRun') && !$errors;
        return ['instanceID'=>$this->InstanceID, 'updated'=>date(DATE_ATOM), 'enabled'=>$this->ReadPropertyBoolean('Enabled'),
            'dryRun'=>$this->ReadPropertyBoolean('DryRun'), 'editable'=>$editable, 'errors'=>$errors,
            'decision'=>$this->GetValue('DecisionStatus'), 'log'=>$this->GetValue('ActionLog'),
            'residual'=>$this->GetValue('ResidualHeating'), 'lastRoom'=>$this->ReadAttributeString('LastDemandRoom'),
            'recoveryMinimum'=>$this->ReadPropertyFloat('ResidualMinOutgoingTemp'),
            'recoveryRunOnSeconds'=>$this->ReadPropertyInteger('RecoveryRunOnSeconds'),
            'mixerOpen'=>$this->ReadPropertyInteger('MixerOpen'), 'mixerClosed'=>$this->ReadPropertyInteger('MixerClosed'),
            'sourceSelection'=>['automatic'=>(bool)$this->GetValue('AutomaticSourceSelection'), 'effective'=>(int)$this->GetValue('EffectiveSource'),
                'power'=>(int)$this->GetValue('SelectedHeatPumpPower'), 'status'=>(string)$this->GetValue('SourceSelectionStatus'),
                'transfer'=>(string)$this->GetValue('SourceTransferStatus'), 'provider'=>$this->webSourceProvider()],
            'heatPumpTest'=>$this->heatPumpTestView(), 'flapCalibration'=>$this->flapCalibrationView(),
            'rooms'=>$roomData, 'environment'=>$environment, 'equipment'=>$equipment, 'controls'=>$controls];
    }

    /** Verified price context; the controller alone chooses source/power from its matrix. */
    private function webSourceProvider(): array
    {
        $instance=$this->ReadPropertyInteger('ElectricityPriceInstanceID');$now=$this->sourceClock();
        $data=['instanceID'=>$instance,'connected'=>false,'name'=>'No LichtBlick instance selected','path'=>'','valid'=>false,
            'source'=>0,'powerPercent'=>0,'reason'=>'Select the LichtBlick instance in the heating configuration.','readings'=>[]];
        try {
            if(!$this->priceQuoteID())return $data;
            $data['connected']=true;$data['name']=IPS_GetName($instance);
            $quote=json_decode((string)GetValue($this->priceQuoteID()),true,16,JSON_THROW_ON_ERROR);
            $data['valid']=($quote['schema']??0)===1&&($quote['valid']??false)===true&&($quote['enabled']??false)===true
                &&is_int($quote['updatedAt']??null)&&$quote['updatedAt']<=$now+30&&$now-$quote['updatedAt']<=180
                &&is_int($quote['validUntil']??null)&&$quote['validUntil']>$now;
            $data['validUntil']=$data['valid']?$quote['validUntil']:0;
            $data['reason']=$data['valid']?'Verified electricity quote; Heating Control computes the measured decision.':'Price quote unavailable, disabled or expired; automatic selection uses gas.';
            foreach(['CurrentPriceCt'=>2,'PriceValid'=>0,'PriceStart'=>1,'PriceEnd'=>1,'LastSuccessfulFetch'=>1] as $ident=>$type) {
                $id=IPS_GetObjectIDByIdent($ident,$instance);$data['readings'][$ident]=$id&&IPS_VariableExists($id)&&IPS_GetVariable($id)['VariableType']===$type?GetValue($id):null;
            }
            $v=&$data['readings'];$hours=(int)IPS_GetProperty($instance,'MaxCacheAgeHours');
            $v['PriceAvailable']=is_numeric($v['CurrentPriceCt'])&&is_int($v['PriceStart'])&&is_int($v['PriceEnd'])&&$v['PriceStart']>0&&$v['PriceStart']<=$now&&$now<$v['PriceEnd']
                &&is_int($v['LastSuccessfulFetch'])&&$v['LastSuccessfulFetch']>0&&$hours>0&&$now<$v['LastSuccessfulFetch']+$hours*3600;
            $v['PriceValid']=$v['PriceValid']===true&&$v['PriceAvailable'];unset($v);
            if($this->webVault()>0&&IPS_GetProperty($instance,'WebEnabled')&&$this->webVaultForSelection((int)IPS_GetProperty($instance,'VaultInstanceID'))===$this->webVault())
                foreach(IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL) as $hook) {
                    $routes=json_decode((string)IPS_GetProperty($hook,'Hooks'),true);
                    foreach(is_array($routes)?$routes:[] as $route)if(($route['Hook']??'')==='/hook/lichtblick_'.$instance&&($route['TargetID']??0)===$instance)$data['path']='/hook/lichtblick_'.$instance;
                }
        }catch(Throwable $e){$data['valid']=false;$data['reason']='Price context unavailable: '.$e->getMessage();}
        return $data;
    }

    private function webCommand(array $payload): array
    {
        if (array_diff(array_keys($payload), ['control','value']) || count($payload) !== 2) {
            throw new InvalidArgumentException('Only control and value are accepted.');
        }
        if (!is_string($payload['control'] ?? null)) throw new InvalidArgumentException('Select a supported control.');
        if (IPS_GetKernelRunlevel() !== KR_READY) throw new RuntimeException('Symcon is not ready.');
        if (in_array($payload['control'], ['ModuleEnabled','FlapCalibrationStart','FlapCalibrationCancel','FlapCalibrationWaitSeconds'], true)) {
            $value = $payload['value'];
            switch ($payload['control']) {
                case 'ModuleEnabled':
                    if (!is_bool($value)) throw new InvalidArgumentException('Module enable requires a Boolean');
                    $this->SetModuleEnabled($value); break;
                case 'FlapCalibrationWaitSeconds':
                    if (!is_int($value)) throw new InvalidArgumentException('Flap wait requires whole seconds');
                    $this->SetFlapCalibrationWaitSeconds($value); break;
                default:
                    if ($value !== true) throw new InvalidArgumentException('Calibration start/cancel requires true');
                    if ($payload['control'] === 'FlapCalibrationStart') $this->StartFlapCalibration();
                    else $this->CancelFlapCalibration();
            }
            return ['ok'=>true, 'confirmed'=>true, 'state'=>$this->webState()];
        }
        if ($this->ReadAttributeString('FlapCalibrationState') !== '') throw new InvalidArgumentException('Air-flap calibration owns the controls; finish or cancel it first.');
        if (in_array($payload['control'],['HeatPumpTestStart','HeatPumpTestCancel','HeatPumpTestMinutes','HeatPumpTestReboot'],true)) {
            $control=$payload['control'];$value=$payload['value'];
            if($control==='HeatPumpTestMinutes') {
                if(!is_int($value))throw new InvalidArgumentException('Test duration requires whole minutes');
                $this->SetHeatPumpTestMinutes($value);
            } elseif($control==='HeatPumpTestReboot') {
                if(!is_array($value)||count($value)!==2||array_diff(array_keys($value),['enabled','time'])||!is_bool($value['enabled']??null)||!is_string($value['time']??null))throw new InvalidArgumentException('Reboot settings require enabled and HH:MM time');
                $this->SetHeatPumpTestReboot($value['enabled'],$value['time']);
            } else {
                if($value!==true)throw new InvalidArgumentException('Test start/cancel requires true');
                if($control==='HeatPumpTestStart')$this->StartHeatPumpTest();else $this->CancelHeatPumpTest();
            }
            return ['ok'=>true,'confirmed'=>true,'state'=>$this->webState()];
        }
        if($this->testState())throw new InvalidArgumentException('A test owns the heating controls. Cancel it before changing operating settings.');
        if (!$this->ReadPropertyBoolean('Enabled') || $this->ReadPropertyBoolean('DryRun')) {
            throw new InvalidArgumentException('Controller disabled or in dry run: page is read-only.');
        }
        if($payload['control']==='HeatPumpCOPMethod') {
            if(!is_string($payload['value']))throw new InvalidArgumentException('COP method requires measured or surface');
            $this->SetHeatPumpCOPMethod($payload['value']);
            return ['ok'=>true,'confirmed'=>true,'state'=>$this->webState()];
        }
        $lock = 'HeatingControl.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1000)) throw new RuntimeException('Controller busy; retry.');
        try {
            if (!$this->ReadPropertyBoolean('Enabled') || $this->ReadPropertyBoolean('DryRun') || $this->ReadAttributeString('FlapCalibrationState') !== '' || $this->testState()) throw new RuntimeException('Controller disabled or calibration/test owns the controls');
            $rooms = $this->rooms();
            $this->validateConfiguration($rooms);
            $control = $this->webControls($rooms)[$payload['control']] ?? null;
            if ($control === null || !$control['available']) throw new InvalidArgumentException('Control is unavailable or conflicts with a sensor/output.');
            $value = $payload['value'];
            if ($control['kind'] === 'bool') {
                if (!is_bool($value)) throw new InvalidArgumentException('Boolean value required.');
            } elseif ($control['kind'] === 'mode') {
                if (!is_int($value) || !in_array($value,array_column($control['options'],'value'),true)) {
                    throw new InvalidArgumentException('Unsupported heating mode.');
                }
            } else {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)
                    || $value < $control['min'] || $value > $control['max']) throw new InvalidArgumentException('Value outside the allowed range.');
                if (IPS_GetVariable($control['id'])['VariableType'] === 1) {
                    if ((float)$value !== (float)(int)$value) throw new InvalidArgumentException('Integer value required.');
                    $value = (int)$value;
                } else $value = (float)$value;
            }
            $meta = IPS_GetVariable($control['id']);
            $profileName = ($meta['VariableCustomProfile'] ?? '') ?: ($meta['VariableProfile'] ?? '');
            if ($control['kind'] === 'number' && $profileName !== '' && IPS_VariableProfileExists($profileName)) {
                $profile = IPS_GetVariableProfile($profileName);
                if ($profile['MaxValue'] > $profile['MinValue'] && ($value < $profile['MinValue'] || $value > $profile['MaxValue'])) {
                    throw new InvalidArgumentException('Value outside the configured variable profile range.');
                }
            }
            if ($payload['control'] === 'AutomaticSourceSelection') {
                $this->SetValue('AutomaticSourceSelection', $value);
                $this->debug('WEB automatic source selection = ' . json_encode($value));
            } elseif (GetValue($control['id']) !== $value) {
                if (($meta['VariableCustomAction'] ?? 0) || ($meta['VariableAction'] ?? 0)) RequestAction($control['id'],$value);
                elseif ($meta['VariableType'] === 0) SetValueBoolean($control['id'],$value);
                elseif ($meta['VariableType'] === 1) SetValueInteger($control['id'],$value);
                else SetValueFloat($control['id'],$value);
                $this->debug('WEB CONTROL '.$payload['control'].' = '.json_encode($value));
            }
            $confirmed = GetValue($control['id']) === $value;
        } finally { IPS_SemaphoreLeave($lock); }
        $this->ProcessHeating(); // Existing summer/master/night and source logic still applies.
        return ['ok'=>true, 'confirmed'=>$confirmed, 'state'=>$this->webState()];
    }

    protected function ProcessHookData()
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = $method === 'POST' ? (string)file_get_contents('php://input',false,null,0,16385) : '';
        $result = $this->webResponse($_SERVER,$_GET,$body);
        http_response_code($result['status']);
        foreach ($result['headers'] as $name=>$value) header($name.': '.$value);
        if ($method !== 'HEAD') echo $result['body'];
    }

    private function webResponse(array $server, array $query, string $body): array
    {
        $headers = ['Content-Type'=>'application/json; charset=utf-8', 'Cache-Control'=>'private, no-store, max-age=0',
            'Pragma'=>'no-cache', 'X-Content-Type-Options'=>'nosniff', 'Referrer-Policy'=>'no-referrer', 'X-Frame-Options'=>'DENY',
            'Content-Security-Policy'=>"default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"];
        $reply = static fn(int $status,$data,array $extra=[])=>['status'=>$status,'headers'=>array_replace($headers,$extra),
            'body'=>is_string($data) ? $data : json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method,['GET','HEAD','POST'],true)) return $reply(405,['error'=>'Method not allowed'],['Allow'=>'GET, HEAD, POST']);
        if (!$this->ReadPropertyBoolean('WebEnabled')) return $reply(503,['error'=>'Heating web page disabled.']);
        $vault = $this->webVault();
        if (!$vault || !function_exists('SEC_IsPortalAuthenticated')) return $reply(503,['error'=>'An enabled SecretsManager HTTPS portal is required.']);
        try {
            if (SEC_IsPortalAuthenticated($vault) !== true) {
                if ($method === 'GET' && !isset($query['view'])) return $reply(303,'',[
                    'Location'=>'/hook/secrets_'.$vault.'?portal=1&return='.rawurlencode($this->webPath())]);
                return $reply(401,['error'=>'Passkey session expired. Sign in through the portal.']);
            }
            $origin = $this->webOrigin('https://'.($server['HTTP_HOST'] ?? ''));
            if ($origin === '' || !in_array($origin,$this->webOrigins($vault),true)) {
                return $reply(403,['error'=>'Use the configured SecretsManager HTTPS portal origin.']);
            }
            if ($method === 'POST') {
                if (!$this->webValidPOST($server,$vault)) return $reply(403,['error'=>'Invalid origin or session-bound security token.']);
                if (strlen($body)>16384) return $reply(413,['error'=>'Request too large.']);
                // Symcon can expose Content-Type under HTTP_CONTENT_TYPE instead of PHP's standard key.
                $contentType = trim((string)($server['CONTENT_TYPE'] ?? ''));
                if ($contentType === '') $contentType = (string)($server['HTTP_CONTENT_TYPE'] ?? '');
                if (strtolower(trim(explode(';',$contentType,2)[0]))!=='application/json') return $reply(415,['error'=>'JSON body required.']);
                $payload = json_decode($body,true,8,JSON_THROW_ON_ERROR);
                if (!is_array($payload)) throw new InvalidArgumentException('Invalid command body.');
                return $reply(200,$this->webCommand($payload));
            }
            if (($query['view'] ?? '') === 'state') return $reply(200,array_merge($this->webState(),[
                'csrf'=>$this->webCSRF($vault,(int)floor(time()/3600))]));
            if (($query['view'] ?? '') === 'history') return $reply(200,$this->webHistory($query));
            if (($query['view'] ?? '') === 'heatpump-test-results') {
                $report=$this->ReadAttributeString('HeatPumpTestLastReport');
                if($report==='')return $reply(404,['error'=>'No heat-pump test has finished yet.']);
                return $reply(200,$report,['Content-Disposition'=>'attachment; filename="heatpump-test-results.json"']);
            }
            if (($query['view'] ?? '') === 'heatpump-test-readings') {
                $report=json_decode($this->ReadAttributeString('HeatPumpTestLastReport')?:'null',true);
                if(!is_array($report))return $reply(404,['error'=>'No completed test readings available.']);
                $file=$this->testFile($report,'.jsonl');
                if(!is_file($file)||filesize($file)>16777216)return $reply(404,['error'=>'Test readings unavailable or too large.']);
                $data=file_get_contents($file);if($data===false)throw new RuntimeException('Cannot read test file');
                return $reply(200,$data,['Content-Type'=>'application/x-ndjson; charset=utf-8','Content-Disposition'=>'attachment; filename="heatpump-test-readings.jsonl"']);
            }
            if (isset($query['view'])) return $reply(404,['error'=>'Unknown view.']);
            $nonce = base64_encode(random_bytes(24));
            $escape = static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $template = file_get_contents(__DIR__.'/page.html');
            if ($template === false) throw new RuntimeException('Page template missing.');
            return $reply(200,strtr($template,['{{NONCE}}'=>$escape($nonce),'{{PATH}}'=>$escape($this->webPath()),
                '{{CSRF}}'=>$escape($this->webCSRF($vault,(int)floor(time()/3600))), '{{INSTANCE}}'=>(string)$this->InstanceID, '{{PORTAL}}'=>$escape($this->webPortalPath($vault))]),[
                'Content-Type'=>'text/html; charset=utf-8',
                'Content-Security-Policy'=>"default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"]);
        } catch (JsonException|InvalidArgumentException $e) { return $reply(422,['error'=>$e->getMessage()]); }
        catch (Throwable $e) {
            $this->debug('WEB ERROR '.$e->getMessage());
            return $reply(503,['error'=>'Heating request failed. Check module configuration and debug log.']);
        }
    }
}



