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
        $selected = $this->ReadPropertyInteger('VaultInstanceID');
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
            'MasterDisableID'=>['Master disable', 'bool'],
            'WinterID'=>['Winter / heating season', 'bool'],
            'NightDisableID'=>['Night shutdown', 'bool'],
            'HolidayID'=>['Holiday override', 'bool'],
            'RecoveryEnableID'=>['Allow residual heat recovery', 'bool'],
            'OperatingModeID'=>['Heat source', 'mode'],
            'HysteresisID'=>['Room hysteresis', 'number', 0, 5, 0.05, '°C'],
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
            $id = str_starts_with($key,'room:') ? $rooms[(int)substr($key,5)]['targetID'] : $this->id($key);
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
            'mixerOpen'=>$this->ReadPropertyInteger('MixerOpen'), 'mixerClosed'=>$this->ReadPropertyInteger('MixerClosed'),
            'rooms'=>$roomData, 'environment'=>$environment, 'equipment'=>$equipment, 'controls'=>$controls];
    }

    private function webCommand(array $payload): array
    {
        if (array_diff(array_keys($payload), ['control','value']) || count($payload) !== 2) {
            throw new InvalidArgumentException('Only control and value are accepted.');
        }
        if (!is_string($payload['control'] ?? null)) throw new InvalidArgumentException('Select a supported control.');
        if (IPS_GetKernelRunlevel() !== KR_READY) throw new RuntimeException('Symcon is not ready.');
        if (!$this->ReadPropertyBoolean('Enabled') || $this->ReadPropertyBoolean('DryRun')) {
            throw new InvalidArgumentException('Controller disabled or in dry run: page is read-only.');
        }
        $lock = 'HeatingControl.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1000)) throw new RuntimeException('Controller busy; retry.');
        try {
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
            if (GetValue($control['id']) !== $value) {
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
            if (isset($query['view'])) return $reply(404,['error'=>'Unknown view.']);
            $nonce = base64_encode(random_bytes(24));
            $escape = static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $template = file_get_contents(__DIR__.'/page.html');
            if ($template === false) throw new RuntimeException('Page template missing.');
            return $reply(200,strtr($template,['{{NONCE}}'=>$escape($nonce),'{{PATH}}'=>$escape($this->webPath()),
                '{{CSRF}}'=>$escape($this->webCSRF($vault,(int)floor(time()/3600))), '{{INSTANCE}}'=>(string)$this->InstanceID, '{{PORTAL}}'=>$escape('/hook/secrets_'.$vault.'?portal=1')]),[
                'Content-Type'=>'text/html; charset=utf-8',
                'Content-Security-Policy'=>"default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"]);
        } catch (JsonException|InvalidArgumentException $e) { return $reply(422,['error'=>$e->getMessage()]); }
        catch (Throwable $e) {
            $this->debug('WEB ERROR '.$e->getMessage());
            return $reply(503,['error'=>'Heating request failed. Check module configuration and debug log.']);
        }
    }
}

