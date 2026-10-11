<?php
declare(strict_types=1);
require_once __DIR__ . '/Economics.php';
require_once __DIR__ . '/History.php';

class LichtBlickElectricityPrice extends IPSModule
{
    private const DEFAULTS = [
        'Enabled' => false, 'PostalCode' => '', 'TariffVerified' => false,
        'FetchIntervalMinutes' => 15, 'PriceCorrectionCt' => 0.0, 'MaxCacheAgeHours' => 24,
        'ThresholdCt' => 0.0, 'SafetyMarginPercent' => 25.0, 'HysteresisCt' => 2.0, 'DebugEnabled' => false,
        'DailyLearningEnabled' => false, 'ArchiveID' => 14817, 'GasPriceCt' => 10.48,
        'GasKWhPerM3' => 11.166973, 'GasCounterID' => 25105, 'GasCounterScale' => 1.0,
        'HeatPumpCounterID' => 12254, 'HeatPumpCounterScale' => 0.001,
        'AuxCounterID' => 10054, 'AuxCounterScale' => 0.001, 'ElectricalBackgroundKW' => 0.204,
        'SourceModeID' => 41085, 'HolidayID' => 46970, 'OutsideTemperatureID' => 25911,
        'HeatPumpOnID' => 35931, 'HeatPumpHeatModeID' => 51011, 'HeatPumpCapacityID' => 19207,
        'GasAirPumpID' => 32875, 'RadiatorPumpID' => 44860, 'HotWaterPumpID' => 20574,
        'StorageTemperatureID' => 15709, 'ReferenceCapacityPercent' => 50.0, 'MinimumReferenceDays' => 3,
        'LearningRooms' => '[{"name":"Living / dining","temperatureID":57694,"targetID":12594},{"name":"Guest bedrooms","temperatureID":44502,"targetID":40975},{"name":"Master bedroom","temperatureID":15880,"targetID":30481},{"name":"Blue / kitchen","temperatureID":57255,"targetID":37341}]'
    ];

    public function Create()
    {
        parent::Create();
        foreach (self::DEFAULTS as $name => $default) {
            if (is_bool($default)) $this->RegisterPropertyBoolean($name, $default);
            elseif (is_int($default)) $this->RegisterPropertyInteger($name, $default);
            elseif (is_float($default)) $this->RegisterPropertyFloat($name, $default);
            else $this->RegisterPropertyString($name, $default);
        }
        foreach (['PriceCache', 'PriceIdentity', 'LearningIdentity', 'ReferenceDays', 'LastAttemptDay', 'LastAppliedDay'] as $name) {
            $this->RegisterAttributeString($name, '');
        }
        $this->RegisterTimer('Tick', 0, 'LBEP_Tick($_IPS["TARGET"]);');
        $this->profiles();
        $this->RegisterVariableFloat('CurrentPriceCt', 'Electricity price (ct/kWh)', 'LBEP.Price', 10);
        $this->RegisterVariableBoolean('PriceValid', 'Current price is valid and tariff confirmed', '', 20);
        $this->RegisterVariableInteger('PriceStart', 'Price interval starts', '~UnixTimestamp', 30);
        $this->RegisterVariableInteger('PriceEnd', 'Price interval ends', '~UnixTimestamp', 40);
        $this->RegisterVariableInteger('LastSuccessfulFetch', 'Last successful price fetch', '~UnixTimestamp', 50);
        $this->RegisterVariableString('PriceSchedule', 'Price schedule (JSON)', '~TextBox', 60);
        $this->RegisterVariableString('PriceStatus', 'Price status', '', 70);
        $this->RegisterVariableString('FetchStatus', 'Last fetch status', '', 80);
        $this->RegisterVariableFloat('ThresholdCt', 'Break-even threshold (ct/kWh)', 'LBEP.Price', 90);
        $this->RegisterVariableFloat('EffectiveThresholdCt', 'Threshold after safety margin (ct/kWh)', 'LBEP.Price', 100);
        $this->RegisterVariableFloat('EntryThresholdCt', 'Heat-pump entry threshold (ct/kWh)', 'LBEP.Price', 110);
        $this->RegisterVariableInteger('RecommendedSource', 'Air-heating source recommendation', 'LBEP.Source', 120);
        $this->RegisterVariableBoolean('RecommendationValid', 'Economic recommendation is valid', '', 130);
        $this->RegisterVariableBoolean('HeatPumpRecommended', 'Heat pump recommended for air heating', '', 140);
        $this->RegisterVariableBoolean('GasRecommended', 'Gas recommended for air heating', '', 150);
        $this->RegisterVariableString('DecisionReason', 'Recommendation reason', '', 160);
        $this->RegisterVariableFloat('DailyCandidateCt', 'Latest daily candidate (ct/kWh)', 'LBEP.Price', 170);
        $this->RegisterVariableInteger('ThresholdCalculatedAt', 'Last accepted threshold update', '~UnixTimestamp', 180);
        $this->RegisterVariableString('DailyStatus', 'Daily calculation status', '~TextBox', 190);
        $this->RegisterVariableString('DebugLog', 'Debug log', '~TextBox', 200);
    }

