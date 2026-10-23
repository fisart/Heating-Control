<?php
declare(strict_types=1);

trait HeatingControlSourceSelection
{
    private const PRICE_MODULE_ID = '{5C0EEDE9-5C09-4F18-BCD1-CE65CFA7B1B7}';

    private function createSourceSelection(): void
    {
        $this->RegisterPropertyInteger('ElectricityPriceInstanceID', 0);
        // Retired compatibility properties: saved values/imports have no runtime effect.
        $this->RegisterPropertyInteger('SourceTransferDelaySeconds', 0);
        $this->RegisterPropertyInteger('HeatPumpMinimumOffSeconds', 0);
        $this->RegisterTimer('SourceCommandCheck', 0, 'HC_ProcessHeating($_IPS["TARGET"]);');
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
        if (!$this->GetValue('AutomaticSourceSelection') && $this->ReadAttributeString('AutomaticWasActive') !== '1' && $this->ReadAttributeString('SourceTransfer') === '') {
            $this->SetTimerInterval('SourceCommandCheck', 0);
            $this->SetValue('SourceTransferStatus', 'Manual control; no timed delay');
            return true;
        }
        $hp = $this->boolValue('HeatPumpOnID');
        $gas = $this->boolValue('GasPumpID');
        $target = $mode === $this->ReadPropertyInteger('HeatPumpModeValue') ? 1 : 2;
        $mixerClosed = GetValueInteger($this->id('GasMixerID')) === $this->ReadPropertyInteger('MixerClosed');
        $opposite = $target === 1 ? $gas : $hp;
        $handover = $opposite || $this->ReadAttributeString('SourceTransfer') !== '' || ($target === 1 && !$mixerClosed);
        if ($handover && !(!$hp && !$gas && $mixerClosed)) {
            $this->WriteAttributeString('SourceTransfer', json_encode(['target'=>$target], JSON_THROW_ON_ERROR));
            $this->queueHeatOff($queue, false);
            $this->SetValue('SourceTransferStatus', 'Awaiting both sources OFF and mixer CLOSED command acknowledgement before '.($target === 1 ? 'heat pump' : 'gas').'; no timed delay');
            // Recheck asynchronous commands promptly; this is not a protection countdown.
            $this->SetTimerInterval('SourceCommandCheck', 1000);
            return false;
        }
        $this->WriteAttributeString('SourceTransfer', '');
        $this->SetTimerInterval('SourceCommandCheck', 0);
        if (!$this->GetValue('AutomaticSourceSelection')) $this->WriteAttributeString('AutomaticWasActive', '0');
        $this->SetValue('SourceTransferStatus', 'Ready: '.($target === 1 ? 'heat pump' : 'gas').'; no timed delay');
        return true;
    }

    private function sourceIdle(string $reason): void
    {
        $this->SetTimerInterval('SourceCommandCheck', 0);
        $hp = $this->boolValue('HeatPumpOnID');
        $gas = $this->boolValue('GasPumpID');
        if ($hp || $gas) $this->WriteAttributeString('SourceTransfer', '{"target":0}');
        elseif (GetValueInteger($this->id('GasMixerID')) === $this->ReadPropertyInteger('MixerClosed')) {
            $this->WriteAttributeString('SourceTransfer', '');
        }
        $this->SetValue('EffectiveSource', 0); $this->SetValue('SelectedHeatPumpPower', 0);
        $this->SetValue('SourceTransferStatus', $reason);
        $this->SetValue('SourceSelectionStatus', $reason);
    }
}


