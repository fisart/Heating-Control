<?php
declare(strict_types=1);

trait HeatingControlFlapCalibration
{
    private function createFlapCalibration(): void
    {
        $this->RegisterPropertyInteger('FlapCalibrationWaitSeconds', 120);
        $this->RegisterAttributeString('FlapCalibrationState', '');
        $this->RegisterAttributeString('InterruptedHeatPumpTest', '');
        $this->RegisterVariableBoolean('FlapCalibrationActive', 'Air-flap calibration active', '', 160);
        $this->RegisterVariableString('FlapCalibrationStatus', 'Air-flap calibration status', '~TextBox', 170);
        $this->RegisterTimer('FlapCalibrationTimer', 0, 'HC_ProcessHeating($_IPS["TARGET"]);');
    }

    /** Releasing control never changes a linked variable, even during a test. */
    private function releaseControlOwnership(string $reason): void
    {
        $raw = $this->ReadAttributeString('HeatPumpTestState');
        if ($raw !== '') {
            $this->WriteAttributeString('InterruptedHeatPumpTest', $raw);
            try {
                $s = $this->testState();
                $this->testWrite($s, ['kind'=>'interrupted', 'at'=>$this->sourceClock(), 'reason'=>$reason, 'test'=>$s]);
            } catch (Throwable $e) { $this->debug('Interrupted test checkpoint: '.$e->getMessage()); }
            $this->WriteAttributeString('HeatPumpTestState', '');
            $this->SetValue('HeatPumpTestActive', false);
            $this->SetValue('HeatPumpTestStatus', $reason.'; test interrupted, readings retained; no output commands');
        }
        if ($this->ReadAttributeString('FlapCalibrationState') !== '') {
            $this->WriteAttributeString('FlapCalibrationState', '');
            $this->SetValue('FlapCalibrationActive', false);
            $this->SetValue('FlapCalibrationStatus', $reason.'; flap calibration interrupted; device states retained');
        }
        $this->SetTimerInterval('HeatPumpTestTimer', 0);
        $this->SetTimerInterval('FlapCalibrationTimer', 0);
        $this->clearRecovery();
        $this->SetValue('LearningSourceMode', 0);
    }

    public function SetModuleEnabled(bool $enabled): void
    {
        $lock = 'HeatingControl.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) throw new RuntimeException('Controller busy; retry.');
        try {
            if ($this->ReadPropertyBoolean('Enabled') === $enabled) return;
            IPS_SetProperty($this->InstanceID, 'Enabled', $enabled);
            if (!$enabled) {
                $this->releaseControlOwnership('Controller disabled');
                $this->SetTimerInterval('HeatingTimer', 0);
                $this->SetValue('DecisionStatus', 'Controller disabled: no linked variables controlled; device states retained');
            } else $this->resetAfterFlapCalibration();
        } finally { IPS_SemaphoreLeave($lock); }
        IPS_ApplyChanges($this->InstanceID);
        $this->UpdateFormField('Enabled', 'value', $enabled);
        if ($enabled) $this->ProcessHeating();
    }

    public function SetFlapCalibrationWaitSeconds(int $seconds): void
    {
        if ($seconds < 1 || $seconds > 3600) throw new InvalidArgumentException('Flap wait must be 1–3600 whole seconds');
        $lock = 'HeatingControl.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) throw new RuntimeException('Controller busy; retry.');
        try {
            if ($this->ReadAttributeString('FlapCalibrationState') !== '' || $this->testState()) {
                throw new InvalidArgumentException('Finish or cancel the active calibration/test before changing the wait');
            }
            IPS_SetProperty($this->InstanceID, 'FlapCalibrationWaitSeconds', $seconds);
        } finally { IPS_SemaphoreLeave($lock); }
        IPS_ApplyChanges($this->InstanceID);
        $this->UpdateFormField('FlapCalibrationWaitSeconds', 'value', $seconds);
    }

    public function StartFlapCalibration(): void
    {
        $lock = 'HeatingControl.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) throw new RuntimeException('Controller busy; retry.');
        try {
            if (!$this->ReadPropertyBoolean('Enabled') || $this->ReadPropertyBoolean('DryRun') || IPS_GetKernelRunlevel() !== KR_READY) {
                throw new InvalidArgumentException('Enable live Heating Control before calibrating flaps');
            }
            if ($this->boolValue('MasterDisableID')) throw new InvalidArgumentException('Master bypass is active');
            if ($this->testState() || $this->ReadAttributeString('FlapCalibrationState') !== '') throw new InvalidArgumentException('A calibration/test already owns the controls');
            $rooms = $this->rooms();
            $this->validateConfiguration($rooms);
            $wait = $this->ReadPropertyInteger('FlapCalibrationWaitSeconds');
            if ($wait < 1 || $wait > 3600) throw new RuntimeException('Flap wait must be 1–3600 whole seconds');
            $s = ['phase'=>-1, 'until'=>0, 'waitSeconds'=>$wait, 'startedAt'=>$this->sourceClock(), 'rooms'=>$rooms];
            $this->WriteAttributeString('FlapCalibrationState', json_encode($s, JSON_THROW_ON_ERROR));
            $this->SetValue('FlapCalibrationActive', true);
            $this->clearRecovery();
            $this->SetValue('LearningSourceMode', 0);
            $this->SetTimerInterval('FlapCalibrationTimer', 1000);
        } finally { IPS_SemaphoreLeave($lock); }
        $this->ProcessHeating();
    }

