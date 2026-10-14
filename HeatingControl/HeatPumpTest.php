<?php
declare(strict_types=1);
require_once __DIR__.'/HeatPumpPerformance.php';

trait HeatingControlHeatPumpTest
{
    private const TEST_DEFAULTS = [
        'HeatPumpTestStepMinutes'=>15,'HeatPumpTestFanSpeed'=>90,'HeatPumpTestExcludedFlapID'=>40259,'HeatPumpTestArchiveID'=>14817,
        'HeatPumpTestPowerMeterID'=>35702,'HeatPumpTestFanMeterID'=>47831,
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
        $s=[];foreach(self::TEST_DEFAULTS as $n=>$v)$s[$n]=is_bool($v)?$this->ReadPropertyBoolean($n):(is_int($v)?$this->ReadPropertyInteger($n):$this->ReadPropertyFloat($n));
        $s['HeatPumpTestStepMinutes']=$minutes;
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
            foreach(['HeatPumpTestPowerMeterID','HeatPumpTestFanMeterID'] as $key)$this->testValue($settings[$key]);
            foreach(['OutsideTempID','IncomingAirTempID','OutgoingAirTempID','HeatPumpTempID'] as $key)$this->testValue($this->id($key),true);
            $archive=$settings['HeatPumpTestArchiveID'];
            if(!function_exists('AC_SetLoggingStatus')||!IPS_InstanceExists($archive)||strtoupper(IPS_GetInstance($archive)['ModuleInfo']['ModuleID'])!==self::ARCHIVE_MODULE)throw new RuntimeException('Select Archive Control for test logging');
            $logged=[$settings['HeatPumpTestPowerMeterID'],$settings['HeatPumpTestFanMeterID']];
            foreach(['OutsideTempID','IncomingAirTempID','OutgoingAirTempID','HeatPumpTempID','HeatPumpPowerID','HeatPumpOnID','HeatPumpHeatModeID','FanOnID','FanSpeedID','GasPumpID','GasMixerID'] as $key)$logged[]=$this->id($key);
            foreach($rooms as $r)$logged=array_merge($logged,$r['sensors'],[$r['targetID'],$r['flapID']]);
            foreach(array_unique($logged) as $id)if(!AC_GetLoggingStatus($archive,$id)) {
                if(!AC_SetLoggingStatus($archive,$id,true)||!AC_GetLoggingStatus($archive,$id))throw new RuntimeException('Cannot enable test logging: '.$id);
            }
            $dir=$this->testDirectory();if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Cannot create test storage');
            $now=$this->sourceClock();
            $s=['schema'=>1,'runID'=>gmdate('Ymd-His',$now).'-'.bin2hex(random_bytes(6)),'startedAt'=>$now,'phase'=>'prepare',
                'deadline'=>$now+8*$settings['HeatPumpTestStepMinutes']*60+1200,'settings'=>$settings,'rooms'=>$rooms,
                'ids'=>array_map(fn($key)=>$this->id($key),array_combine(['HeatPumpOnID','HeatPumpPowerID','HeatPumpHeatModeID','GasPumpID','GasMixerID','FanOnID','FanSpeedID','OperatingModeID'],['HeatPumpOnID','HeatPumpPowerID','HeatPumpHeatModeID','GasPumpID','GasMixerID','FanOnID','FanSpeedID','OperatingModeID'])),
                'originalFlaps'=>$flaps,'originalMode'=>$this->intValue('OperatingModeID'),'mixerClosed'=>$this->ReadPropertyInteger('MixerClosed'),'powers'=>[30,40,50,60,70,80,90,100],
                'index'=>0,'points'=>[],'window'=>[],'lastTick'=>0,'baseline'=>null,'readySince'=>0,
                'averageSeconds'=>min(300,$settings['HeatPumpTestStepMinutes']*20)];
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
        try {$s=$this->testState();if($s){$this->beginTestFinish($s,'Cancelled by user',false);$this->finishTestTick($s);}}
        finally {IPS_SemaphoreLeave($lock);}
    }
    public function HeatPumpTestTick(): void {$this->ProcessHeating();}

