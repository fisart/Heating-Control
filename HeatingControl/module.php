<?php
declare(strict_types=1);

/**
 * Berlin heating controller. This module is intentionally disabled on installation.
 * The legacy script must be deactivated before enabling this instance.
 */
require_once __DIR__ . "/Webhook.php";

class HeatingControl extends IPSModule
{
    use HeatingControlWebhook;

    private const DEFAULT_IDS = [
        'MasterDisableID' => 11098,
        'HysteresisID' => 58771,
        'ResidualDeltaID' => 28538,
        'OutsideTempID' => 25911,
        'OutgoingAirTempID' => 40843,
        'IncomingAirTempID' => 15315,
        'HeatExchangerTempID' => 54400,
        'HeatPumpTempID' => 58696,
        'WinterID' => 47386,
        'NightDisableID' => 20141,
        'HolidayID' => 46970,
        'AtHomeID' => 11637,
        'OperatingModeID' => 41085,
        'FanOnID' => 46921,
        'FanSpeedID' => 29718,
        'FanDesiredSpeedID' => 48646,
        'HeatPumpOnID' => 35931,
        'HeatPumpPowerID' => 19207,
        'HeatPumpDesiredPowerID' => 59800,
        'HeatPumpHeatModeID' => 51011,
        'GasMixerID' => 14348,
        'GasPumpID' => 32875,
        'GasFlowTargetID' => 14533,
        'GasFlowHeatingID' => 46820,
        'GasFlowIdleID' => 11595,
    ];

    private const DEFAULT_ROOMS = [
        ['name' => 'Living and Dining Room', 'sensors' => [57694], 'targetID' => 12594, 'flapID' => 36911, 'open' => 0, 'closed' => 100, 'statusID' => 43898, 'legacyGroupID' => 25055],
        ['name' => 'Guest Bedrooms', 'sensors' => [44502], 'targetID' => 40975, 'flapID' => 22150, 'open' => true, 'closed' => false, 'statusID' => 50623, 'legacyGroupID' => 38782],
        ['name' => 'Master Bedroom', 'sensors' => [15880], 'targetID' => 30481, 'flapID' => 40259, 'open' => true, 'closed' => false, 'statusID' => 36744, 'legacyGroupID' => 16188],
        ['name' => 'Blue Room and Kitchen', 'sensors' => [57255], 'targetID' => 37341, 'flapID' => 27527, 'open' => 0, 'closed' => 100, 'statusID' => 53400, 'legacyGroupID' => 36698],
    ];