    public function CancelFlapCalibration(): void
    {
        $lock = 'HeatingControl.'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) throw new RuntimeException('Controller busy; retry.');
        try {
            if ($this->ReadAttributeString('FlapCalibrationState') === '') return;
            $this->finishFlapCalibration('Cancelled; normal heating control resumed');
        } finally { IPS_SemaphoreLeave($lock); }
        $this->ProcessHeating();
    }

    private function resetAfterFlapCalibration(): void
    {
        $this->WriteAttributeString('HeatPumpStoppedAt', '0');
        $this->WriteAttributeString('SourceTransfer', '{"target":0,"since":0}');
        $this->WriteAttributeString('AutomaticWasActive', '1');
        $this->WriteAttributeString('DemandLatch', '{}');
        $this->clearRecovery();
    }

    private function finishFlapCalibration(string $status): void
    {
        $this->WriteAttributeString('FlapCalibrationState', '');
        $this->SetValue('FlapCalibrationActive', false);
        $this->SetValue('FlapCalibrationStatus', $status);
        $this->SetTimerInterval('FlapCalibrationTimer', 0);
        $this->resetAfterFlapCalibration();
    }

    /** Owns all outputs until the final open dwell has elapsed. No blocking sleep. */
    private function processFlapCalibration(): bool
    {
        $raw = $this->ReadAttributeString('FlapCalibrationState');
        if ($raw === '') return false;
        if ($this->ReadPropertyBoolean('DryRun') || $this->boolValue('MasterDisableID')) {
            $this->finishFlapCalibration('Interrupted by dry run/master bypass; device states retained');
            return true;
        }
        try {
            $s = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $now = $this->sourceClock();
            $q = []; $log = [];
            $this->queueHeatOff($q);
            $this->queueFanOff($q);
            $this->dispatch($q, $log);
            $off = true;
            foreach ($q as $id=>$value) if (GetValue($id) !== $value) $off = false;
            if (!$off) {
                // A lost stop acknowledgement restarts the current dwell after recovery.
                $s['until'] = 0;
                $this->WriteAttributeString('FlapCalibrationState', json_encode($s, JSON_THROW_ON_ERROR));
                $this->SetValue('FlapCalibrationStatus', 'Waiting for heat sources, mixer and fan to acknowledge OFF; flap sequence paused');
                return true;
            }
            if ($s['until'] === 0 || $now >= $s['until']) {
                if ($s['until'] !== 0 || $s['phase'] === -1) $s['phase']++;
                if ($s['phase'] >= 3) {
                    $this->finishFlapCalibration('Completed: open → close → open; normal heating control resumed');
                    return false;
                }
                $flaps = [];
                foreach ($s['rooms'] as $room) $this->queue($flaps, (int)$room['flapID'], $s['phase'] === 1 ? $room['closed'] : $room['open']);
                // End-stop calibration must reissue the command even if its value already matches.
                $this->dispatch($flaps, $log, array_keys($flaps));
                $s['until'] = $now + $s['waitSeconds'];
                $this->WriteAttributeString('FlapCalibrationState', json_encode($s, JSON_THROW_ON_ERROR));
            }
            $label = $s['phase'] === 1 ? 'all flaps CLOSED' : 'all flaps OPEN';
            $status = sprintf('Step %d/3: %s; %ds remaining (wait %ds); heat sources and fan OFF', $s['phase']+1, $label, max(0, $s['until']-$now), $s['waitSeconds']);
            $this->SetValue('FlapCalibrationStatus', $status);
            $this->SetValue('DecisionStatus', 'Air-flap calibration: '.$status);
            $this->SetValue('LearningSourceMode', 0);
        } catch (Throwable $e) {
            // Retain exclusive ownership until cancel/disable; never restart heating on an output fault.
            $this->SetValue('FlapCalibrationStatus', 'Calibration paused: '.$e->getMessage().'; cancel or disable to release control');
        }
        return true;
    }

    private function flapCalibrationView(): array
    {
        $raw = $this->ReadAttributeString('FlapCalibrationState');
        $s = $raw === '' ? [] : json_decode($raw, true);
        return ['active'=>$raw !== '', 'status'=>$this->GetValue('FlapCalibrationStatus'),
            'waitSeconds'=>$this->ReadPropertyInteger('FlapCalibrationWaitSeconds'),
            'step'=>isset($s['phase']) ? max(0, $s['phase']+1) : 0,
            'remainingSeconds'=>max(0, (int)($s['until']??0)-$this->sourceClock())];
    }
}