    private function beginTestFinish(array &$s,string $reason,bool $complete): void
    {
        if($s['phase']!=='stopping'){$s['phase']='stopping';$s['finishReason']=$reason;$s['complete']=$complete;$this->saveTestState($s);}
        $this->SetValue('HeatPumpTestStatus','Stopping test: '.$s['finishReason']);
    }
    /** Test-owned stop remains effective even if Enabled/DryRun changes during a run. */
    private function testStopCommands(array $s): void
    {
        $v=[$s['ids']['HeatPumpOnID']=>false,$s['ids']['HeatPumpPowerID']=>0,$s['ids']['GasPumpID']=>false,
            $s['ids']['GasMixerID']=>$s['mixerClosed']];
        $this->testOwnedCommands($v);
    }
    private function testOwnedCommands(array $v): bool
    {
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
        $relationships=HeatPumpPerformance::relationships($s['points']);
        // A normal configured temperature cutoff may end a run before 100%.
        // Its previously completed stable steps are still valid measurements.
        $temperatureStop=str_starts_with($s['finishReason'],'Room temperature limit:')||$s['finishReason']==='Heat-pump exchanger temperature limit';
        $qualified=($s['complete']||$temperatureStop)&&$relationships['usablePointCount']>=2;
        $report=['schema'=>1,'runID'=>$s['runID'],'startedAt'=>$s['startedAt'],'finishedAt'=>$this->sourceClock(),
            'complete'=>$s['complete'],'reason'=>$s['finishReason'],'stepMinutes'=>$s['settings']['HeatPumpTestStepMinutes'],
            'airflowM3h'=>$s['settings']['HeatPumpTestAirflowM3h'],'airflowIsMeasured'=>false,'fanSpeed'=>$s['settings']['HeatPumpTestFanSpeed'],
            'excludedFlapID'=>$s['settings']['HeatPumpTestExcludedFlapID'],'rooms'=>$s['rooms'],'baseline'=>$s['baseline'],
            'temperatureMaxAgeSeconds'=>$s['settings']['MaxSensorAgeSeconds']??0,
            'points'=>$s['points'],'relationships'=>$relationships,'modelQualified'=>$qualified];
        $json=json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
        $this->testSaveJSON($this->testFile($s,'.summary.json'),$json);
        $this->testWrite($s,['kind'=>'footer','report'=>$report]);
        $this->WriteAttributeString('HeatPumpTestLastReport',$json);$this->SetValue('HeatPumpTestResults',$json);
        if($qualified) {
            $model=['schema'=>1,'runID'=>$s['runID'],'createdAt'=>$this->sourceClock(),'fanSpeed'=>$report['fanSpeed'],
                'airflowM3h'=>$report['airflowM3h'],'airflowIsMeasured'=>false,'roomSignature'=>hash('sha256',json_encode($s['rooms'])),'points'=>$s['points'],'relationships'=>$relationships];
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
        $row['hpWatts']=$this->testValue($s['settings']['HeatPumpTestPowerMeterID']);
        $row['fanWatts']=$this->testValue($s['settings']['HeatPumpTestFanMeterID']);
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
            if($now>$s['deadline'])throw new RuntimeException('Maximum test duration reached');
            if($this->intValue('OperatingModeID')===$this->ReadPropertyInteger('CoolingModeValue'))throw new RuntimeException('Cooling/shutdown selected during test');
            if($s['lastTick']&&$now-$s['lastTick']>90)throw new RuntimeException('Test timer interruption; measurements would be unreliable');
            $row=$this->testSample($s);$q=[];$log=[];
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
                $wait=max($this->ReadPropertyInteger('HeatPumpMinimumOffSeconds'),$this->ReadPropertyInteger('SourceTransferDelaySeconds'));
                if($s['readySince']&&$now-$s['readySince']>=$wait){$s['phase']='baseline';$s['phaseSince']=$now;$s['window']=[];}
            } else {
                $this->queue($q,$this->id('FanOnID'),true);$this->queue($q,$this->id('FanSpeedID'),$s['settings']['HeatPumpTestFanSpeed']);
                if($s['phase']==='baseline') {
                    $this->queueHeatOff($q);$this->queueFanOff($q);$this->dispatch($q,$log);
                    $s['window'][]=$row;
                    if($now-$s['phaseSince']>=120) {
                        $s['baseline']=['hpWatts'=>array_sum(array_column($s['window'],'hpWatts'))/count($s['window']),
                            'fanWatts'=>array_sum(array_column($s['window'],'fanWatts'))/count($s['window'])];
                        // Both sources and external fan are off for the electrical baseline.
                        $s['phase']='step';$s['phaseSince']=$now;$s['window']=[];
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
                        foreach(['HeatPumpTestPowerMeterID','HeatPumpTestFanMeterID'] as $key)if($now-(IPS_GetVariable($s['settings'][$key])['VariableUpdated']??0)>180)throw new RuntimeException('Power meter stale: '.$key);
                    }
                    $row['powerPercent']=$power;
                    $s['window'][]=$row;$s['window']=array_values(array_filter($s['window'],static fn($r)=>$r['timestamp']>=$now-$s['averageSeconds']));
                    if($now-$s['phaseSince']>=$s['settings']['HeatPumpTestStepMinutes']*60) {
                        $point=HeatPumpPerformance::point($s['window'],$s['baseline'],$s['settings']['HeatPumpTestAirflowM3h'],$power,$s['averageSeconds']);
                        $point['stepStartedAt']=$s['phaseSince'];$point['stepEndedAt']=$now;
                        $s['points'][]=$point;$this->testWrite($s,['kind'=>'point','point'=>$point]);
                        $s['index']++;$s['window']=[];$s['phaseSince']=$now;
                        if($s['index']>=count($s['powers'])){$this->beginTestFinish($s,'All eight power steps measured',true);$this->finishTestTick($s);return true;}
                        $this->queueHeatSource($q,$this->ReadPropertyInteger('HeatPumpModeValue'),$s['powers'][$s['index']]);$this->dispatch($q,$log);
                    }
                }
            }
            $s['lastTick']=$now;$this->testWrite($s,['kind'=>'sample','phase'=>$s['phase'],'sample'=>$row]);$this->saveTestState($s);
            $this->SetValue('HeatPumpTestStatus',$s['phase']==='step' ? sprintf('Step %d/8: %d%%; %d/%d seconds; master bedroom closed',$s['index']+1,$s['powers'][$s['index']],$now-$s['phaseSince'],$s['settings']['HeatPumpTestStepMinutes']*60) : 'Test '.$s['phase'].'; master bedroom closed');
            $this->publish((string)$this->GetValue('HeatPumpTestStatus'),$log);
        } catch(Throwable $e){$this->beginTestFinish($s,$e->getMessage(),false);$this->finishTestTick($s);}
        return true;
    }
    private function heatPumpTestView(): array
    {
        $s=$this->testState();$report=json_decode($this->ReadAttributeString('HeatPumpTestLastReport')?:'null',true);
        return ['active'=>(bool)$s,'phase'=>$s['phase']??'idle','stepMinutes'=>$s['settings']['HeatPumpTestStepMinutes']??$this->ReadPropertyInteger('HeatPumpTestStepMinutes'),
            'status'=>$this->GetValue('HeatPumpTestStatus'),'powerPercent'=>($s['phase']??'')==='step' ? ($s['powers'][$s['index']]??0):0,
            'report'=>$report,'points'=>$s['points']??($report['points']??[]),'performanceStatus'=>$this->GetValue('HeatPumpPerformanceStatus'),
            'occupiedMinimumCoil'=>$this->ReadPropertyFloat('HeatPumpOccupiedMinimumCoilC'),
            'holidayMinimumCoil'=>$this->ReadPropertyFloat('HeatPumpHolidayMinimumCoilC')];
    }
    private function heatPumpRequirement(array $rooms): array
    {
        $targets=[];foreach($rooms as $r)$targets[]=$this->temperature($r['targetID'],false);
        $target=max($targets);$holiday=$this->boolValue('HolidayID');
        $minimum=$holiday?max($this->ReadPropertyFloat('HeatPumpHolidayMinimumCoilC'),$target+$this->ReadPropertyFloat('HeatPumpHolidayCoilAboveTargetC')):$this->ReadPropertyFloat('HeatPumpOccupiedMinimumCoilC');
        return ['holiday'=>$holiday,'target'=>$target,'coilMinimum'=>$minimum];
    }
    private function measuredPowerChoice(int $power,string &$reason): int
    {
        if(!$this->ReadPropertyBoolean('HeatPumpUseTestModel'))return $power;
        $raw=$this->ReadAttributeString('HeatPumpPerformanceModel');if($raw==='')return $power;
        $model=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$req=$this->heatPumpRequirement($this->rooms());
        if(($model['schema']??0)!==1 || $this->sourceClock()-($model['createdAt']??0)>90*86400
            || ($model['fanSpeed']??0)!==$this->intValue('FanDesiredSpeedID') || ($model['roomSignature']??'')!==hash('sha256',json_encode($this->rooms()))) {$reason.='; measured model unavailable for current fan/rooms/age';return $power;}
        $p=HeatPumpPerformance::choose($model['points'],$this->temperature($this->id('OutsideTempID')),$this->temperature($this->id('IncomingAirTempID')),
            $req['coilMinimum'],$req['target'],$this->ReadPropertyFloat('HeatPumpSupplyAboveTargetC'),$this->ReadPropertyFloat('HeatPumpMinimumAirRiseC'));
        if(!$p){$reason.='; gas fallback: no qualified local test point satisfies current exchanger/room target requirements';$this->SetValue('HeatPumpPerformanceStatus',$reason);return 0;}
        $reason.='; measured local point '.$p['powerPercent'].'% for exchanger ≥'.round($req['coilMinimum'],1).'°C';
        $this->SetValue('HeatPumpPerformanceStatus',$reason.'; estimated COP, airflow not calibrated');
        // The provider's economic power may not authorize a more expensive command.
        // Never retain its old price threshold blindly after raising the percentage.
        if($p['powerPercent']>$power && !$this->measuredPriceAllows($p,$reason))return 0;
        return max($power,(int)$p['powerPercent']);
    }
    private function measuredPriceAllows(array $point,string &$reason): bool
    {
        try {
            $instance=$this->ReadPropertyInteger('ElectricityPriceInstanceID');
            $read=static function(string $ident)use($instance){return GetValue(IPS_GetObjectIDByIdent($ident,$instance));};
            if($read('PriceValid')!==true)throw new RuntimeException('Price is not verified');
            $price=(float)$read('CurrentPriceCt');$ceiling=(float)$read('EffectiveThresholdCt');
            require_once __DIR__.'/../LichtBlickElectricityPrice/WeatherModel.php';
            $curve=LichtBlickWeatherModel::curve((string)IPS_GetProperty($instance,'WeatherCurve'));
            $ref=LichtBlickWeatherModel::point($curve,(float)IPS_GetProperty($instance,'WeatherReferenceTemperatureC'),(int)IPS_GetProperty($instance,'WeatherPowerStepPercent'));
            $fan=(float)IPS_GetProperty($instance,'WeatherFanKW');$refCOP=$ref['cop']*$ref['heatPumpKW']/($ref['heatPumpKW']+$fan);
            $threshold=(float)$read('ReferenceThresholdCt')*(float)IPS_GetProperty($instance,'GasPriceCt')/(float)IPS_GetProperty($instance,'WeatherReferenceGasPriceCt')*$point['estimatedSystemCOP']/$refCOP;
            $safe=$threshold*(1-(float)IPS_GetProperty($instance,'SafetyMarginPercent')/100);
            $entry=min($ceiling,$safe)-(float)IPS_GetProperty($instance,'HysteresisCt');
            if(!is_finite($entry)||!is_finite($price)||$price>=$entry)throw new RuntimeException('Higher measured power is not economical under the conservative adjusted threshold');
            $reason.='; adjusted higher-power entry '.round($entry,2).' ct/kWh';return true;
        }catch(Throwable $e){$reason.='; gas fallback: '.$e->getMessage();$this->SetValue('HeatPumpPerformanceStatus',$reason);return false;}
    }
}
