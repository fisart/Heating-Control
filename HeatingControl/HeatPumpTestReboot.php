<?php
declare(strict_types=1);

/** Only a deliberately stopped, configuration-matching test may cross a reboot. */
trait HeatingControlHeatPumpTestReboot
{
    private const TEST_REBOOT_LEAD = 300;
    private const TEST_REBOOT_SETTLE = 300;
    private const TEST_REBOOT_MAX_WAIT = 7200;

    private function testRebootTime(string $raw): array
    {
        $t=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($t)||array_diff(array_keys($t),['hour','minute','second']))throw new InvalidArgumentException('Select a valid daily Symcon reboot time');
        foreach(['hour'=>23,'minute'=>59,'second'=>59] as $key=>$maximum)
            if(!isset($t[$key])||!is_int($t[$key])||$t[$key]<0||$t[$key]>$maximum)throw new InvalidArgumentException('Select a valid daily Symcon reboot time');
        return ['hour'=>$t['hour'],'minute'=>$t['minute'],'second'=>$t['second']];
    }

    private function testConfigurationHash(): string
    {
        $config=json_decode(IPS_GetConfiguration($this->InstanceID),true,512,JSON_THROW_ON_ERROR);
        ksort($config);
        return hash('sha256',json_encode($config,JSON_THROW_ON_ERROR));
    }

    /** Calendar days in Symcon's local timezone, including DST, never UTC + 86400. */
    private function testNextReboot(array $settings,int $now,bool $allowLate=true): int
    {
        $t=$this->testRebootTime($settings['HeatPumpTestRebootTime']);
        $local=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone($settings['rebootTimezone']));
        $today=$local->setTime($t['hour'],$t['minute'],$t['second']);
        $at=$today->getTimestamp();
        // A test started just after the configured time also waits if this boot
        // predates the scheduled reboot; never start heating during a late backup.
        if($at<$now&&(!$allowLate||$now>$at+self::TEST_REBOOT_MAX_WAIT||IPS_GetKernelStartTime()>=$at))
            $at=$today->modify('+1 day')->setTime($t['hour'],$t['minute'],$t['second'])->getTimestamp();
        return $at;
    }

    public function SetHeatPumpTestReboot(bool $enabled,string $time): void
    {
        if($this->testState())throw new InvalidArgumentException('Cancel the running or paused test before changing its reboot settings');
        if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new InvalidArgumentException('Reboot time must use HH:MM');
        [$hour,$minute]=array_map('intval',explode(':',$time));
        $raw=json_encode(['hour'=>$hour,'minute'=>$minute,'second'=>0],JSON_THROW_ON_ERROR);
        IPS_SetProperty($this->InstanceID,'HeatPumpTestRebootGuardEnabled',$enabled);
        IPS_SetProperty($this->InstanceID,'HeatPumpTestRebootTime',$raw);
        IPS_ApplyChanges($this->InstanceID);
        $this->UpdateFormField('HeatPumpTestRebootGuardEnabled','value',$enabled);
        $this->UpdateFormField('HeatPumpTestRebootTime','value',$raw);
    }

    private function testRebootPaused(array $s): bool
    {
        return in_array($s['phase']??'',['reboot_pausing','reboot_wait'],true);
    }

    private function testNeedsRebootPause(array $s,int $now): bool
    {
        if(!($s['settings']['HeatPumpTestRebootGuardEnabled']??false)||empty($s['nextRebootAt']))return false;
        $elapsed=$s['phase']==='step'?($s['cleanMeasurementSeconds']??max(0,$now-($s['phaseSince']??$now))):max(0,$now-($s['phaseSince']??$now));
        $recovery=0;
        if($s['phase']==='step'&&HeatPumpTransient::active($s['stepTransient']??[])) {
            $event=end($s['stepTransient']['events']);$policy=HeatPumpTransient::policy();
            $recovery=$event['releaseAt']!==null?max(0,$event['releaseAt']-$now):max(0,$policy['maximumRecoverySeconds']-($now-$event['detectedAt']))+$policy['postSeconds'];
        }
        $duration=match($s['phase']) {
            'step'=>max(0,$s['settings']['HeatPumpTestStepMinutes']*60-$elapsed)+$recovery,
            'baseline'=>max(0,$this->testBaselineSeconds($s)-$elapsed),
            default=>$this->testBaselineSeconds($s),
        };
        // Leave archive-boundary and command-confirmation time before the lead.
        return $now+$duration+90 >= $s['nextRebootAt']-self::TEST_REBOOT_LEAD;
    }

    private function beginTestRebootPause(array &$s): void
    {
        $s['rebootPause']=['scheduledAt'=>$s['nextRebootAt'],'pausedAt'=>$this->sourceClock(),
            'kernelStartedAt'=>IPS_GetKernelStartTime(),'interruptedPhase'=>$s['phase'],
            'repeatPowerPercent'=>$s['powers'][$s['index']]??0,'stopConfirmed'=>false,'discardedTransient'=>$s['stepTransient']??null];
        $s['phase']='reboot_pausing';
        // Raw telemetry remains in the append-only readings. A partial window
        // cannot be joined to post-reboot samples or become a completed point.
        $s['window']=[];$s['fullWindow']=[];$s['readySince']=0;
        unset($s['stepTransient'],$s['stepExtensionAllocated'],$s['cleanMeasurementSeconds']);
        $this->saveTestState($s);
        $this->testWrite($s,['kind'=>'reboot_pause','pause'=>$s['rebootPause']]);
    }

    /** Safe before KR_READY: preserve state, but send no hardware commands. */
    private function recoverHeatPumpTest(): void
    {
        $lock='HeatingControl.'.$this->InstanceID;
        if(!IPS_SemaphoreEnter($lock,1000))return;
        try {
            $s=$this->testState();if(!$s)return;
            $planned=$this->testRebootPaused($s)&&isset($s['rebootPause'],$s['configurationHash'])
                &&($s['settings']['HeatPumpTestRebootGuardEnabled']??false)
                &&hash_equals($s['configurationHash'],$this->testConfigurationHash())
                &&$this->ReadPropertyBoolean('Enabled')&&!$this->ReadPropertyBoolean('DryRun');
            if(!$planned)$this->beginTestFinish($s,'Configuration changed or Symcon restarted',false);
            $this->SetValue('HeatPumpTestActive',true);
            $this->SetTimerInterval('HeatPumpTestTimer',IPS_GetKernelRunlevel()===KR_READY?10000:0);
            if(IPS_GetKernelRunlevel()!==KR_READY)return;
            if(!$planned)$this->finishTestTick($s);
            else $this->SetValue('HeatPumpTestStatus','Scheduled reboot pause restored; waiting for startup and safety checks');
        } finally {IPS_SemaphoreLeave($lock);}
    }

    private function processTestRebootPause(array &$s): void
    {
        $now=$this->sourceClock();$pause=&$s['rebootPause'];
        $this->captureTestMeterBoundaries($s);
        $this->SetValue('EffectiveSource',0);$this->SetValue('SelectedHeatPumpPower',0);$this->SetValue('LearningSourceMode',0);
        $this->SetValue('SourceSelectionStatus','Heat-pump test paused for scheduled Symcon reboot; sources held off');
        $this->testStopCommands($s);
        $off=[$s['ids']['HeatPumpOnID']=>false,$s['ids']['HeatPumpPowerID']=>0,
            $s['ids']['GasPumpID']=>false,$s['ids']['GasMixerID']=>$s['mixerClosed']];
        foreach($off as $id=>$value)if(!IPS_VariableExists($id)||GetValue($id)!==$value) {
            if($now>=$pause['scheduledAt'])throw new RuntimeException('Scheduled reboot pause could not confirm heat-source stop');
            $this->SetValue('HeatPumpTestStatus','Pausing for reboot: waiting for heat-source stop confirmation');
            $s['lastTick']=$now;$this->saveTestState($s);return;
        }
        $restore=[$s['ids']['FanOnID']=>false,$s['ids']['FanSpeedID']=>0];
        foreach($s['originalFlaps'] as $id=>$value)$restore[(int)$id]=$value;
        if(!$this->testOwnedCommands($restore))throw new RuntimeException('Scheduled reboot pause could not restore fan/flaps');
        foreach($restore as $id=>$value)if(!IPS_VariableExists($id)||GetValue($id)!==$value) {
            if($now>=$pause['scheduledAt'])throw new RuntimeException('Scheduled reboot pause could not confirm fan/flap restoration');
            $this->SetValue('HeatPumpTestStatus','Pausing for reboot: waiting for fan/flap restoration confirmation');
            $s['lastTick']=$now;$this->saveTestState($s);return;
        }
        $boot=IPS_GetKernelStartTime();
        if(!$pause['stopConfirmed']) {
            // No automatic restart if Symcon went down before the planned stop
            // was confirmed and persisted.
            if($boot!==$pause['kernelStartedAt'])throw new RuntimeException('Symcon restarted before the planned test stop was confirmed');
            $pause['stopConfirmed']=true;$pause['stopConfirmedAt']=$now;$s['phase']='reboot_wait';
            $this->testWrite($s,['kind'=>'reboot_stop_confirmed','timestamp'=>$now]);
        }
        // Finalize completed steps while their counter boundaries are still
        // local to this segment, independent of the upcoming logging gap.
        if($s['points']&&$now>=end($s['points'])['stepEndedAt']+75)$this->finalizeTestCounterPoints($s);
        if($now>$pause['scheduledAt']+self::TEST_REBOOT_MAX_WAIT)throw new RuntimeException('Scheduled reboot recovery timed out after two hours');
        $newBoot=$boot!==$pause['kernelStartedAt']&&$boot>=$pause['scheduledAt']-self::TEST_REBOOT_LEAD&&$boot>=$pause['pausedAt'];
        if($newBoot&&($pause['readyKernelStartedAt']??0)!==$boot) {
            $pause['readyKernelStartedAt']=$boot;$pause['readyObservedAt']=$now;
        }
        if($newBoot&&$now>=max($pause['readyObservedAt'],$pause['scheduledAt'])+self::TEST_REBOOT_SETTLE) {
            $this->validateConfiguration($s['rooms']);
            $probe=$s;unset($probe['lastMeterSample'],$probe['meterSegmentExportStart']);
            $this->testSample($probe); // Recheck limits; counter continuity restarts in the new segment.
            $this->testMeterFresh($s);
            $pause['resumedAt']=$now;$pause['resumedKernelStartedAt']=$boot;
            $s['rebootHistory'][]=$pause;
            $this->testWrite($s,['kind'=>'reboot_resume','resume'=>$pause]);
            $s['phase']='prepare';$s['phaseSince']=$now;$s['readySince']=0;$s['lastTick']=$now;
            $s['window']=[];$s['fullWindow']=[];
            unset($s['counterFinalized'],$s['rebootPause'],$s['lastMeterSample'],$s['meterSegmentExportStart']);
            $s['nextRebootAt']=$this->testNextReboot($s['settings'],$now+1,false);
            $s['deadline']=$now+(count($s['powers'])-$s['index'])*$s['settings']['HeatPumpTestStepMinutes']*60+2400;
            $this->saveTestState($s);
            $this->SetValue('HeatPumpTestStatus','Symcon restarted; preparing a fresh '.($this->testDedicatedMeter($s)?'two-minute meter check':'15-minute baseline').', then repeating '.$s['powers'][$s['index']].'%');
            return;
        }
        $s['lastTick']=$now;$this->saveTestState($s);
        $label=$newBoot?'Symcon is back; waiting five minutes for startup to settle':'Waiting for the scheduled Symcon reboot';
        $this->SetValue('HeatPumpTestStatus',$label.' at '.date('H:i',$pause['scheduledAt']).'; next step '.$pause['repeatPowerPercent'].'%; sources off');
    }
}

