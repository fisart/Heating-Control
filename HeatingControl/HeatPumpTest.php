<?php
declare(strict_types=1);
require_once __DIR__.'/HeatPumpPerformance.php';
require_once __DIR__.'/HeatPumpTestReboot.php';
require_once __DIR__.'/HeatPumpMeter.php';
require_once __DIR__.'/HeatPumpTransient.php';

trait HeatingControlHeatPumpTest
{
    use HeatingControlHeatPumpTestReboot;
    use HeatingControlHeatPumpMeter;

    private const TEST_DEFAULTS = [
        'HeatPumpTestStepMinutes'=>15,'HeatPumpTestTransientGuardEnabled'=>true,'HeatPumpTestFanSpeed'=>90,'HeatPumpTestExcludedFlapID'=>40259,'HeatPumpTestArchiveID'=>14817,
        'HeatPumpTestRebootGuardEnabled'=>true,'HeatPumpTestRebootTime'=>'{"hour":3,"minute":0,"second":0}',
        'HeatPumpTestPowerMeterID'=>35702,'HeatPumpTestFanMeterID'=>47831, // Inert legacy properties.
        'HeatPumpTestMeterSource'=>'dedicated','HeatPumpTestDedicatedPowerID'=>28681,'HeatPumpTestDedicatedCounterID'=>22365,
        'HeatPumpTestDedicatedExportID'=>57639,'HeatPumpTestDedicatedOnlineID'=>37456,'HeatPumpTestDedicatedCounterScale'=>1.0,
        'HeatPumpTestHousePowerID'=>13869,'HeatPumpTestHouseCounterID'=>38023,'HeatPumpTestHouseCounterScale'=>0.001,
        'HeatPumpTestMaximumRoomC'=>28.0,'HeatPumpTestMaximumCoilC'=>55.0,'HeatPumpTestAirflowM3h'=>2000.0,
        'HeatPumpOccupiedMinimumCoilC'=>40.0,'HeatPumpHolidayMinimumCoilC'=>20.0,
        'HeatPumpHolidayCoilAboveTargetC'=>10.0,'HeatPumpSupplyAboveTargetC'=>5.0,
        'HeatPumpMinimumAirRiseC'=>5.0,'HeatPumpUseTestModel'=>true,
    ];

    private function createHeatPumpTest(): void
    {
        foreach(self::TEST_DEFAULTS as $n=>$v) {
            if(is_bool($v))$this->RegisterPropertyBoolean($n,$v);
            elseif(is_int($v))$this->RegisterPropertyInteger($n,$v);
            elseif(is_string($v))$this->RegisterPropertyString($n,$v);
            else $this->RegisterPropertyFloat($n,$v);
        }
        $this->RegisterAttributeString('HeatPumpTestState','');
        $this->RegisterAttributeString('HeatPumpTestLastReport','');
        $this->RegisterAttributeString('HeatPumpPerformanceModel','');
        $this->RegisterAttributeString('HeatPumpComfortSince','0');
        $this->RegisterVariableBoolean('HeatPumpTestActive','Heat-pump test active','',110);
        $this->RegisterVariableString('HeatPumpTestStatus','Heat-pump test status','~TextBox',120);
        $this->RegisterVariableString('HeatPumpTestResults','Latest heat-pump test results (JSON)','~TextBox',130);
        $this->RegisterVariableString('HeatPumpModelJSON','Heat-pump measured relationships (JSON)','~TextBox',140);
        $this->RegisterVariableString('HeatPumpPerformanceStatus','Heat-pump comfort / model status','~TextBox',150);
        $this->RegisterTimer('HeatPumpTestTimer',0,'HC_HeatPumpTestTick($_IPS["TARGET"]);');
    }

