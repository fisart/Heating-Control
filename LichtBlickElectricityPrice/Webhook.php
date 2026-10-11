<?php
declare(strict_types=1);

trait LichtBlickElectricityPriceWebhook
{
    private const WEBHOOK_CONTROL = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const PORTAL_VAULT = '{7C5A3841-3F7B-4D2A-9E1C-5B6D8F9A0E12}';

    private function webPath(): string
    {
        return '/hook/lichtblick_' . $this->InstanceID;
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
            $this->debug('LichtBlick page webhook ' . ($enabled ? 'enabled' : 'disabled') . ': ' . $path);
        } catch (Throwable $e) {
            $this->SetValue('WebPath', 'Webhook setup failed: ' . $e->getMessage());
            $this->SetTimerInterval('HookSetup', 0); // Explicit repair after configuration/ownership errors.
            $this->debug('Webhook setup failed: ' . $e->getMessage());
        } finally {
            IPS_SemaphoreLeave('ALPORT.WebhookControl');
        }
    }

    private function webOrigins(int $vault): array
    {
        $origins = [$this->webOrigin((string)IPS_GetProperty($vault, 'PortalOrigin'))];
        try { $origins[] = $this->webOrigin((string)IPS_GetProperty($vault, 'PortalBackupOrigin')); }
        catch (Throwable $e) { /* Older vaults have only one origin. */ }
        return array_values(array_filter(array_unique($origins)));
    }

    private function webPortalPath(): string
    {
        $eligible = [];
        foreach (IPS_GetInstanceListByModuleID('{922E5A10-A048-463D-A973-97B4BBD9096B}') as $id) {
            try { if (IPS_GetProperty((int)$id,'Enabled') === true) $eligible[] = (int)$id; }
            catch (Throwable $e) { continue; }
        }
        return count($eligible) === 1 ? '/hook/alarm_portal_'.$eligible[0] : '';
    }

    /** A read-only snapshot: opening the dashboard never fetches, learns or commands heating. */
    private function webState(): array
    {
        $now = time();
        $values = [];
        foreach (['CurrentPriceCt','PriceValid','PriceStart','PriceEnd','LastSuccessfulFetch','PriceStatus','FetchStatus',
            'ThresholdCt','ReferenceThresholdCt','EffectiveThresholdCt','EntryThresholdCt','DecisionReason',
            'DailyCandidateCt','ThresholdCalculatedAt','DailyStatus','WeatherHeatPumpPowerPercent',
            'WeatherOutsideTemperatureC','WeatherCOP','WeatherSystemCOP','WeatherHeatPumpKW','WeatherModelValid',
            'WeatherModelStatus'] as $key) $values[$key] = $this->GetValue($key);
        $values['PriceAvailable'] = $values['PriceStart'] > 0 && $values['PriceStart'] <= $now
            && $now < $values['PriceEnd'] && $values['LastSuccessfulFetch'] > 0
            && $now < $values['LastSuccessfulFetch'] + $this->ReadPropertyInteger('MaxCacheAgeHours') * 3600;
        $values['PriceValid'] = $values['PriceValid'] === true && $values['PriceAvailable'];
        $snapshot = json_decode((string)$this->GetValue('RecommendationJSON'), true);
        $fresh = is_array($snapshot) && ($snapshot['schema'] ?? 0) === 1 && ($snapshot['valid'] ?? false) === true
            && is_int($snapshot['updatedAt'] ?? null) && $snapshot['updatedAt'] <= $now + 30
            && $now - $snapshot['updatedAt'] <= 180 && is_int($snapshot['validUntil'] ?? null)
            && $now < $snapshot['validUntil'] && in_array($snapshot['source'] ?? null,[1,2],true)
            && is_int($snapshot['powerPercent'] ?? null)
            && ($snapshot['source'] === 2 ? $snapshot['powerPercent'] === 0 : ($snapshot['powerPercent'] >= 1 && $snapshot['powerPercent'] <= 100));
        $enabled = $this->ReadPropertyBoolean('Enabled');
        $fresh = $fresh && $enabled && $values['PriceValid'];
        $outside = null; $outsideUpdated = null;
        $id = $this->ReadPropertyInteger('OutsideTemperatureID');
        if ($id > 1 && IPS_VariableExists($id)) {
            $meta = IPS_GetVariable($id); $v = GetValue($id);
            if (in_array($meta['VariableType'] ?? -1,[1,2],true) && (is_float($v) || is_int($v)) && is_finite((float)$v)) {
                $outside = $v; $outsideUpdated = (int)($meta['VariableUpdated'] ?? 0);
            }
        }
        if ($this->ReadPropertyBoolean('WeatherModelEnabled') && ($outsideUpdated === null || $outsideUpdated <= 0
            || $outsideUpdated > $now + 30 || $now >= $outsideUpdated + $this->ReadPropertyInteger('OutsideTemperatureMaxAgeSeconds'))) {
            $values['WeatherModelValid'] = false; $fresh = false;
        }
        $slots = json_decode((string)$this->GetValue('PriceSchedule'), true);
        if (!is_array($slots) || !array_is_list($slots) || count($slots) > 512) $slots = [];
        $slots = array_values(array_filter($slots, static fn($s)=>is_array($s) && is_int($s['start'] ?? null)
            && is_int($s['end'] ?? null) && $s['end'] > $now && is_numeric($s['price'] ?? null)));
        try { $curve = LichtBlickWeatherModel::curve($this->ReadPropertyString('WeatherCurve')); }
        catch (Throwable $e) { $curve = []; }
        return ['instanceID'=>$this->InstanceID, 'updatedAt'=>$now, 'enabled'=>$enabled,
            'recommendation'=>['valid'=>$fresh, 'source'=>$fresh ? $snapshot['source'] : ($enabled ? 2 : 0),
                'powerPercent'=>$fresh ? $snapshot['powerPercent'] : 0,
                'validUntil'=>$fresh ? $snapshot['validUntil'] : 0,
                'reason'=>$fresh ? (string)($snapshot['reason'] ?? '') : ($enabled ? 'Recommendation unavailable or stale; gas fallback.' : 'Recommendation disabled.')],
            'values'=>$values, 'outside'=>['temperatureC'=>$outside,'updatedAt'=>$outsideUpdated],
            'settings'=>['postalCode'=>$this->ReadPropertyString('PostalCode'),'tariffVerified'=>$this->ReadPropertyBoolean('TariffVerified'),
                'safetyMarginPercent'=>$this->ReadPropertyFloat('SafetyMarginPercent'),'hysteresisCt'=>$this->ReadPropertyFloat('HysteresisCt'),
                'weatherEnabled'=>$this->ReadPropertyBoolean('WeatherModelEnabled'),'fanKW'=>$this->ReadPropertyFloat('WeatherFanKW'),
                'gasPriceCt'=>$this->ReadPropertyFloat('GasPriceCt'),'referenceTemperatureC'=>$this->ReadPropertyFloat('WeatherReferenceTemperatureC'),
                'referenceGasPriceCt'=>$this->ReadPropertyFloat('WeatherReferenceGasPriceCt')],
            'curve'=>$curve, 'slots'=>$slots, 'debug'=>substr((string)$this->GetValue('DebugLog'),-32000)];
    }

    protected function ProcessHookData()
    {
        $result = $this->webResponse($_SERVER, $_GET);
        http_response_code($result['status']);
        foreach ($result['headers'] as $name=>$value) header($name.': '.$value);
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') echo $result['body'];
    }

    private function webResponse(array $server, array $query): array
    {
        $headers = ['Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'private, no-store, max-age=0',
            'Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer','X-Frame-Options'=>'DENY',
            'Content-Security-Policy'=>"default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"];
        $reply = static fn(int $status,$data,array $extra=[])=>['status'=>$status,'headers'=>array_replace($headers,$extra),
            'body'=>is_string($data) ? $data : json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)];
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method,['GET','HEAD'],true)) return $reply(405,['error'=>'Read-only page; method not allowed.'],['Allow'=>'GET, HEAD']);
        if (!$this->ReadPropertyBoolean('WebEnabled')) return $reply(503,['error'=>'LichtBlick web page disabled.']);
        $vault = $this->webVault();
        if (!$vault || !function_exists('SEC_IsPortalAuthenticated')) return $reply(503,['error'=>'An enabled SecretsManager HTTPS portal is required.']);
        try {
            if (SEC_IsPortalAuthenticated($vault) !== true) {
                if ($method === 'GET' && !isset($query['view'])) return $reply(303,'',[
                    'Location'=>'/hook/secrets_'.$vault.'?portal=1&return='.rawurlencode($this->webPath())]);
                return $reply(401,['error'=>'Passkey session expired. Sign in through the portal.']);
            }
            $origin = $this->webOrigin('https://'.($server['HTTP_HOST'] ?? ''));
            if ($origin === '' || !in_array($origin,$this->webOrigins($vault),true)) return $reply(403,['error'=>'Use the configured SecretsManager HTTPS portal origin.']);
            if (($query['view'] ?? '') === 'state') return $reply(200,$this->webState());
            if (isset($query['view'])) return $reply(404,['error'=>'Unknown view.']);
            $nonce = base64_encode(random_bytes(24));
            $escape = static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $template = file_get_contents(__DIR__.'/page.html');
            if ($template === false) throw new RuntimeException('Page template missing.');
            return $reply(200,strtr($template,['{{NONCE}}'=>$escape($nonce),'{{PATH}}'=>$escape($this->webPath()),
                '{{INSTANCE}}'=>(string)$this->InstanceID,'{{PORTAL}}'=>$escape($this->webPortalPath()),
                '{{LOGIN}}'=>$escape('/hook/secrets_'.$vault.'?portal=1&return='.rawurlencode($this->webPath()))]),[
                'Content-Type'=>'text/html; charset=utf-8',
                'Content-Security-Policy'=>"default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"]);
        } catch (Throwable $e) {
            $this->debug('WEB ERROR '.$e->getMessage());
            return $reply(503,['error'=>'LichtBlick request failed. Check module configuration and debug log.']);
        }
    }
}

