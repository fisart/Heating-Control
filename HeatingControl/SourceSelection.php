<?php
declare(strict_types=1);

trait HeatingControlSourceSelection
{
    private const PRICE_MODULE_ID = '{5C0EEDE9-5C09-4F18-BCD1-CE65CFA7B1B7}';

    private function createSourceSelection(): void
    {
        $this->RegisterPropertyInteger('ElectricityPriceInstanceID', 0);
        $this->RegisterPropertyInteger('SourceTransferDelaySeconds', 120);
        $this->RegisterPropertyInteger('HeatPumpMinimumOffSeconds', 300);
        $this->RegisterAttributeString('SourceTransfer', '');
        $this->RegisterAttributeString('AutomaticWasActive', '0');
        $this->RegisterAttributeString('HeatPumpStoppedAt', '0');
        $this->RegisterVariableBoolean('AutomaticSourceSelection', 'Automatic heating source selection (LichtBlick)', '~Switch', 60);
        $this->EnableAction('AutomaticSourceSelection');
        $this->RegisterVariableInteger('EffectiveSource', 'Selected air-heating source (0 off, 1 heat pump, 2 gas)', '', 70);
        $this->RegisterVariableInteger('LearningSourceMode', 'Air-source mode for archive learning (0 unavailable, 1 heat pump, 2 gas)', '', 75);
        $this->RegisterVariableInteger('SelectedHeatPumpPower', 'Selected heat-pump power (%)', '', 80);
        $this->RegisterVariableString('SourceSelectionStatus', 'Source selection status', '~TextBox', 90);
        $this->RegisterVariableString('SourceTransferStatus', 'Source transfer status', '~TextBox', 100);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'AutomaticSourceSelection' || !is_bool($Value)) {
            throw new InvalidArgumentException('AutomaticSourceSelection requires a Boolean');
        }
        $this->SetAutomaticSourceSelection($Value);
    }

    public function SetAutomaticSourceSelection(bool $enabled): void
    {
        $this->SetValue('AutomaticSourceSelection', $enabled);
        $this->debug('Automatic source selection ' . ($enabled ? 'enabled' : 'disabled: current source and manual desired power used'));
        $this->ProcessHeating();
    }

    protected function sourceClock(): int { return time(); }

    private function priceQuoteID(): int
    {
        $instance = $this->ReadPropertyInteger('ElectricityPriceInstanceID');
        if ($instance <= 1 || !IPS_InstanceExists($instance)
            || strtoupper(IPS_GetInstance($instance)['ModuleInfo']['ModuleID']) !== self::PRICE_MODULE_ID) return 0;
        try {
            $id = IPS_GetObjectIDByIdent('PriceQuoteJSON', $instance);
            if (!IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] !== 3) return 0;
            return $id;
        } catch (Throwable $e) { return 0; }
    }

    /** One final JSON publication avoids reading half-updated provider variables. */
    private function sourceChoice(int $manualMode, array &$queue): array
    {
        $automatic = (bool)$this->GetValue('AutomaticSourceSelection');
        $power = $this->clamp($this->intValue('HeatPumpDesiredPowerID'));
        $mode = $manualMode;
        $reason = 'Manual source and desired power';
        if ($automatic) {
            $this->WriteAttributeString('AutomaticWasActive', '1');
            $mode = $this->ReadPropertyInteger('GasModeValue'); $power = 0;
            try {
                $power=$this->measuredEconomicChoice($reason);
                $mode=$power>0?$this->ReadPropertyInteger('HeatPumpModeValue'):$this->ReadPropertyInteger('GasModeValue');
            } catch (Throwable $e) { $reason = 'Automatic gas fallback: ' . $e->getMessage(); }
            // Mirror the selected mode, including fallback and transfer/no-demand periods.
            // Dispatch respects DryRun and does not invoke a legacy mode action script.
            $this->queue($queue, $this->id('OperatingModeID'), $mode);
        }
        $source = $mode === $this->ReadPropertyInteger('HeatPumpModeValue') ? 1 : 2;
        $this->SetValue('EffectiveSource', $source);
        $this->SetValue('LearningSourceMode', $source);
        $this->SetValue('SelectedHeatPumpPower', $source === 1 ? $power : 0);
        $this->SetValue('SourceSelectionStatus', $reason);
        return [$mode, $power];
    }

    /** Break before make. These variables acknowledge commands, not compressor/valve feedback. */
    private function sourceReady(array &$queue, int $mode): bool
    {
        $auto = (bool)$this->GetValue('AutomaticSourceSelection');
        if (!$auto && $this->ReadAttributeString('AutomaticWasActive') !== '1'
            && $this->ReadAttributeString('SourceTransfer') === '') {
            $this->SetValue('SourceTransferStatus', 'Manual control'); return true;
        }
        $now = $this->sourceClock();
        $hp = $this->boolValue('HeatPumpOnID'); $gas = $this->boolValue('GasPumpID');
        $target = $mode === $this->ReadPropertyInteger('HeatPumpModeValue') ? 1 : 2;
        $stopped = (int)$this->ReadAttributeString('HeatPumpStoppedAt');
        if ($hp) { $stopped = 0; $this->WriteAttributeString('HeatPumpStoppedAt', '0'); }
        elseif ($stopped === 0 || $stopped > $now) {
            $stopped = $now; $this->WriteAttributeString('HeatPumpStoppedAt', (string)$now);
        }
        try {
            $pending = json_decode($this->ReadAttributeString('SourceTransfer') ?: '{}', true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($pending) || ($pending && (!in_array($pending['target'] ?? null, [1, 2], true)
                || !is_int($pending['since'] ?? null) || $pending['since'] < 0 || $pending['since'] > $now))) {
                throw new RuntimeException('Invalid transfer state');
            }
        } catch (Throwable $e) { $pending = ['target'=>$target, 'since'=>0]; }
        $opposite = $target === 1 ? $gas : $hp;
        if ($opposite || ($pending && ($pending['target'] ?? 0) !== $target)) {
            if (!$pending || ($pending['target'] ?? 0) !== $target) {
                $pending = ['target'=>$target, 'since'=>0];
                $this->WriteAttributeString('SourceTransfer', json_encode($pending));
            }
        }
        $acknowledged = !$hp && !$gas && GetValueInteger($this->id('GasMixerID')) === $this->ReadPropertyInteger('MixerClosed');
        if ($pending) {
            if (!$acknowledged) $pending['since'] = 0;
            elseif (($pending['since'] ?? 0) === 0) $pending['since'] = $now;
            $this->WriteAttributeString('SourceTransfer', json_encode($pending));
        }
        $delay = $pending ? max(0, ($pending['since'] ?: $now) + $this->ReadPropertyInteger('SourceTransferDelaySeconds') - $now) : 0;
        $offDelay = $target === 1 && !$hp ? max(0, $stopped + $this->ReadPropertyInteger('HeatPumpMinimumOffSeconds') - $now) : 0;
        if (($pending && !$acknowledged) || $delay > 0 || $offDelay > 0) {
            // Source interlocks govern the heat sources, not the shared fan.
            // evaluate() decides airflow from the selected exchanger and room demand.
            $this->queueHeatOff($queue, false);
            $this->SetValue('SourceTransferStatus', sprintf('Both air sources off before %s; transfer wait %ds, heat-pump off wait %ds; checking stop/mixer command states',
                $target === 1 ? 'heat pump' : 'gas', $delay, $offDelay));
            return false;
        }
        $this->WriteAttributeString('SourceTransfer', '');
        if (!$auto) $this->WriteAttributeString('AutomaticWasActive', '0');
        $this->SetValue('SourceTransferStatus', 'Ready: ' . ($target === 1 ? 'heat pump' : 'gas'));
        return true;
    }

    private function sourceIdle(string $reason): void
    {
        if ($this->boolValue('HeatPumpOnID')) $this->WriteAttributeString('HeatPumpStoppedAt', '0');
        elseif ((int)$this->ReadAttributeString('HeatPumpStoppedAt') === 0) $this->WriteAttributeString('HeatPumpStoppedAt', (string)$this->sourceClock());
        // A brief loss of room demand must not erase a transfer's stop/travel delay.
        if ($this->GetValue('AutomaticSourceSelection') || $this->ReadAttributeString('AutomaticWasActive') === '1') {
            $hp = $this->boolValue('HeatPumpOnID'); $gas = $this->boolValue('GasPumpID');
            if ($hp || $gas) {
                $this->WriteAttributeString('SourceTransfer', json_encode(['target'=>$hp ? 2 : 1, 'since'=>0]));
            } elseif ($this->ReadAttributeString('SourceTransfer') !== '') {
                try {
                    $pending = json_decode($this->ReadAttributeString('SourceTransfer'), true, 16, JSON_THROW_ON_ERROR);
                    if (is_array($pending) && in_array($pending['target'] ?? null, [1, 2], true)
                        && ($pending['since'] ?? null) === 0
                        && GetValueInteger($this->id('GasMixerID')) === $this->ReadPropertyInteger('MixerClosed')) {
                        $pending['since'] = $this->sourceClock();
                        $this->WriteAttributeString('SourceTransfer', json_encode($pending));
                    }
                } catch (Throwable $e) { /* sourceReady reestablishes a corrupt transfer conservatively. */ }
            }
        } else { $this->WriteAttributeString('SourceTransfer', ''); }
        $this->SetValue('EffectiveSource', 0); $this->SetValue('SelectedHeatPumpPower', 0);
        $this->SetValue('SourceTransferStatus', $reason);
        $this->SetValue('SourceSelectionStatus', $reason);
    }
}