    private function testState(): array
    {
        $raw=$this->ReadAttributeString('HeatPumpTestState');
        if($raw==='')return [];
        $s=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($s)||($s['schema']??null)!==1)throw new RuntimeException('Invalid persistent test state');
        return $s;
    }
    private function saveTestState(array $s): void {$this->WriteAttributeString('HeatPumpTestState',json_encode($s,JSON_THROW_ON_ERROR));}
    private function testDirectory(): string {return rtrim(IPS_GetKernelDir(),'/\\').'/media/heatpump-tests/'.$this->InstanceID;}
    private function testFile(array $s,string $suffix): string
    {
        if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/D',$s['runID']??''))throw new RuntimeException('Invalid test file identity');
        return $this->testDirectory().'/'.$s['runID'].$suffix;
    }
    private function testWrite(array $s,array $row): void
    {
        $line=json_encode($row,JSON_THROW_ON_ERROR)."\n";
        if(file_put_contents($this->testFile($s,'.jsonl'),$line,FILE_APPEND|LOCK_EX)!==strlen($line))throw new RuntimeException('Cannot save heat-pump test readings');
    }
    private function testSaveJSON(string $file,string $json): void
    {
        $temporary=$file.'.tmp';
        if(file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)||!rename($temporary,$file))throw new RuntimeException('Cannot persist test JSON atomically');
    }
    private function testValue(int $id,bool $temperature=false): float
    {
        if(!IPS_VariableExists($id) || !in_array(IPS_GetVariable($id)['VariableType'],[1,2],true))throw new RuntimeException('Numeric test sensor missing: '.$id);
        $v=GetValue($id);if(!is_numeric($v)||!is_finite((float)$v))throw new RuntimeException('Invalid test sensor: '.$id);
        if($temperature && ($v < -40 || $v > 100))throw new RuntimeException('Implausible temperature: '.$id);
        return (float)$v;
    }
    private function testSettings(int $minutes): array
    {
        $s=[];foreach(self::TEST_DEFAULTS as $n=>$v)$s[$n]=is_bool($v)?$this->ReadPropertyBoolean($n):(is_int($v)?$this->ReadPropertyInteger($n):(is_string($v)?$this->ReadPropertyString($n):$this->ReadPropertyFloat($n)));
        $this->testRebootTime($s['HeatPumpTestRebootTime']);
        $s['rebootTimezone']=date_default_timezone_get();
        $s['HeatPumpTestStepMinutes']=$minutes;
        if(!in_array($s['HeatPumpTestMeterSource'],['dedicated','house'],true))throw new InvalidArgumentException('Select dedicated or house test metering');
        if($s['HeatPumpTestDedicatedCounterScale']<=0||$s['HeatPumpTestDedicatedCounterScale']>1)throw new InvalidArgumentException('Invalid dedicated-counter kWh/unit');
        // Change-triggered temperature inputs may legitimately hold their value.
        $s['MaxSensorAgeSeconds']=max(0,$this->ReadPropertyInteger('MaxSensorAgeSeconds'));
        if($minutes<3||$minutes>60 || $s['HeatPumpTestFanSpeed']<20||$s['HeatPumpTestFanSpeed']>100
            || $s['HeatPumpTestAirflowM3h']<100||$s['HeatPumpTestAirflowM3h']>10000
            || $s['HeatPumpTestMaximumRoomC']<15||$s['HeatPumpTestMaximumRoomC']>35
            || $s['HeatPumpTestMaximumCoilC']<45||$s['HeatPumpTestMaximumCoilC']>65)throw new InvalidArgumentException('Invalid test duration, fan, airflow or temperature limits');
        return $s;
    }
    public function SetHeatPumpTestMinutes(int $minutes): void
    {
        if($this->testState())throw new RuntimeException('Cancel the running test before changing its duration');
        $this->testSettings($minutes);
        IPS_SetProperty($this->InstanceID,'HeatPumpTestStepMinutes',$minutes);
        IPS_ApplyChanges($this->InstanceID);
        $this->UpdateFormField('HeatPumpTestStepMinutes','value',$minutes);
    }
    public function StartHeatPumpTest(int $minutes=0): void
    {
        $lock='HeatingControl.'.$this->InstanceID;
        if(!IPS_SemaphoreEnter($lock,1000))throw new RuntimeException('Heating controller busy');
        try {
            if($this->ReadAttributeString('FlapCalibrationState')!=='')throw new RuntimeException('Finish or cancel air-flap calibration first');
            if($this->testState())throw new RuntimeException('A heat-pump test is already running or stopping');
            if(!$this->ReadPropertyBoolean('Enabled')||$this->ReadPropertyBoolean('DryRun')||IPS_GetKernelRunlevel()!==KR_READY)throw new RuntimeException('Enable live Heating Control before starting a test');
            if($this->boolValue('MasterDisableID')||!$this->boolValue('WinterID')||$this->intValue('OperatingModeID')===$this->ReadPropertyInteger('CoolingModeValue'))throw new RuntimeException('Heating is bypassed or cooling is selected');
            $rooms=$this->rooms();$this->validateConfiguration($rooms);
            $settings=$this->testSettings($minutes?:$this->ReadPropertyInteger('HeatPumpTestStepMinutes'));
            $excluded=$settings['HeatPumpTestExcludedFlapID'];$found=false;$flaps=[];
            foreach($rooms as $r) {
                if($r['flapID']===$excluded)$found=true;
                foreach($r['sensors'] as $id)if($this->testValue($id,true)>=$settings['HeatPumpTestMaximumRoomC'])throw new RuntimeException('A room is already at the test temperature limit');
                $flaps[$r['flapID']]=GetValue($r['flapID']);
            }
            if(!$found||count($rooms)<2)throw new RuntimeException('Select the configured master-bedroom flap to exclude; at least one other room is required');
            $meterState=['settings'=>$settings];
            foreach($this->testMeterIDs($meterState) as $id)$this->testValue($id);
            if($this->testDedicatedMeter($meterState))$this->testMeterSample($meterState);
            if($settings['HeatPumpTestHouseCounterScale']<=0||$settings['HeatPumpTestHouseCounterScale']>1)throw new RuntimeException('Invalid house-counter kWh/unit');
            foreach(['OutsideTempID','IncomingAirTempID','OutgoingAirTempID','HeatPumpTempID'] as $key)$this->testValue($this->id($key),true);
            $archive=$settings['HeatPumpTestArchiveID'];
            if(!function_exists('AC_SetLoggingStatus')||!IPS_InstanceExists($archive)||strtoupper(IPS_GetInstance($archive)['ModuleInfo']['ModuleID'])!==self::ARCHIVE_MODULE)throw new RuntimeException('Select Archive Control for test logging');
            $logged=$this->testMeterIDs($meterState);
            foreach(['OutsideTempID','IncomingAirTempID','OutgoingAirTempID','HeatPumpTempID','HeatPumpPowerID','HeatPumpOnID','HeatPumpHeatModeID','FanOnID','FanSpeedID','GasPumpID','GasMixerID'] as $key)$logged[]=$this->id($key);
            foreach($rooms as $r)$logged=array_merge($logged,$r['sensors'],[$r['targetID'],$r['flapID']]);
            foreach(array_unique($logged) as $id)if(!AC_GetLoggingStatus($archive,$id)) {
                if(!AC_SetLoggingStatus($archive,$id,true)||!AC_GetLoggingStatus($archive,$id))throw new RuntimeException('Cannot enable test logging: '.$id);
            }
            $dir=$this->testDirectory();if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Cannot create test storage');
            $now=$this->sourceClock();
            $s=['schema'=>1,'runID'=>gmdate('Ymd-His',$now).'-'.bin2hex(random_bytes(6)),'startedAt'=>$now,'phase'=>'prepare',
                'deadline'=>$now+8*$settings['HeatPumpTestStepMinutes']*60+2400,'settings'=>$settings,'rooms'=>$rooms,
                'ids'=>array_map(fn($key)=>$this->id($key),array_combine(['HeatPumpOnID','HeatPumpPowerID','HeatPumpHeatModeID','GasPumpID','GasMixerID','FanOnID','FanSpeedID','OperatingModeID'],['HeatPumpOnID','HeatPumpPowerID','HeatPumpHeatModeID','GasPumpID','GasMixerID','FanOnID','FanSpeedID','OperatingModeID'])),
                'originalFlaps'=>$flaps,'originalMode'=>$this->intValue('OperatingModeID'),'mixerClosed'=>$this->ReadPropertyInteger('MixerClosed'),'powers'=>[30,40,50,60,70,80,90,100],
                'index'=>0,'points'=>[],'window'=>[],'lastTick'=>0,'baseline'=>null,'baselines'=>[],'rebootHistory'=>[],'readySince'=>0,
                'configurationHash'=>$this->testConfigurationHash(),'nextRebootAt'=>$settings['HeatPumpTestRebootGuardEnabled']?$this->testNextReboot($settings,$now):0,
                'transientPolicy'=>HeatPumpTransient::policy(),'transientLog'=>[],'averageSeconds'=>min(300,$settings['HeatPumpTestStepMinutes']*20)];
            $this->testWrite($s,['kind'=>'header','test'=>$s,'airflowIsMeasured'=>false]);
            $this->saveTestState($s);$this->SetValue('HeatPumpTestActive',true);
            $this->SetValue('HeatPumpTestStatus','Preparing test; master bedroom closed; normal heating decisions suspended');
            $this->SetTimerInterval('HeatPumpTestTimer',10000);
        } finally {IPS_SemaphoreLeave($lock);}
        $this->ProcessHeating();
    }
    public function CancelHeatPumpTest(): void
    {
        $lock='HeatingControl.'.$this->InstanceID;if(!IPS_SemaphoreEnter($lock,1000))throw new RuntimeException('Controller busy');
        try {if(!$this->ReadPropertyBoolean('Enabled')){$this->releaseControlOwnership('Controller disabled');return;}$s=$this->testState();if($s){$this->beginTestFinish($s,'Cancelled by user',false);$this->finishTestTick($s);}}
        finally {IPS_SemaphoreLeave($lock);}
    }
    public function HeatPumpTestTick(): void {$this->ProcessHeating();}

    private function beginTestFinish(array &$s,string $reason,bool $complete): void
    {
        if($s['phase']!=='stopping'){$s['phase']='stopping';$s['finishReason']=$reason;$s['complete']=$complete;$this->saveTestState($s);}
        $this->SetValue('HeatPumpTestStatus','Stopping test: '.$s['finishReason']);
    }
    /** A dry-run change still stops test-owned equipment; disabling releases all control. */
    private function testStopCommands(array $s): void
    {
        $v=[$s['ids']['HeatPumpOnID']=>false,$s['ids']['HeatPumpPowerID']=>0,$s['ids']['GasPumpID']=>false,
            $s['ids']['GasMixerID']=>$s['mixerClosed']];
        $this->testOwnedCommands($v);
    }
    private function testOwnedCommands(array $v): bool
    {
        if (!$this->ReadPropertyBoolean('Enabled')) return false;
        $ok=true;
        foreach($v as $id=>$value) {
            try {
                if(!IPS_VariableExists($id))throw new RuntimeException('Stop output missing: '.$id);
                $m=IPS_GetVariable($id);if(!in_array($m['VariableType'],[0,1,2],true))throw new RuntimeException('Invalid test-owned output: '.$id);
                $value=$m['VariableType']===0?(bool)$value:($m['VariableType']===1?(int)$value:(float)$value);
                if(GetValue($id)===$value)continue;
                if(($m['VariableCustomAction']??0)||($m['VariableAction']??0))RequestAction($id,$value);
                elseif($m['VariableType']===0)SetValueBoolean($id,$value);elseif($m['VariableType']===1)SetValueInteger($id,$value);else SetValueFloat($id,$value);
            } catch(Throwable $e){$ok=false;$this->debug('TEST STOP '.$e->getMessage());}
        }
        return $ok;
    }
    private function finishTestTick(array &$s): void
    {
        $this->testStopCommands($s);
        try {$this->captureTestMeterBoundaries($s);}catch(Throwable $e){$this->debug('TEST METER '.$e->getMessage());}
        if(!IPS_VariableExists($s['ids']['HeatPumpOnID'])||!IPS_VariableExists($s['ids']['GasPumpID'])
            ||GetValue($s['ids']['HeatPumpOnID'])||GetValue($s['ids']['GasPumpID'])) {
            $this->SetValue('HeatPumpTestStatus','Waiting for test heat-source stop confirmation: '.$s['finishReason']);
            $this->saveTestState($s);$this->SetTimerInterval('HeatPumpTestTimer',10000);return;
        }
        $q=[$s['ids']['FanOnID']=>false,$s['ids']['FanSpeedID']=>0];$log=[];
        foreach($s['originalFlaps'] as $id=>$value)if(IPS_VariableExists((int)$id))$q[(int)$id]=$value;
        // A new DryRun setting cannot leave previously test-owned airflow running.
        if(!$this->testOwnedCommands($q))throw new RuntimeException('Could not restore test-owned fan/flap outputs');
        foreach($q as $id=>$value)if(GetValue($id)!==$value) {
            $this->SetValue('HeatPumpTestStatus','Waiting for fan/flap restoration confirmation: '.$s['finishReason']);
            $this->saveTestState($s);$this->SetTimerInterval('HeatPumpTestTimer',10000);return;
        }
        // The test never edits source mode or manual desired settings; preserve any
        // explicit external shutdown rather than overwriting it with an old mode.

        if($s['points'] && isset($s['baselineStartedAt']) && !($s['counterFinalized']??false)) {
            $end=$s['points'][count($s['points'])-1]['stepEndedAt'];
            if($this->sourceClock()<$end+75) {
                $this->SetValue('HeatPumpTestStatus','Sources stopped; waiting for final electricity-counter archive boundary');
                $this->saveTestState($s);return;
            }
            try {$this->finalizeTestCounterPoints($s);}
            catch(Throwable $e) {
                foreach($s['points'] as &$point){$point['usable']=false;$point['counterError']=$e->getMessage();}unset($point);
                $s['finishReason'].='; electricity-counter validation failed: '.$e->getMessage();
            }
            $s['counterFinalized']=true;
        }
        $relationships=HeatPumpPerformance::relationships($s['points']);
        // A normal configured temperature cutoff may end a run before 100%.
        // Its previously completed stable steps are still valid measurements.
        $temperatureStop=str_starts_with($s['finishReason'],'Room temperature limit:')||$s['finishReason']==='Heat-pump exchanger temperature limit';
        $qualified=($s['complete']||$temperatureStop)&&$relationships['usablePointCount']>=2;
        $report=['schema'=>1,'runID'=>$s['runID'],'startedAt'=>$s['startedAt'],'finishedAt'=>$this->sourceClock(),
            'complete'=>$s['complete'],'reason'=>$s['finishReason'],'stepMinutes'=>$s['settings']['HeatPumpTestStepMinutes'],
            'airflowM3h'=>$s['settings']['HeatPumpTestAirflowM3h'],'airflowIsMeasured'=>false,'fanSpeed'=>$s['settings']['HeatPumpTestFanSpeed'],
            'excludedFlapID'=>$s['settings']['HeatPumpTestExcludedFlapID'],'rooms'=>$s['rooms'],'baseline'=>$s['baselines'][0]??$s['baseline'],
            'baselines'=>$s['baselines']??[],'rebootHistory'=>$s['rebootHistory']??[],'pendingRebootPause'=>$s['rebootPause']??null,
            'transientGuardEnabled'=>$s['settings']['HeatPumpTestTransientGuardEnabled']??false,
            'transientPolicy'=>$s['transientPolicy']??HeatPumpTransient::policy(),'transientLog'=>$s['transientLog']??[],'pendingTransient'=>$s['stepTransient']??null,
            'temperatureMaxAgeSeconds'=>$s['settings']['MaxSensorAgeSeconds']??0,'electricalProvenance'=>$this->testElectricalProvenance($s),
            'electricalMethod'=>$this->testElectricalMethod($s),
            'points'=>$s['points'],'relationships'=>$relationships,'modelQualified'=>$qualified];
        $json=json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
        $this->testSaveJSON($this->testFile($s,'.summary.json'),$json);
        $this->testWrite($s,['kind'=>'footer','report'=>$report]);
        $this->WriteAttributeString('HeatPumpTestLastReport',$json);$this->SetValue('HeatPumpTestResults',$json);
        if($qualified) {
            $existing=$this->measuredMatrix();
            $model=['schema'=>2,'runID'=>$s['runID'],'createdAt'=>$this->sourceClock(),'fanSpeed'=>$report['fanSpeed'],
                'airflowM3h'=>$report['airflowM3h'],'airflowIsMeasured'=>false,'roomSignature'=>hash('sha256',json_encode($s['rooms'])),'points'=>HeatPumpMatrix::merge($existing,array_values(array_filter($s['points'],static fn($p)=>$p['usable']))),'relationships'=>$relationships];
            $m=json_encode($model,JSON_THROW_ON_ERROR);
            $this->testSaveJSON($this->testDirectory().'/model.json',$m);
            $this->WriteAttributeString('HeatPumpPerformanceModel',$m);$this->SetValue('HeatPumpModelJSON',$m);
        }
        $this->WriteAttributeString('HeatPumpTestState','');$this->SetValue('HeatPumpTestActive',false);$this->SetTimerInterval('HeatPumpTestTimer',0);
        $this->WriteAttributeString('HeatPumpStoppedAt',(string)$this->sourceClock());
        $this->WriteAttributeString('SourceTransfer','{"target":0,"since":0}');$this->WriteAttributeString('AutomaticWasActive','1');
        $this->WriteAttributeString('DemandLatch','{}');$this->WriteAttributeString('RecoveryLatch','{}');
        $this->SetValue('HeatPumpTestStatus',($s['complete']?'Completed':'Stopped').': '.$s['finishReason'].'; '.$relationships['usablePointCount'].' stable model points stored');
    }
    private function testSample(array $s): array
    {
        $row=['timestamp'=>$this->sourceClock()];
        foreach(['outside'=>'OutsideTempID','incoming'=>'IncomingAirTempID','outgoing'=>'OutgoingAirTempID','coil'=>'HeatPumpTempID'] as $key=>$property)$row[$key]=$this->testValue($this->id($property),true);
        if($row['outside'] < -20 || $row['outside'] > 24)throw new RuntimeException('Outside temperature beyond Sinclair heating range -20 to +24C');
        $row=array_replace($row,$this->testMeterSample($s));
        $targets=[];$rooms=[];
        foreach($s['rooms'] as $r) {
            $temperatures=array_map(fn($id)=>$this->testValue($id,true),$r['sensors']);
            $target=$this->testValue($r['targetID'],true);
            $rooms[]=['name'=>$r['name'],'actual'=>array_sum($temperatures)/count($temperatures),'target'=>$target,'flap'=>GetValue($r['flapID'])];
            if($r['flapID']!==$s['settings']['HeatPumpTestExcludedFlapID'])$targets[]=$target;
            if(max($temperatures)>=$s['settings']['HeatPumpTestMaximumRoomC'])throw new RuntimeException('Room temperature limit: '.$r['name']);
        }
        if($row['coil']>=$s['settings']['HeatPumpTestMaximumCoilC'])throw new RuntimeException('Heat-pump exchanger temperature limit');
        $row['rooms']=$rooms;$row['target']=max($targets);
        $row['sensorUpdated']=[];$row['sensorAgeSeconds']=[];
        foreach(['OutsideTempID','IncomingAirTempID','OutgoingAirTempID','HeatPumpTempID'] as $key) {
            $updated=(int)(IPS_GetVariable($this->id($key))['VariableUpdated']??0);
            $row['sensorUpdated'][$key]=$updated;
            $row['sensorAgeSeconds'][$key]=$updated>0?max(0,$row['timestamp']-$updated):null;
        }
        return $row;
    }
    private function processHeatPumpTest(): bool
    {
        $s=$this->testState();if(!$s)return false;
        $now=$this->sourceClock();
        if($s['phase']==='stopping'){$this->finishTestTick($s);return true;}
        if(!$this->ReadPropertyBoolean('Enabled')||$this->ReadPropertyBoolean('DryRun')) {
            $this->beginTestFinish($s,'Controller disabled or dry run enabled',false);$this->finishTestTick($s);return true;
        }
        if($now-$s['lastTick']<8)return true;
        try {
            if(!$this->ReadPropertyBoolean('Enabled')||$this->ReadPropertyBoolean('DryRun')||$this->boolValue('MasterDisableID')||!$this->boolValue('WinterID'))throw new RuntimeException('Controller disabled, dry run or heating bypass');
            if($this->intValue('OperatingModeID')===$this->ReadPropertyInteger('CoolingModeValue'))throw new RuntimeException('Cooling/shutdown selected during test');
            if(isset($s['configurationHash'])&&!hash_equals($s['configurationHash'],$this->testConfigurationHash()))throw new RuntimeException('Configuration changed during heat-pump test');
            if($this->testRebootPaused($s)){$this->processTestRebootPause($s);return true;}
            if($now>$s['deadline'])throw new RuntimeException('Maximum test duration reached');
            if($s['lastTick']&&$now-$s['lastTick']>90)throw new RuntimeException('Test timer interruption; measurements would be unreliable');
            if($this->testNeedsRebootPause($s,$now)){$this->beginTestRebootPause($s);$this->processTestRebootPause($s);return true;}
            $row=$this->testSample($s);$q=[];$log=[];$sampleLogged=false;
            if($this->testDedicatedMeter($s)) {
                $s['meterSegmentExportStart']??=$row['meterExportCounter'];
                $s['lastMeterSample']=$row;
                foreach($s['points'] as &$pending)if(!($pending['counterFinalized']??false)&&$now<=$pending['stepEndedAt']+75)$pending['meterBoundaries'][]=array_intersect_key($row,array_flip(['meterCounter','meterExportCounter','importUpdated','exportUpdated']));
                unset($pending);
            }
            $this->SetValue('EffectiveSource',$s['phase']==='step'?1:0);
            $this->SetValue('SelectedHeatPumpPower',$s['phase']==='step'?$s['powers'][$s['index']]:0);
            $this->SetValue('SourceSelectionStatus','Heat-pump test owns source/power; automatic recommendation suspended');
            foreach($s['rooms'] as $r)$this->queue($q,$r['flapID'],$r['flapID']===$s['settings']['HeatPumpTestExcludedFlapID']?$r['closed']:$r['open']);
            $this->SetValue('LearningSourceMode',0); // Exclude intentional tests from daily consumption learning.
            $this->queue($q,$this->id('GasPumpID'),false);$this->queue($q,$this->id('GasMixerID'),$this->ReadPropertyInteger('MixerClosed'));
            if($s['phase']==='prepare') {
                $this->queueHeatOff($q);$this->queueFanOff($q);$this->dispatch($q,$log);
                $ack=!$this->boolValue('HeatPumpOnID')&&!$this->boolValue('GasPumpID')&&$this->intValue('GasMixerID')===$this->ReadPropertyInteger('MixerClosed');
                if(!$ack)$s['readySince']=0;elseif(!$s['readySince'])$s['readySince']=$now;
                if($ack){$s['phase']='baseline';$s['phaseSince']=$now;$s['window']=[];}
            } else {
                $this->queue($q,$this->id('FanOnID'),true);$this->queue($q,$this->id('FanSpeedID'),$s['settings']['HeatPumpTestFanSpeed']);
                if($s['phase']==='baseline') {
                    $this->queueHeatOff($q);$this->queueFanOff($q);$this->dispatch($q,$log);
                    $s['window'][]=$row;
                    if($now-$s['phaseSince']>=$this->testBaselineSeconds($s)) {
                        $s['baseline']=['hpWatts'=>array_sum(array_column($s['window'],'hpWatts'))/count($s['window']),
                            'fanWatts'=>array_sum(array_column($s['window'],'fanWatts'))/count($s['window'])];
                        $s['baselineStartedAt']=$s['phaseSince'];$s['baselineEndedAt']=$now;
                        if(!$this->testDedicatedMeter($s))$s['baseline']['houseWatts']=$s['baseline']['hpWatts'];
                        $s['baseline']['subtractedFromCOP']=!$this->testDedicatedMeter($s);
                        $s['baselineIndex']=count($s['baselines']??[]);
                        $s['baselines'][]=$s['baseline']+['startedAt'=>$s['baselineStartedAt'],'endedAt'=>$now];
                        // Dedicated: two-minute meter/standby check, never subtracted. House: 15-minute baseline.
                        $s['phase']='step';$s['phaseSince']=$now;$s['window']=[];$s['fullWindow']=[$row];
                    }
                } else {
                    if($this->boolValue('GasPumpID'))throw new RuntimeException('Gas air pump activated during heat-pump test');
                    $power=$s['powers'][$s['index']];
                    $this->queueHeatSource($q,$this->ReadPropertyInteger('HeatPumpModeValue'),$power);$this->dispatch($q,$log);
                    if($now-$s['phaseSince']>60 && (!$this->boolValue('HeatPumpOnID')||abs($this->intValue('HeatPumpPowerID')-$power)>1
                        ||!$this->boolValue('HeatPumpHeatModeID')||!$this->boolValue('FanOnID')||$this->intValue('FanSpeedID')!==$s['settings']['HeatPumpTestFanSpeed']))throw new RuntimeException('Heat-pump/fan command state not confirmed or changed by another controller');
                    if($now-$s['phaseSince']>60)foreach($s['rooms'] as $r) {
                        $expected=$r['flapID']===$s['settings']['HeatPumpTestExcludedFlapID']?$r['closed']:$r['open'];
                        if(GetValue($r['flapID'])!==$expected)throw new RuntimeException('Test flap command not confirmed: '.$r['name']);
                    }
                    if($s['index']>0 || $now-$s['phaseSince']>180) {
                        $maxAge=$s['settings']['MaxSensorAgeSeconds']??$this->ReadPropertyInteger('MaxSensorAgeSeconds');
                        if($maxAge>0)foreach($row['sensorUpdated'] as $key=>$updated)if($updated<=0||$now-$updated>$maxAge)throw new RuntimeException('Test sensor stale: '.$key);
                        $this->testMeterFresh($s);
                    }
                    $row['powerPercent']=$power;
                    $this->testWrite($s,['kind'=>'sample','phase'=>'step','sample'=>$row]);$sampleLogged=true;
                    $s['fullWindow'][]=$row;
                    $intervals=$this->testMeasurementProgress($s,$now);
                    $lastCleanStart=$intervals&&end($intervals)['endedAt']===$now?end($intervals)['startedAt']:$now+1;
                    $s['window']=array_values(array_filter($s['fullWindow'],static fn($r)=>$r['timestamp']>=max($lastCleanStart,$now-$s['averageSeconds'])));
                    $requiredSeconds=$s['settings']['HeatPumpTestStepMinutes']*60;
                    if($s['cleanMeasurementSeconds']>=$requiredSeconds&&!HeatPumpTransient::active($s['stepTransient']??[])
                        &&$now-$lastCleanStart>=$s['averageSeconds']) {
                        $point=HeatPumpPerformance::point($s['window'],$s['baseline'],$s['settings']['HeatPumpTestAirflowM3h'],$power,$s['averageSeconds']);
                        $point['stepStartedAt']=$s['phaseSince'];$point['stepEndedAt']=$now;
                        $point['pointID']=$s['runID'].'-'.$power;$point['runID']=$s['runID'];$point['measuredAt']=$s['phaseSince'];
                        $point['baselineIndex']=$s['baselineIndex']??0;
                        $point['steadyIncoming']=$point['incoming'];$point['steadyOutgoing']=$point['outgoing'];
                        foreach(['outside','incoming','outgoing','coil','target'] as $key) {
                            $point['fullStepTemperatures'][$key]=HeatPumpMatrix::mean($s['fullWindow'],$key,$s['phaseSince'],$now);
                            $point[$key]=HeatPumpTransient::mean($s['fullWindow'],$key,$intervals);
                        }
                        $point['measurementIntervals']=$intervals;$point['excludedIntervals']=$s['stepTransient']['events']??[];
                        $point['excludedSeconds']=$now-$s['phaseSince']-$s['cleanMeasurementSeconds'];
                        $point['measurementBasis']=$point['excludedSeconds']>0?'transient-excluded-normal-heating':'full-step';
                        $point['fullPeriodAirRise']=$point['outgoing']-$point['incoming'];
                        $point['fanSpeed']=$s['settings']['HeatPumpTestFanSpeed'];$point['airflowM3h']=$s['settings']['HeatPumpTestAirflowM3h'];
                        $point['airflowIsMeasured']=false;$point['excludedFlapID']=$s['settings']['HeatPumpTestExcludedFlapID'];
                        $point=array_replace($point,$this->testElectricalProvenance($s));
                        if($this->testDedicatedMeter($s)) {
                            $boundaries=[$point['stepStartedAt'],$point['stepEndedAt']];
                            foreach($intervals as $interval)$boundaries=array_merge($boundaries,array_values($interval));
                            $point['meterBoundaries']=array_map(static fn($r)=>array_intersect_key($r,array_flip(['meterCounter','meterExportCounter','importUpdated','exportUpdated'])),
                                array_values(array_filter($s['fullWindow'],static function($r)use($boundaries){foreach($boundaries as $at)if(abs($r['timestamp']-$at)<=90)return true;return false;})));
                        }
                        $point['usable']=false;$point['systemInputKW']=null;$point['estimatedSystemCOP']=null; // Finalized after stop.

                        $s['points'][]=$point;$this->testWrite($s,['kind'=>'point','point'=>$point]);
                        $s['index']++;$s['window']=[];$s['fullWindow']=[$row];$s['phaseSince']=$now;
                        unset($s['stepTransient'],$s['stepExtensionAllocated'],$s['cleanMeasurementSeconds']);
                        if($s['index']>=count($s['powers'])){$this->beginTestFinish($s,'All eight power steps measured',true);$this->finishTestTick($s);return true;}
                        if($this->testNeedsRebootPause($s,$now)) {
                            $this->beginTestRebootPause($s);$this->processTestRebootPause($s);return true;
                        }
                        $this->queueHeatSource($q,$this->ReadPropertyInteger('HeatPumpModeValue'),$s['powers'][$s['index']]);$this->dispatch($q,$log);
                    }
                }
            }
            $s['lastTick']=$now;if(!$sampleLogged)$this->testWrite($s,['kind'=>'sample','phase'=>$s['phase'],'sample'=>$row]);$this->saveTestState($s);
            $this->SetValue('HeatPumpTestStatus',$s['phase']==='step' ? $this->testMeasurementStatus($s,$now) : 'Test '.$s['phase'].'; master bedroom closed');
            $this->publish((string)$this->GetValue('HeatPumpTestStatus'),$log);
        } catch(Throwable $e){$this->beginTestFinish($s,$e->getMessage(),false);$this->finishTestTick($s);}
        return true;
    }
    private function testMeasurementProgress(array &$s,int $now): array
    {
        $enabled=$s['settings']['HeatPumpTestTransientGuardEnabled']??false;
        $s['stepTransient']??=['events'=>[]];
        if($enabled)foreach(HeatPumpTransient::observe($s['stepTransient'],$s['fullWindow'],$s['phaseSince'],$now) as $event) {
            $event['powerPercent']=$s['powers'][$s['index']];$event['stepStartedAt']=$s['phaseSince'];
            $s['transientLog'][]=$event;$this->testWrite($s,$event);
        }
        $intervals=HeatPumpTransient::intervals($s['stepTransient'],$s['phaseSince'],$now);
        $s['cleanMeasurementSeconds']=HeatPumpTransient::seconds($intervals);
        $extension=max(0,$now-$s['phaseSince']-min($s['cleanMeasurementSeconds'],$s['settings']['HeatPumpTestStepMinutes']*60));
        if($extension>HeatPumpTransient::policy()['maximumStepExtensionSeconds'])throw new RuntimeException('Temperature-transient step extension exceeded 30 minutes');
        $s['deadline']+=max(0,$extension-($s['stepExtensionAllocated']??0));$s['stepExtensionAllocated']=$extension;
        return $intervals;
    }
    private function testMeasurementStatus(array $s,int $now): string
    {
        $clean=$s['cleanMeasurementSeconds']??max(0,$now-$s['phaseSince']);$elapsed=max(0,$now-$s['phaseSince']);
        $label=HeatPumpTransient::active($s['stepTransient']??[])?'temperature transient: holding power; waiting for stable recovery / safety bracket':'measuring';
        return sprintf('Step %d/8: %d%%; %s; clean %d/%d seconds; excluded %d seconds; master bedroom closed',
            $s['index']+1,$s['powers'][$s['index']],$label,$clean,$s['settings']['HeatPumpTestStepMinutes']*60,$elapsed-$clean);
    }

    private function finalizeHouseCounterPoints(array &$s): void
    {
        $baselines=$s['baselines']??[];
        if(!$baselines)$baselines=[($s['baseline']??[])+['startedAt'=>$s['baselineStartedAt'],'endedAt'=>$s['baselineEndedAt']]];
        foreach($baselines as $index=>$baseline) {
            $pending=array_filter($s['points'],static fn($p)=>($p['baselineIndex']??0)===$index&&!($p['counterFinalized']??false));
            if(!$pending)continue;
            $start=$baseline['startedAt'];$end=max(array_column($pending,'stepEndedAt'));
            try {
                $records=AC_GetLoggedValues($s['settings']['HeatPumpTestArchiveID'],$s['settings']['HeatPumpTestHouseCounterID'],$start-90,min($end+75,$this->sourceClock()),0);
                if(!is_array($records))throw new RuntimeException('House counter history unavailable');
                // Never read through a shutdown or use another segment's reset.
                $lastRecordAt=min($end+75,$this->sourceClock());
                $records=array_values(array_filter($records,static fn($r)=>$r['TimeStamp']>=$start-90&&$r['TimeStamp']<=$lastRecordAt));
                $scale=$s['settings']['HeatPumpTestHouseCounterScale'];
                usort($records,static fn($a,$b)=>$a['TimeStamp']<=>$b['TimeStamp']);
                foreach($records as $i=>$record)if($i&&$record['Value']<$records[$i-1]['Value'])throw new RuntimeException('House counter reset in test segment');
                $baseSeconds=$baseline['endedAt']-$start;
                $baseEnergy=(HeatPumpMatrix::counterAt($records,$baseline['endedAt'])-HeatPumpMatrix::counterAt($records,$start))*$scale;
                $baseKW=$baseEnergy*3600/$baseSeconds;
                if(!is_finite($baseKW)||$baseKW<0)throw new RuntimeException('Invalid household baseline');
                $s['baselines'][$index]=array_replace($baseline,['houseKW'=>$baseKW,'seconds'=>$baseSeconds]);
                foreach($s['points'] as &$p) {
                    if(($p['baselineIndex']??0)!==$index||($p['counterFinalized']??false))continue;
                    try {
                        $intervals=HeatPumpTransient::measurementIntervals($p);$seconds=HeatPumpTransient::seconds($intervals);
                        $gross=HeatPumpTransient::energy($records,$intervals,$scale);
                        if(isset($p['fullStepTemperatures'])) {
                            $wallSeconds=$p['stepEndedAt']-$p['stepStartedAt'];
                            $fullGross=(HeatPumpMatrix::counterAt($records,$p['stepEndedAt'])-HeatPumpMatrix::counterAt($records,$p['stepStartedAt']))*$scale;
                            $p['fullStep']=HeatPumpTransient::fullStep($p,$fullGross,$fullGross-$baseKW*$wallSeconds/3600,0.0,$s['settings']['HeatPumpTestAirflowM3h']);
                        }
                        $net=$gross-$baseKW*$seconds/3600;
                        $p['systemInputKW']=$net*3600/$seconds;
                        $p['estimatedHeatKW']=0.000335*$s['settings']['HeatPumpTestAirflowM3h']*$p['fullPeriodAirRise'];
                        $p['estimatedSystemCOP']=$net>0?$p['estimatedHeatKW']/$p['systemInputKW']:null;
                        $p['grossElectricityKWh']=$gross;$p['netElectricityKWh']=$net;$p['estimatedHeatKWh']=$p['estimatedHeatKW']*$seconds/3600;
                        $p['electricalMethod']='house-counter-minus-pretest-baseline';$p['counterID']=$s['settings']['HeatPumpTestHouseCounterID'];$p['counterScale']=$scale;
                        $p['baselineKW']=$baseKW;$p['baselineSeconds']=$baseSeconds;$p['baselineStartedAt']=$start;$p['baselineEndedAt']=$baseline['endedAt'];
                        $p['measurementSeconds']=$seconds;$p['lightingCorrectionApplied']=false;
                        unset($p['hpWatts'],$p['fanWatts'],$p['heatPumpKW'],$p['fanIncrementKW']);
                        $p['usable']=$p['stable']&&$p['airRise']>0&&$p['systemInputKW']>0.05&&$p['estimatedSystemCOP']>=0.5&&$p['estimatedSystemCOP']<=10;
                    } catch(Throwable $e) {$p['usable']=false;$p['counterError']=$e->getMessage();}
                    $p['counterFinalized']=true;
                }unset($p);
            } catch(Throwable $e) {
                foreach($s['points'] as &$p)if(($p['baselineIndex']??0)===$index&&!($p['counterFinalized']??false)) {
                    $p['usable']=false;$p['counterError']=$e->getMessage();$p['counterFinalized']=true;
                }unset($p);
            }
        }
    }

    private function heatPumpTestView(): array
    {
        $s=$this->testState();$report=json_decode($this->ReadAttributeString('HeatPumpTestLastReport')?:'null',true);
        $matrix=$this->measuredMatrix();$viewPoints=$s['points']??($report['points']??[]);
        foreach($viewPoints as &$point)if(!isset($point['electricalMethod'])) {
            $replacement=null;foreach($matrix as $row)if(isset($row['runID'])&&$row['runID']===($report['runID']??'')&&$row['powerPercent']===$point['powerPercent']){$replacement=$row;break;}
            if($replacement)$point=$replacement;else {$point['systemInputKW']=null;$point['estimatedSystemCOP']=null;}
        }unset($point);
        if(!$s)$viewPoints=array_map([HeatPumpMatrix::class,'applyPointReview'],$viewPoints);
        $rebootTime=$this->testRebootTime($this->ReadPropertyString('HeatPumpTestRebootTime'));
        return ['copMethod'=>$this->copMethodView(),'meterSource'=>$s['settings']['HeatPumpTestMeterSource']??$this->ReadPropertyString('HeatPumpTestMeterSource'),
            'configuredElectricalProvenance'=>$this->testElectricalProvenance($s?:['settings'=>$this->testSettings($this->ReadPropertyInteger('HeatPumpTestStepMinutes'))]),
            'reportElectricalProvenance'=>$report['electricalProvenance']??['electricalMethod'=>$report['electricalMethod']??'house-counter-minus-pretest-baseline'],
            'rebootGuardEnabled'=>$s['settings']['HeatPumpTestRebootGuardEnabled']??$this->ReadPropertyBoolean('HeatPumpTestRebootGuardEnabled'),
            'rebootTime'=>sprintf('%02d:%02d',$rebootTime['hour'],$rebootTime['minute']),
            'rebootTimezone'=>$s['settings']['rebootTimezone']??date_default_timezone_get(),'nextRebootAt'=>$s['nextRebootAt']??0,
            'transientGuardEnabled'=>$s['settings']['HeatPumpTestTransientGuardEnabled']??$this->ReadPropertyBoolean('HeatPumpTestTransientGuardEnabled'),
            'transientPolicy'=>HeatPumpTransient::policy(),'transientActive'=>HeatPumpTransient::active($s['stepTransient']??[]),
            'cleanMeasurementSeconds'=>$s['cleanMeasurementSeconds']??0,'currentTransient'=>$s['stepTransient']??null,
            'active'=>(bool)$s,'phase'=>$s['phase']??'idle','stepMinutes'=>$s['settings']['HeatPumpTestStepMinutes']??$this->ReadPropertyInteger('HeatPumpTestStepMinutes'),
            'status'=>$this->GetValue('HeatPumpTestStatus'),'powerPercent'=>($s['phase']??'')==='step' ? ($s['powers'][$s['index']]??0):0,
            'report'=>$report,'points'=>$viewPoints,'performanceStatus'=>$this->GetValue('HeatPumpPerformanceStatus'),
            'supplyAboveTarget'=>$this->ReadPropertyFloat('HeatPumpSupplyAboveTargetC'),'minimumAirRise'=>$this->ReadPropertyFloat('HeatPumpMinimumAirRiseC'),
            'matrixPoints'=>$matrix,'economics'=>json_decode((string)$this->GetValue('HeatPumpEconomicsJSON')?:'null',true)];
    }
    private function heatPumpRequirement(array $rooms): array
    {
        $targets=[];foreach($rooms as $r)$targets[]=$this->temperature($r['targetID'],false);
        return ['target'=>max($targets),'coilMinimum'=>0.0];
    }
}