    private const DEFAULT_STATUS_IDS = [
        'LegacyActionLogID' => 21945,
        'LegacyResidualHeatingID' => 57844,
        'LegacyLastHeatedGroupID' => 52602,
        'LegacyConfigSnapshotID' => 29352,
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Enabled', false);
        $this->RegisterPropertyBoolean('DryRun', true);
        $this->RegisterPropertyBoolean('DebugEnabled', false);
        $this->RegisterPropertyBoolean('WebEnabled', false);
        $this->RegisterPropertyInteger('VaultInstanceID', 0);
        $this->RegisterAttributeString('WebCSRFKey', '');
        if ($this->ReadAttributeString('WebCSRFKey') === '') $this->WriteAttributeString('WebCSRFKey', bin2hex(random_bytes(32)));
        $this->RegisterVariableString('WebPath', 'Heating web page', '~TextBox', 50);
        $this->RegisterTimer('HookSetup', 0, 'HC_SetupHook($_IPS[\'TARGET\']);');
        foreach (self::DEFAULT_IDS as $name => $id) {
            $this->RegisterPropertyInteger($name, $id);
        }
        foreach (self::DEFAULT_STATUS_IDS as $name => $id) {
            $this->RegisterPropertyInteger($name, $id);
        }
        $this->RegisterPropertyInteger('RecoveryEnableID', 0); // Unselected retains existing recovery behavior.
        $this->RegisterPropertyString('Rooms', json_encode(self::DEFAULT_ROOMS, JSON_UNESCAPED_UNICODE));
        $this->RegisterPropertyInteger('IntervalSeconds', 60);
        $this->RegisterPropertyInteger('MaxSensorAgeSeconds', 0); // 0 retains legacy behavior
        $this->RegisterPropertyFloat('ResidualMinOutgoingTemp', 22.0);
        $this->RegisterPropertyInteger('MixerOpen', 0);
        $this->RegisterPropertyInteger('MixerClosed', 100);
        $this->RegisterPropertyInteger('CoolingModeValue', 0);
        $this->RegisterPropertyInteger('HeatPumpModeValue', 1);
        $this->RegisterPropertyInteger('GasModeValue', 2);
        $this->RegisterAttributeString('DemandLatch', '{}');
        $this->RegisterAttributeString('LastDemandRoom', '');
        $this->RegisterAttributeString('WatchIDs', '[]');
        $this->RegisterVariableString('DecisionStatus', 'Heating decision', '~TextBox', 10);
        $this->RegisterVariableString('ActionLog', 'Heating action log', '~TextBox', 20);
        $this->RegisterVariableBoolean('ResidualHeating', 'Residual heating', '', 30);
        $this->RegisterVariableString('DebugLog', 'Heating debug log', '~TextBox', 40);
        $this->RegisterTimer('HeatingTimer', 0, 'HC_ProcessHeating($_IPS[\'TARGET\']);');
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') !== 'RoomConfigurationPopup') continue;
            try {
                // Render both legacy JSON and saved popup rows without modifying properties.
                $rows = $this->decodeRoomRows($this->ReadPropertyString('Rooms'));
                $element['popup']['items'][1]['values'] = $this->roomEditorRows($rows);
            } catch (\Throwable $e) {
                // Keep malformed configuration available for repair; never replace it with defaults.
                $element['popup']['items'] = [
                    ['type' => 'Label', 'caption' => $this->Translate('Room configuration could not be loaded:') . ' ' . $e->getMessage()],
                    ['type' => 'ScriptEditor', 'name' => 'Rooms', 'caption' => 'Repair room configuration', 'rowCount' => 15],
                ];
            }
        }
        unset($element);
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->replaceWatchers();
        // Defer cross-instance changes until after the current ApplyChanges lifecycle.
        $this->SetTimerInterval('HookSetup', IPS_GetKernelRunlevel() === KR_READY ? 1000 : 0);
        $active = $this->ReadPropertyBoolean('Enabled') && IPS_GetKernelRunlevel() === KR_READY;
        $this->SetTimerInterval('HeatingTimer', $active ? max(5, $this->ReadPropertyInteger('IntervalSeconds')) * 1000 : 0);
    }

    public function MessageSink($timeStamp, $senderID, $message, $data)
    {
        if ($message === IPS_KERNELMESSAGE && isset($data[0]) && $data[0] === KR_READY) {
            $this->ApplyChanges();
        } elseif ($message === VM_UPDATE && $this->ReadPropertyBoolean('Enabled')) {
            $this->ProcessHeating();
        }
    }

    public function ProcessHeating(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled') || IPS_GetKernelRunlevel() !== KR_READY) return;
        $lock = 'HeatingControl.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $this->debug('Evaluation started');
            $this->evaluate();
        } catch (\Throwable $e) {
            $this->debug('ERROR ' . get_class($e) . ': ' . $e->getMessage());
            $this->SetValue('DecisionStatus', 'ERROR: ' . $e->getMessage());
            $this->LogMessage('Heating control: ' . $e->getMessage(), KL_ERROR);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function evaluate(): void
    {
        $rooms = $this->rooms();
        $this->validateConfiguration($rooms);
        $queue = [];
        $log = [];
        $disabled = $this->boolValue('MasterDisableID');
        $winter = $this->boolValue('WinterID');
        if ($disabled || !$winter) {
            $this->debug($disabled ? 'Master disabled; no commands' : 'Summer; no commands');
            $this->SetValue('DecisionStatus', ($this->ReadPropertyBoolean('DryRun') ? '[DRY RUN] ' : '') .
                ($disabled ? 'Master disabled: no devices controlled' : 'Summer: no devices controlled'));
            return;
        }

        $night = $this->boolValue('NightDisableID'); // true means heating OFF at night
        $holiday = $this->boolValue('HolidayID');
        $mode = $this->intValue('OperatingModeID');
        $this->debug(sprintf('INPUTS winter=%d nightDisabled=%d holiday=%d mode=%d hysteresis=%.2f',
            (int)$winter, (int)$night, (int)$holiday, $mode, $this->floatValue('HysteresisID')));
        if (($night && !$holiday) || $mode === $this->ReadPropertyInteger('CoolingModeValue')) {
            $this->queueOff($queue);
            $this->dispatch($queue, $log);
            $this->SetValue('ResidualHeating', false);
            $decision = ($night && !$holiday) ? 'Night shutdown' : 'Cooling mode: heating shutdown';
            $this->publish($decision, $log);
            $this->mirrorLegacyStatus($rooms, [], false, null, $decision, $log);
            return;
        }
        if ($mode !== $this->ReadPropertyInteger('HeatPumpModeValue') && $mode !== $this->ReadPropertyInteger('GasModeValue')) {
            throw new \RuntimeException('Unknown operating mode ' . $mode . '; no commands sent');
        }

        $hysteresis = $this->floatValue('HysteresisID');
        if ($hysteresis < 0.0) throw new \RuntimeException('Hysteresis must not be negative');
        $oldLatch = json_decode($this->ReadAttributeString('DemandLatch'), true);
        if (!is_array($oldLatch)) $oldLatch = [];
        $nextLatch = [];
        $demandRooms = [];
        $cutoffRooms = [];
        foreach ($rooms as $room) {
            $temps = [];
            foreach ($room['sensors'] as $id) $temps[] = $this->temperature((int)$id);
            $actual = array_sum($temps) / count($temps);
            $target = $this->temperature((int)$room['targetID'], false);
            $name = $room['name'];
            $previous = (bool)($oldLatch[$name] ?? ($actual < $target)); // first run inside band
            $demand = $actual <= $target - $hysteresis ? true :
                ($actual >= $target + $hysteresis ? false : $previous);
            $nextLatch[$name] = $demand;
            if ($demand) {
                $demandRooms[] = $name;
                $this->queue($queue, (int)$room['flapID'], $room['open']);
            } elseif ($previous && $actual >= $target + $hysteresis) {
                $cutoffRooms[] = $name;
            }
            $log[] = sprintf('%s: %.2f / %.2f °C; demand=%s', $name, $actual, $target, $demand ? 'yes' : 'no');
        }
        if ($demandRooms) {
            foreach ($rooms as $room) {
                if (!$nextLatch[$room['name']]) $this->queue($queue, (int)$room['flapID'], $room['closed']);
            }
            $this->queueHeatSource($queue, $mode);
            $this->queueFanForSource($queue, $mode);
            $this->dispatch($queue, $log);
            $this->WriteAttributeString('DemandLatch', json_encode($nextLatch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('LastDemandRoom', end($demandRooms));
            $this->SetValue('ResidualHeating', false);
            $decision = 'Heating demand: ' . implode(', ', $demandRooms);
            $this->publish($decision, $log);
            $this->mirrorLegacyStatus($rooms, $nextLatch, false, end($demandRooms), $decision, $log);
            return;
        }

        $recoveryEnabled = $this->recoveryEnabled();
        $outgoing = $delta = 0.0;
        if ($recoveryEnabled) {
            $outgoing = $this->temperature($this->ReadPropertyInteger('OutgoingAirTempID'));
            $delta = $this->floatValue('ResidualDeltaID');
            $this->debug(sprintf('RESIDUAL enabled=1 outgoing=%.2f minimum=%.2f delta=%.2f',
                $outgoing, $this->ReadPropertyFloat('ResidualMinOutgoingTemp'), $delta));
        } else {
            $this->debug('RESIDUAL enabled=0: recovery disabled');
        }
        $candidates = $cutoffRooms ?: [$this->ReadAttributeString('LastDemandRoom')];
        $purge = [];
        foreach ($rooms as $room) {
            $useful = $recoveryEnabled && $outgoing >= $this->ReadPropertyFloat('ResidualMinOutgoingTemp')
                && $outgoing > $this->temperature((int)$room['targetID'], false) + $delta
                && in_array($room['name'], $candidates, true);
            $this->queue($queue, (int)$room['flapID'], $useful ? $room['open'] : $room['closed']);
            if ($useful) $purge[] = $room['name'];
        }
        $this->queueHeatOff($queue, !$purge);
        if ($purge) $this->queueFanOn($queue);
        else $this->queueFanOff($queue);
        $this->dispatch($queue, $log);
        $this->WriteAttributeString('DemandLatch', json_encode($nextLatch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->SetValue('ResidualHeating', (bool)$purge);
        $decision = $purge ? 'Residual heat: ' . implode(', ', $purge) :
            ($recoveryEnabled ? 'Idle' : 'Idle: residual heat recovery disabled');
        $this->publish($decision, $log);
        $this->mirrorLegacyStatus($rooms, $nextLatch, (bool)$purge, null, $decision, $log);
    }

    private function queueHeatSource(array &$q, int $mode): void
    {
        if ($mode === $this->ReadPropertyInteger('HeatPumpModeValue')) {
            $this->queue($q, $this->id('GasPumpID'), false);
            $this->queue($q, $this->id('GasMixerID'), $this->ReadPropertyInteger('MixerClosed'));
            $this->queue($q, $this->id('HeatPumpHeatModeID'), true);
            $this->queue($q, $this->id('HeatPumpOnID'), true);
            $this->queue($q, $this->id('HeatPumpPowerID'), $this->clamp($this->intValue('HeatPumpDesiredPowerID')));
        } else {
            $this->queue($q, $this->id('HeatPumpOnID'), false);
            $this->queue($q, $this->id('HeatPumpPowerID'), 0);
            $this->queue($q, $this->id('GasMixerID'), $this->ReadPropertyInteger('MixerOpen'));
            $this->queue($q, $this->id('GasPumpID'), true);
            $this->queue($q, $this->id('GasFlowTargetID'), $this->floatValue('GasFlowHeatingID'));
        }
    }

    private function queueFanForSource(array &$q, int $mode): void
    {
        $source = $mode === $this->ReadPropertyInteger('HeatPumpModeValue')
            ? $this->floatValue('HeatPumpTempID') : $this->floatValue('HeatExchangerTempID');
        $incoming = $this->floatValue('IncomingAirTempID');
        $this->debug(sprintf('FAN source=%.2f incoming=%.2f threshold=%.2f',
            $source, $incoming, $this->floatValue('ResidualDeltaID')));
        if ($source - $incoming >= $this->floatValue('ResidualDeltaID')) $this->queueFanOn($q);
        else $this->queueFanOff($q);
    }

    private function queueOff(array &$q): void
    {
        $this->queueHeatOff($q, true);
        $this->queueFanOff($q);
    }

    private function queueHeatOff(array &$q, bool $idleSetpoint = false): void
    {
        $this->queue($q, $this->id('HeatPumpOnID'), false);
        $this->queue($q, $this->id('HeatPumpPowerID'), 0);
        $this->queue($q, $this->id('GasMixerID'), $this->ReadPropertyInteger('MixerClosed'));
        $this->queue($q, $this->id('GasPumpID'), false);
        if ($idleSetpoint) $this->queue($q, $this->id('GasFlowTargetID'), $this->floatValue('GasFlowIdleID'));
    }

    private function queueFanOn(array &$q): void
    {
        $this->queue($q, $this->id('FanOnID'), true);
        $this->queue($q, $this->id('FanSpeedID'), $this->intValue('FanDesiredSpeedID'));
    }

    private function queueFanOff(array &$q): void
    {
        $this->queue($q, $this->id('FanOnID'), false);
        $this->queue($q, $this->id('FanSpeedID'), 0);
    }

    private function queue(array &$q, int $id, $value): void
    {
        $q[$id] = $value; // Last decision wins; compare only during dispatch.
    }

    private function dispatch(array $q, array &$log): void
    {
        // Validate every destination before sending the first command.
        foreach ($q as $id => $_) {
            if (!IPS_VariableExists($id) || !in_array(IPS_GetVariable($id)['VariableType'], [0, 1, 2], true)) {
                throw new \RuntimeException('Invalid output variable: ' . $id);
            }
        }
        foreach ($q as $id => $value) {
            if (!IPS_VariableExists($id)) throw new \RuntimeException('Output variable missing: ' . $id);
            $v = IPS_GetVariable($id);
            $type = (int)$v['VariableType'];
            if ($type === 0) $value = (bool)$value;
            elseif ($type === 1) $value = (int)round((float)$value);
            elseif ($type === 2) $value = (float)$value;
            else throw new \RuntimeException('Unsupported output type at ' . $id);
            $current = GetValue($id);
            $same = $type === 2 ? abs((float)$current - (float)$value) < 0.01 : $current === $value;
            if ($same) {
                $this->debug('UNCHANGED ' . $id . ' = ' . json_encode($value));
                continue;
            }
            if ($this->ReadPropertyBoolean('DryRun')) {
                $line = 'WOULD SEND ' . $id . ' (' . IPS_GetName($id) . ') => ' . json_encode($value);
                $log[] = $line;
                $this->debug($line);
                continue;
            }
            if (($v['VariableCustomAction'] ?? 0) || ($v['VariableAction'] ?? 0)) {
                RequestAction($id, $value);
            } elseif ($type === 0) SetValueBoolean($id, $value);
            elseif ($type === 1) SetValueInteger($id, $value);
            else SetValueFloat($id, $value);
            $log[] = $id . ' (' . IPS_GetName($id) . ') => ' . json_encode($value);
            $this->debug('COMMAND ' . end($log));
        }
    }

    private function publish(string $decision, array $log): void
    {
        $this->debug('DECISION ' . $decision . '; ' . implode(' | ', $log));
        $this->SetValue('DecisionStatus', ($this->ReadPropertyBoolean('DryRun') ? '[DRY RUN] ' : '') . $decision);
        $this->SetValue('ActionLog', date(DATE_ATOM) . "\n" . implode("\n", $log));
    }

    private function rooms(): array
    {
        $rooms = $this->decodeRoomRows($this->ReadPropertyString('Rooms'));
        if (!$rooms) throw new \RuntimeException('Configure at least one room');
        return $rooms;
    }

    private function decodeRoomRows(string $json): array
    {
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows)) throw new \InvalidArgumentException('Rooms must be an array');
        $rooms = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['name'], $row['sensors'], $row['targetID'], $row['flapID'], $row['open'], $row['closed'])
                || !is_string($row['name']) || !is_array($row['sensors']) || !array_is_list($row['sensors'])
                || !is_int($row['targetID']) || !is_int($row['flapID'])) {
                throw new \InvalidArgumentException('Invalid room configuration');
            }
            $sensors = [];
            foreach ($row['sensors'] as $sensor) {
                $sensorID = is_array($sensor) ? ($sensor['id'] ?? null) : $sensor;
                if (!is_int($sensorID)) throw new \InvalidArgumentException('Invalid room temperature sensor');
                $sensors[] = $sensorID;
            }
            $open = $row['open'];
            $closed = $row['closed'];
            if (array_key_exists('flapType', $row)) {
                if (!in_array($row['flapType'], [0, 1], true) || !is_int($open) || !is_int($closed)) {
                    throw new \InvalidArgumentException('Invalid flap command type');
                }
                $maximum = $row['flapType'] === 0 ? 1 : 100;
                if ($open < 0 || $open > $maximum || $closed < 0 || $closed > $maximum) {
                    throw new \InvalidArgumentException('Flap commands outside allowed range for ' . $row['name']);
                }
                if ($row['flapType'] === 0) {
                    $open = (bool)$open;
                    $closed = (bool)$closed;
                }
            }
            if (!((is_bool($open) && is_bool($closed)) || (is_int($open) && is_int($closed)))) {
                throw new \InvalidArgumentException('Flap open and closed commands must have the same type');
            }
            // Old backups and existing properties have no status fields. Only apply
            // Berlin defaults when the original target/flap wiring still matches.
            $defaults = ['statusID' => 0, 'legacyGroupID' => 0];
            foreach (self::DEFAULT_ROOMS as $original) {
                if ($original['targetID'] === $row['targetID'] && $original['flapID'] === $row['flapID']) {
                    $defaults = $original;
                    break;
                }
            }
            $statusID = $row['statusID'] ?? $defaults['statusID'];
            $groupID = $row['legacyGroupID'] ?? $defaults['legacyGroupID'];
            if (!is_int($statusID) || $statusID < 0 || !is_int($groupID) || $groupID < 0) {
                throw new \InvalidArgumentException('Invalid room status or original group ID');
            }
            // Symcon selectors can represent "not selected" as either 0 or 1.
            if ($statusID === 1) $statusID = 0;
            if ($groupID === 1) $groupID = 0;
            $rooms[] = ['name' => $row['name'], 'sensors' => $sensors, 'targetID' => $row['targetID'],
                'flapID' => $row['flapID'], 'open' => $open, 'closed' => $closed,
                'statusID' => $statusID, 'legacyGroupID' => $groupID];
        }
        return $rooms;
    }

    private function roomEditorRows(array $rooms): array
    {
        $rows = [];
        foreach ($rooms as $room) {
            $rows[] = ['name' => $room['name'],
                'sensors' => array_map(static fn(int $id): array => ['id' => $id], $room['sensors']),
                'targetID' => $room['targetID'], 'flapID' => $room['flapID'],
                'flapType' => is_bool($room['open']) ? 0 : 1,
                'open' => (int)$room['open'], 'closed' => (int)$room['closed'],
                'statusID' => $room['statusID'], 'legacyGroupID' => $room['legacyGroupID']];
        }
        return $rows;
    }

    private function validateConfiguration(array $rooms): void
    {
        $this->recoveryEnabled(); // Validate the optional switch before sending any commands.
        foreach (self::DEFAULT_IDS as $property => $_) {
            $id = $this->id($property);
            if (!IPS_VariableExists($id)) throw new \RuntimeException('Missing input/output ' . $property . ': ' . $id);
        }
        $names = [];
        foreach ($rooms as $room) {
            if (!is_array($room) || !isset($room['name'], $room['sensors'], $room['targetID'], $room['flapID'], $room['open'], $room['closed'])
                || !is_string($room['name']) || trim($room['name']) === ''
                || !is_array($room['sensors']) || !$room['sensors']) {
                throw new \RuntimeException('Invalid room configuration');
            }
            if (isset($names[$room['name']])) throw new \RuntimeException('Duplicate room name: ' . $room['name']);
            $names[$room['name']] = true;
            foreach (array_merge($room['sensors'], [$room['targetID'], $room['flapID']]) as $id) {
                if (!is_int($id) || !IPS_VariableExists($id)) throw new \RuntimeException('Missing room variable ' . $id);
            }
            $type = IPS_GetVariable((int)$room['flapID'])['VariableType'];
            if ($this->id('RecoveryEnableID') > 0 && $this->id('RecoveryEnableID') === $room['flapID']) {
                throw new \RuntimeException('Recovery switch must not also be a room flap command');
            }
            if (($type === 0 && (!is_bool($room['open']) || !is_bool($room['closed'])))
                || ($type === 1 && (!is_int($room['open']) || !is_int($room['closed'])))
                || !in_array($type, [0, 1], true) || $room['open'] === $room['closed']) {
                throw new \RuntimeException('Invalid flap values for ' . $room['name']);
            }
        }
        $this->validateLegacyOutputs($rooms);
    }

    private function validateLegacyOutputs(array $rooms): void
    {
        $protected = [];
        $protected[$this->id('RecoveryEnableID')] = true;
        foreach (array_keys(self::DEFAULT_IDS) as $property) $protected[$this->id($property)] = true;
        foreach ($rooms as $room) {
            foreach (array_merge($room['sensors'], [$room['targetID'], $room['flapID']]) as $id) $protected[$id] = true;
        }
        $outputs = [];
        $check = function (int $id, int $type, string $label) use (&$outputs, $protected): void {
            if ($id === 0) return; // Optional output is not configured.
            if ($id < 0 || !IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] !== $type) {
                throw new \RuntimeException('Invalid status variable for ' . $label . ': ' . $id);
            }
            if (isset($protected[$id]) || isset($outputs[$id])) {
                throw new \RuntimeException('Status variable conflicts with another input/output: ' . $id);
            }
            $outputs[$id] = true;
        };
        foreach ($rooms as $room) {
            $check($room['statusID'], 0, $room['name']);
            if ($this->id('LegacyLastHeatedGroupID') > 0 && $room['legacyGroupID'] > 0
                && (!IPS_ObjectExists($room['legacyGroupID']) || IPS_GetObject($room['legacyGroupID'])['ObjectType'] !== 0)) {
                throw new \RuntimeException('Invalid original room group category: ' . $room['legacyGroupID']);
            }
        }
        $check($this->id('LegacyResidualHeatingID'), 0, 'Residual heating');
        $check($this->id('LegacyLastHeatedGroupID'), 1, 'Last heated group');
        $check($this->id('LegacyActionLogID'), 3, 'Heating action log');
        $check($this->id('LegacyConfigSnapshotID'), 3, 'Configuration snapshot');
    }

    private function mayWriteLegacyStatus(): bool
    {
        return $this->ReadPropertyBoolean('Enabled') && !$this->ReadPropertyBoolean('DryRun');
    }

    private function mirrorLegacyStatus(array $rooms, array $demand, bool $residual, ?string $lastRoom, string $decision, array $log): void
    {
        if (!$this->mayWriteLegacyStatus()) return;
        foreach ($rooms as $room) {
            if ($room['statusID'] > 0) $this->writeLegacyValue($room['statusID'], (bool)($demand[$room['name']] ?? false));
            if ($lastRoom === $room['name'] && $room['legacyGroupID'] > 0 && $this->id('LegacyLastHeatedGroupID') > 0) {
                $this->writeLegacyValue($this->id('LegacyLastHeatedGroupID'), $room['legacyGroupID']);
            }
        }
        if ($this->id('LegacyResidualHeatingID') > 0) $this->writeLegacyValue($this->id('LegacyResidualHeatingID'), $residual);
        if ($this->id('LegacyActionLogID') > 0) {
            // Preserve the HTML list used by existing IPSView displays.
            $lines = array_merge(['Timestamp: ' . date('d.m.Y H:i:s'), $decision], $log);
            $html = '<ul><li>' . implode('</li><li>', array_map(
                static fn(string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $lines)) . '</li></ul>';
            $this->writeLegacyValue($this->id('LegacyActionLogID'), $html);
        }
    }

    private function writeLegacyValue(int $id, $value): void
    {
        if (GetValue($id) === $value) return;
        // Status variables may have custom actions. Set their values directly;
        // never execute an action script or a device command for a status update.
        if (is_bool($value)) SetValueBoolean($id, $value);
        elseif (is_int($value)) SetValueInteger($id, $value);
        else SetValueString($id, $value);
        $this->debug('STATUS ' . $id . ' = ' . json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    private function temperature(int $id, bool $checkAge = true): float
    {
        if (!IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] !== 2) {
            throw new \RuntimeException('Invalid temperature variable ' . $id);
        }
        $maxAge = $this->ReadPropertyInteger('MaxSensorAgeSeconds');
        if ($checkAge && $maxAge > 0 && time() - IPS_GetVariable($id)['VariableUpdated'] > $maxAge) {
            throw new \RuntimeException('Stale temperature variable ' . $id);
        }
        $value = GetValueFloat($id);
        if (!is_finite($value)) throw new \RuntimeException('Nonfinite temperature ' . $id);
        return $value;
    }

    private function id(string $property): int {
        $id = $this->ReadPropertyInteger($property);
        return (array_key_exists($property, self::DEFAULT_STATUS_IDS) || $property === 'RecoveryEnableID') && $id === 1 ? 0 : $id;
    }
    private function recoveryEnabled(): bool
    {
        $id = $this->id('RecoveryEnableID');
        if ($id === 0) return true;
        if ($id < 0 || !IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] !== 0) {
            throw new \RuntimeException('Residual heat recovery switch must be a Boolean variable: ' . $id);
        }
        foreach (['FanOnID', 'HeatPumpOnID', 'HeatPumpHeatModeID', 'GasPumpID'] as $property) {
            if ($id === $this->id($property)) throw new \RuntimeException('Recovery switch must not also be an actuator command: ' . $id);
        }
        return GetValueBoolean($id);
    }
    private function boolValue(string $property): bool { return GetValueBoolean($this->id($property)); }
    private function intValue(string $property): int { return GetValueInteger($this->id($property)); }
    private function floatValue(string $property): float {
        $setpoint = in_array($property, ['HysteresisID', 'ResidualDeltaID', 'GasFlowHeatingID', 'GasFlowIdleID'], true);
        return $this->temperature($this->id($property), !$setpoint);
    }
    private function clamp(int $n): int { return max(0, min(100, $n)); }

    private function debug(string $message): void
    {
        if (!$this->ReadPropertyBoolean('DebugEnabled')) return;
        $old = $this->GetValue('DebugLog');
        $line = date('Y-m-d H:i:s') . ' ' . $message . "\n";
        $this->SetValue('DebugLog', substr($old . $line, -32000));
    }

    private function replaceWatchers(): void
    {
        $previous = json_decode($this->ReadAttributeString('WatchIDs'), true);
        if (is_array($previous)) foreach ($previous as $id) {
            if (is_int($id) && $id > 0) $this->UnregisterMessage($id, VM_UPDATE);
        }
        if (!$this->ReadPropertyBoolean('Enabled') || IPS_GetKernelRunlevel() !== KR_READY) {
            $this->WriteAttributeString('WatchIDs', '[]');
            if (IPS_GetKernelRunlevel() !== KR_READY) $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        $ids = [];
        foreach (self::DEFAULT_IDS as $property => $_) {
            // Include modes, conditions, thresholds and source temperatures;
            // output variables and outside/at-home sensors do not drive decisions.
            if (in_array($property, ['OutsideTempID', 'AtHomeID', 'FanOnID', 'FanSpeedID',
                'HeatPumpOnID', 'HeatPumpPowerID', 'HeatPumpHeatModeID',
                'GasMixerID', 'GasPumpID', 'GasFlowTargetID'], true)) continue;
            $id = $this->id($property);
            if ($id > 0 && IPS_VariableExists($id)) $ids[$id] = true;
        }
        $recoveryID = $this->id('RecoveryEnableID');
        if ($recoveryID > 0 && IPS_VariableExists($recoveryID)) $ids[$recoveryID] = true;
        try {
            $rooms = $this->decodeRoomRows($this->ReadPropertyString('Rooms'));
        } catch (\Throwable $e) {
            $rooms = []; // Evaluation reports invalid configuration before sending any commands.
        }
        foreach ($rooms as $room) {
            foreach (array_merge($room['sensors'], [$room['targetID']]) as $id) {
                if ($id > 0 && IPS_VariableExists($id)) $ids[$id] = true;
            }
        }
        foreach (array_keys($ids) as $id) $this->RegisterMessage($id, VM_UPDATE);
        $this->WriteAttributeString('WatchIDs', json_encode(array_keys($ids)));
    }

    public function ExportConfig(): string
    {
        $config = json_decode(IPS_GetConfiguration($this->InstanceID), true, 512, JSON_THROW_ON_ERROR);
        // Keep backups compatible with the original room schema and Boolean commands.
        $config['Rooms'] = json_encode($this->rooms(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $json = json_encode([
            'schema' => 'HeatingControl.Config.v1',
            'exportedAt' => date(DATE_ATOM),
            'sourceInstanceID' => $this->InstanceID,
            'config' => $config,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->UpdateFormField('BackupJson', 'value', $json);
        if ($this->mayWriteLegacyStatus() && $this->id('LegacyConfigSnapshotID') > 0) {
            $this->validateLegacyOutputs($this->rooms());
            $this->writeLegacyValue($this->id('LegacyConfigSnapshotID'), $json);
        }
        return $json;
    }

    public function ImportConfig(string $json): string
    {
        $backup = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($backup) || ($backup['schema'] ?? '') !== 'HeatingControl.Config.v1' || !is_array($backup['config'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported heating configuration backup');
        }
        $allowed = array_merge(array_keys(self::DEFAULT_IDS), array_keys(self::DEFAULT_STATUS_IDS),
            ['Enabled', 'DryRun', 'DebugEnabled', 'WebEnabled', 'VaultInstanceID', 'Rooms', 'RecoveryEnableID', 'IntervalSeconds', 'MaxSensorAgeSeconds', 'ResidualMinOutgoingTemp',
             'MixerOpen', 'MixerClosed', 'CoolingModeValue', 'HeatPumpModeValue', 'GasModeValue']);
        $incoming = $backup['config'];
        if (array_diff(array_keys($incoming), $allowed)) throw new \InvalidArgumentException('Unknown backup property');
        foreach (['DryRun', 'DebugEnabled', 'WebEnabled'] as $name) {
            if (isset($incoming[$name]) && !is_bool($incoming[$name])) {
                throw new \InvalidArgumentException('Expected Boolean ' . $name);
            }
        }
        foreach (array_merge(self::DEFAULT_IDS, self::DEFAULT_STATUS_IDS, ['RecoveryEnableID' => 0, 'VaultInstanceID' => 0]) as $name => $_) if (isset($incoming[$name]) && !is_int($incoming[$name])) {
            throw new \InvalidArgumentException('Expected integer ID: ' . $name);
        }
        foreach (['IntervalSeconds', 'MaxSensorAgeSeconds', 'MixerOpen', 'MixerClosed',
                  'CoolingModeValue', 'HeatPumpModeValue', 'GasModeValue'] as $name) {
            if (isset($incoming[$name]) && !is_int($incoming[$name])) throw new \InvalidArgumentException('Expected integer: ' . $name);
        }
        if (isset($incoming['ResidualMinOutgoingTemp']) && !is_numeric($incoming['ResidualMinOutgoingTemp'])) {
            throw new \InvalidArgumentException('Invalid residual minimum');
        }
        if (isset($incoming['Rooms'])) {
            if (!is_string($incoming['Rooms'])) throw new \InvalidArgumentException('Invalid Rooms property');
            $rooms = $this->decodeRoomRows($incoming['Rooms']);
            if (!$rooms) throw new \InvalidArgumentException('Configure at least one room');
            $incoming['Rooms'] = json_encode($rooms, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        // Importing never starts a second controller automatically.
        $incoming['Enabled'] = false;
        $incoming['DryRun'] = true;
        $incoming['WebEnabled'] = false; // Restores require an explicit web-access review.
        $current = json_decode(IPS_GetConfiguration($this->InstanceID), true, 512, JSON_THROW_ON_ERROR);
        IPS_SetConfiguration($this->InstanceID, json_encode(array_replace($current, $incoming), JSON_THROW_ON_ERROR));
        IPS_ApplyChanges($this->InstanceID);
        return 'Configuration imported. Controller is disabled; verify IDs before enabling.';
    }
}