    private function profiles(): void
    {
        if (!IPS_VariableProfileExists('LBEP.Price')) {
            IPS_CreateVariableProfile('LBEP.Price', 2);
            IPS_SetVariableProfileDigits('LBEP.Price', 3);
            IPS_SetVariableProfileText('LBEP.Price', '', ' ct/kWh');
        }
        if (!IPS_VariableProfileExists('LBEP.Source')) {
            IPS_CreateVariableProfile('LBEP.Source', 1);
            foreach ([0 => 'Unavailable / disabled', 1 => 'Heat pump', 2 => 'Gas'] as $value => $text) {
                IPS_SetVariableProfileAssociation('LBEP.Source', $value, $text, '', -1);
            }
        }
    }

    private function configuration(): array
    {
        $config = [];
        foreach (self::DEFAULTS as $name => $default) {
            if (is_bool($default)) $config[$name] = $this->ReadPropertyBoolean($name);
            elseif (is_int($default)) $config[$name] = $this->ReadPropertyInteger($name);
            elseif (is_float($default)) $config[$name] = $this->ReadPropertyFloat($name);
            else $config[$name] = $this->ReadPropertyString($name);
        }
        return $config;
    }

    private function validate(array $c): void
    {
        foreach (self::DEFAULTS as $name => $default) {
            if (!array_key_exists($name, $c)) throw new InvalidArgumentException('Missing setting ' . $name);
            $v = $c[$name];
            $ok = is_float($default) ? (is_int($v) || is_float($v)) && is_finite((float)$v)
                : gettype($default) === gettype($v);
            if (!$ok) throw new InvalidArgumentException('Wrong type for ' . $name);
        }
        if ($c['PostalCode'] !== '' && !preg_match('/^\d{5}$/D', $c['PostalCode'])) throw new InvalidArgumentException('Postcode must have five digits');
        foreach (['SafetyMarginPercent' => [0, 95], 'ThresholdCt' => [0, 1000], 'HysteresisCt' => [0, 100],
            'FetchIntervalMinutes' => [5, 1440], 'MaxCacheAgeHours' => [1, 48], 'PriceCorrectionCt' => [-100, 100],
            'GasPriceCt' => [0.001, 100], 'GasKWhPerM3' => [1, 20], 'GasCounterScale' => [0.000001, 100],
            'HeatPumpCounterScale' => [0.000001, 100], 'AuxCounterScale' => [0.000001, 100],
            'ElectricalBackgroundKW' => [0, 10], 'ReferenceCapacityPercent' => [1, 100], 'MinimumReferenceDays' => [1, 14]] as $key => $bounds) {
            if ($c[$key] < $bounds[0] || $c[$key] > $bounds[1]) throw new InvalidArgumentException('Out of range: ' . $key);
        }
        foreach ($c as $key => $value) {
            if (str_ends_with($key, 'ID') && $value < 0) throw new InvalidArgumentException('Negative variable/instance ID');
        }
        $rooms = json_decode($c['LearningRooms'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($rooms) || !array_is_list($rooms) || count($rooms) > 20) throw new InvalidArgumentException('Invalid learning rooms');
        foreach ($rooms as $room) {
            foreach (['temperatureID', 'targetID'] as $key) {
                if (!isset($room[$key]) || !is_int($room[$key]) || $room[$key] <= 0) throw new InvalidArgumentException('Invalid room variable');
            }
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        try {
            $c = $this->configuration(); $this->validate($c);
            $identity = hash('sha256', $c['PostalCode'] . '|' . $c['PriceCorrectionCt']);
            $previousIdentity = $this->ReadAttributeString('PriceIdentity');
            if ($identity !== $previousIdentity) {
                $this->WriteAttributeString('PriceIdentity', $identity);
                $this->WriteAttributeString('PriceCache', '');
                $this->SetValue('LastSuccessfulFetch', 0);
                $this->SetValue('PriceSchedule', '[]');
                $this->SetValue('RecommendedSource', 0);
                if ($previousIdentity !== '' && $c['TariffVerified']) {
                    // A different region/correction must be checked against the contract again.
                    IPS_SetProperty($this->InstanceID, 'TariffVerified', false);
                    IPS_ApplyChanges($this->InstanceID);
                    $this->UpdateFormField('TariffVerified', 'value', false);
                    return;
                }
            }
            $model = $c;
            foreach (['Enabled','PostalCode','TariffVerified','FetchIntervalMinutes','PriceCorrectionCt','MaxCacheAgeHours',
                'ThresholdCt','SafetyMarginPercent','HysteresisCt','DebugEnabled','DailyLearningEnabled'] as $key) unset($model[$key]);
            $identity = hash('sha256', json_encode($model));
            if ($identity !== $this->ReadAttributeString('LearningIdentity')) {
                $this->WriteAttributeString('LearningIdentity', $identity);
                $this->WriteAttributeString('ReferenceDays', '');
                $this->WriteAttributeString('LastAttemptDay', '');
                $this->SetValue('DailyCandidateCt', 0.0);
            }
            $this->SetStatus(102);
            $this->SetTimerInterval('Tick', $c['Enabled'] ? 60000 : 0);
            $this->EvaluatePrices();
        } catch (Throwable $e) {
            $this->SetTimerInterval('Tick', 0); $this->SetStatus(201);
            $this->recommend(0, false, 'Configuration error: ' . $e->getMessage());
            $this->SetValue('PriceValid', false);
        }
    }

    public function Tick(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')) return;
        $last = (int)$this->GetValue('LastSuccessfulFetch');
        // Failed requests are rate limited too; cached prices remain usable within their validity period.
        if (time() - (int)$this->GetBuffer('LastFetchAttempt') >= $this->ReadPropertyInteger('FetchIntervalMinutes') * 60
            || ($last === 0 && $this->GetBuffer('LastFetchAttempt') === '')) $this->RefreshPrices();
        $this->EvaluatePrices();
        $local = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')));
        [$date] = LichtBlickEconomics::previousDay(time());
        if ($local->format('H:i') >= '00:10' && $this->ReadPropertyBoolean('DailyLearningEnabled')
            && $this->ReadAttributeString('LastAttemptDay') !== $date) $this->CalculateYesterday();
    }

    /** Fixed public website endpoint; no account credentials or arbitrary URL. */
    protected function download(string $postcode): string
    {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension unavailable');
        $curl = curl_init('https://www.lichtblick.de/api/energy-price-curve/' . $postcode . '/');
        $body = '';
        curl_setopt_array($curl, [CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'IP-Symcon-LichtBlickElectricityPrice/0.3',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 1048576) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($curl); $error = curl_error($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($ok === false || $status !== 200) throw new RuntimeException('Price fetch failed: HTTP ' . $status . ' ' . $error);
        return $body;
    }

    public function RefreshPrices(): void
    {
        if (!IPS_SemaphoreEnter('LBEP.Price.' . $this->InstanceID, 100)) return;
        try {
            $this->SetBuffer('LastFetchAttempt', (string)time());
            $c = $this->configuration(); $this->validate($c);
            if (!preg_match('/^\d{5}$/D', $c['PostalCode'])) throw new RuntimeException('Set your delivery postcode first');
            $slots = LichtBlickEconomics::parsePrices($this->download($c['PostalCode']), $c['PriceCorrectionCt']);
            if (LichtBlickEconomics::slotAt($slots, time()) === null) throw new RuntimeException('Response does not cover the current interval');
            $this->WriteAttributeString('PriceCache', json_encode($slots, JSON_THROW_ON_ERROR));
            $this->SetValue('PriceSchedule', json_encode($slots, JSON_PRETTY_PRINT));
            $this->SetValue('LastSuccessfulFetch', time());
            $this->SetValue('FetchStatus', 'OK: ' . count($slots) . ' actual 15-minute intervals');
            $this->debug('Fetched ' . count($slots) . ' prices');
        } catch (Throwable $e) {
            $this->SetValue('FetchStatus', $e->getMessage()); $this->debug($e->getMessage());
        } finally { IPS_SemaphoreLeave('LBEP.Price.' . $this->InstanceID); }
        $this->EvaluatePrices();
    }

    public function EvaluatePrices(): void
    {
        try {
            $c = $this->configuration(); $this->validate($c);
            $ceiling = $c['ThresholdCt'] * (1 - $c['SafetyMarginPercent'] / 100);
            $this->SetValue('ThresholdCt', $c['ThresholdCt']);
            $this->SetValue('EffectiveThresholdCt', $ceiling);
            $this->SetValue('EntryThresholdCt', $ceiling - $c['HysteresisCt']);
            $slots = json_decode($this->ReadAttributeString('PriceCache') ?: '[]', true, 32, JSON_THROW_ON_ERROR);
            $slot = LichtBlickEconomics::slotAt($slots, time());
            $fresh = time() - (int)$this->GetValue('LastSuccessfulFetch') < $c['MaxCacheAgeHours'] * 3600;
            $this->SetValue('CurrentPriceCt', $slot ? (float)$slot['price'] : 0.0);
            $this->SetValue('PriceStart', $slot ? $slot['start'] : 0);
            $this->SetValue('PriceEnd', $slot ? $slot['end'] : 0);
            $valid = $slot !== null && $fresh && $c['TariffVerified'];
            $this->SetValue('PriceValid', $valid);
            $this->SetValue('PriceStatus', !$slot ? 'No price for current interval' : (!$fresh ? 'Price cache expired'
                : (!$c['TariffVerified'] ? 'Regional quote: tariff confirmation required' : 'Valid current tariff price')));
            if (!$c['Enabled']) { $this->recommend(0, false, 'Recommendation disabled'); return; }
            if (!$c['TariffVerified'] || $c['ThresholdCt'] <= 0 || $c['PostalCode'] === '') {
                $this->recommend(0, false, 'Configure postcode, positive threshold and confirm tariff'); return;
            }
            if (!$valid) { $this->recommend(2, false, 'Gas fallback: current electricity price unavailable or expired'); return; }
            $source = LichtBlickEconomics::source($slot['price'], $c['ThresholdCt'], $c['SafetyMarginPercent'],
                $c['HysteresisCt'], (int)$this->GetValue('RecommendedSource'));
            $this->recommend($source, true, sprintf('%s: %.3f ct/kWh; entry below %.3f; exit at %.3f',
                $source === 1 ? 'Heat pump' : 'Gas', $slot['price'], $ceiling - $c['HysteresisCt'], $ceiling));
        } catch (Throwable $e) {
            $this->SetValue('PriceValid', false); $this->recommend(0, false, 'Invalid settings/cache: ' . $e->getMessage());
        }
    }

    private function recommend(int $source, bool $valid, string $reason): void
    {
        $old = (int)$this->GetValue('RecommendedSource');
        $this->SetValue('RecommendedSource', $source); $this->SetValue('RecommendationValid', $valid);
        $this->SetValue('HeatPumpRecommended', $source === 1); $this->SetValue('GasRecommended', $source === 2);
        $this->SetValue('DecisionReason', $reason);
        if ($old !== $source) $this->debug($reason);
    }

    public function CalculateYesterday(): void
    {
        [$date, $start, $end] = LichtBlickEconomics::previousDay(time());
        $this->calculateDay($date, $start, $end, true);
    }

    /** Seed a specific completed historical day, without changing the configured threshold. */
    public function AddReferenceDay(string $date): string
    {
        try {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Berlin'));
            if (!$start || $start->format('Y-m-d') !== $date || $start->modify('+1 day')->getTimestamp() > time()) {
                throw new InvalidArgumentException('Use a completed Berlin calendar day (YYYY-MM-DD)');
            }
            $this->calculateDay($date, $start->getTimestamp(), $start->modify('+1 day')->getTimestamp(), false);
        } catch (Throwable $e) { $this->SetValue('DailyStatus', $e->getMessage()); }
        return (string)$this->GetValue('DailyStatus');
    }

    private function calculateDay(string $date, int $start, int $end, bool $update): void
    {
        if (!IPS_SemaphoreEnter('LBEP.Learn.' . $this->InstanceID, 100)) return;
        $newThreshold = null;
        try {
            $c = $this->configuration(); $this->validate($c);
            if ($update) $this->WriteAttributeString('LastAttemptDay', $date);
            if ($update && !$c['DailyLearningEnabled']) throw new RuntimeException('Daily learning disabled');
            if ($update && $this->ReadAttributeString('LastAppliedDay') === $date) throw new RuntimeException('Threshold already updated from this day');
            if ($update && $c['ThresholdCt'] <= 0) throw new RuntimeException('Enter a positive initial threshold');
            $day = (new LichtBlickHistory($c['ArchiveID'], $start, $end))->day($c);
            $day['date'] = $date;
            $days = json_decode($this->ReadAttributeString('ReferenceDays') ?: '{}', true, 32, JSON_THROW_ON_ERROR);
            $days[$date] = $day; ksort($days);
            // Keep at most 60 quality-checked reference days (even when seeded out of chronological order).
            $days = array_slice($days, -60, null, true);
            $this->WriteAttributeString('ReferenceDays', json_encode($days, JSON_THROW_ON_ERROR));
            if (!$update) { $this->SetValue('DailyStatus', 'Stored reference day ' . $date . '; no threshold change'); return; }
            $candidates = [];
            foreach ($days as $otherDate => $other) {
                if ($otherDate >= $date || $start - (new DateTimeImmutable($otherDate, new DateTimeZone('Europe/Berlin')))->getTimestamp() > 60 * 86400
                    || $other['source'] === $day['source']) continue;
                $hp = $day['source'] === 1 ? $day : $other;
                $gas = $day['source'] === 2 ? $day : $other;
                $candidate = LichtBlickEconomics::candidate($hp, $gas, $c['GasPriceCt'], $c['GasKWhPerM3'], $c['ElectricalBackgroundKW']);
                if ($candidate !== null) $candidates[] = $candidate;
            }
            if (count($candidates) < $c['MinimumReferenceDays']) throw new RuntimeException('Retained threshold: ' . count($candidates) . ' suitable opposite-source days; need ' . $c['MinimumReferenceDays']);
            sort($candidates, SORT_NUMERIC); $n = count($candidates);
            $candidate = ($candidates[(int)floor(($n - 1) / 2)] + $candidates[(int)floor($n / 2)]) / 2;
            $this->SetValue('DailyCandidateCt', $candidate);
            $newThreshold = LichtBlickEconomics::smooth($c['ThresholdCt'], $candidate);
            if (IPS_SetProperty($this->InstanceID, 'ThresholdCt', $newThreshold) === false) {
                throw new RuntimeException('Unable to save calculated threshold');
            }
            if (IPS_ApplyChanges($this->InstanceID) === false) {
                IPS_SetProperty($this->InstanceID, 'ThresholdCt', $c['ThresholdCt']);
                IPS_ApplyChanges($this->InstanceID);
                throw new RuntimeException('Unable to apply calculated threshold; restored previous value');
            }
            $this->UpdateFormField('ThresholdCt', 'value', $newThreshold);
            $this->WriteAttributeString('LastAppliedDay', $date);
            $this->SetValue('ThresholdCalculatedAt', time());
            $this->SetValue('DailyStatus', sprintf('%s: raw candidate %.3f, applied %.3f ct/kWh from %d matches at %.0f%% reference capacity; margin stays %.1f%%',
                $date, $candidate, $newThreshold, $n, $c['ReferenceCapacityPercent'], $c['SafetyMarginPercent']));
        } catch (Throwable $e) { $this->SetValue('DailyStatus', $date . ': ' . $e->getMessage()); }
        finally { IPS_SemaphoreLeave('LBEP.Learn.' . $this->InstanceID); }
        $this->debug((string)$this->GetValue('DailyStatus'));
    }

    public function ExportConfig(): string
    {
        $json = json_encode(['module' => 'LichtBlickElectricityPrice', 'schema' => 1, 'configuration' => $this->configuration()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->UpdateFormField('BackupJson', 'value', $json);
        return $json;
    }

    public function ImportConfig(string $json): string
    {
        try {
            if (strlen($json) > 131072) throw new InvalidArgumentException('Backup too large');
            $backup = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (($backup['module'] ?? '') !== 'LichtBlickElectricityPrice' || ($backup['schema'] ?? 0) !== 1
                || !is_array($backup['configuration'] ?? null)) throw new InvalidArgumentException('Unsupported backup');
            $c = $backup['configuration'];
            if (array_diff(array_keys($c), array_keys(self::DEFAULTS))) throw new InvalidArgumentException('Unknown configuration keys');
            $this->validate($c); // Atomic validation before any writes.
            $c['Enabled'] = false; $c['DailyLearningEnabled'] = false; $c['TariffVerified'] = false;
            IPS_SetConfiguration($this->InstanceID, json_encode($c, JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('PriceCache', ''); $this->WriteAttributeString('ReferenceDays', '');
            $this->WriteAttributeString('LastAttemptDay', ''); $this->WriteAttributeString('LastAppliedDay', '');
            $this->SetValue('LastSuccessfulFetch', 0); $this->SetValue('PriceSchedule', '[]');
            $this->SetValue('RecommendedSource', 0); $this->SetValue('ThresholdCalculatedAt', 0);
            IPS_ApplyChanges($this->InstanceID);
            $this->ReloadForm();
            return 'Imported. Recommendation and learning disabled; reconfirm tariff before enabling.';
        } catch (Throwable $e) { return 'Import failed: ' . $e->getMessage(); }
    }

    public function ClearDebug(): void { $this->SetValue('DebugLog', ''); }
    private function debug(string $line): void
    {
        if (!$this->ReadPropertyBoolean('DebugEnabled')) return;
        $log = (string)$this->GetValue('DebugLog') . gmdate('c') . ' ' . $line . "\n";
        $this->SetValue('DebugLog', strlen($log) > 32000 ? substr($log, -32000) : $log);
    }
}
