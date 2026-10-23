<?php
declare(strict_types=1);
require __DIR__.'/source-selection.php';
$beforeChecks=$checks;
function flapState($m): array { return json_decode($m->attributes['FlapCalibrationState']?:'{}',true); }
function ackCommands(): void { foreach($GLOBALS['commands'] as [$id,$v])$GLOBALS['variables'][$id]['value']=$v; }
function flapTick($m,int $seconds=0): void {$m->clock+=$seconds;run($m);}
function flapsMatch($m,string $position): bool {
 foreach(json_decode($m->properties['Rooms'],true) as $r)if(GetValue($r['flapID'])!==$r[$position])return false;
 return true;
}
function expectFailure(callable $f,string $label): void {try{$f();}catch(Throwable $e){check(true,$label);return;}check(false,$label);}
$m=fixture();check($m->properties['FlapCalibrationWaitSeconds']===120,'Default dwell is two minutes');
$GLOBALS['variables'][$m->properties['GasPumpID']]['value']=true;
$GLOBALS['variables'][$m->properties['FanOnID']]['value']=true;
$GLOBALS['commands']=[];$m->StartFlapCalibration();
check(flapState($m)['phase']===-1,'No flap motion before heat/fan stop acknowledgement');
check(count(array_intersect(array_column($GLOBALS['commands'],0),array_column(json_decode($m->properties['Rooms'],true),'flapID')))===0,'Stop is sent before any flap command');
ackCommands();flapTick($m);
check(flapState($m)['phase']===0&&flapsMatch($m,'open'),'Every configured flap opens, including master bedroom');
check(!value($m,'GasPumpID')&&!value($m,'HeatPumpOnID')&&!value($m,'FanOnID')&&value($m,'FanSpeedID')===0,'Sources and fan stay off');
check($m->values['LearningSourceMode']===0,'Calibration is excluded from normal source learning');
flapTick($m,119);check(flapState($m)['phase']===0&&flapsMatch($m,'open'),'First open dwell lasts full configured time');
flapTick($m,1);check(flapState($m)['phase']===1&&flapsMatch($m,'closed'),'All close at two minutes');
flapTick($m,119);check(flapState($m)['phase']===1,'Closed dwell lasts full configured time');
flapTick($m,1);check(flapState($m)['phase']===2&&flapsMatch($m,'open'),'All open again at four minutes');
flapTick($m,119);check($m->values['FlapCalibrationActive']&&flapsMatch($m,'open'),'Final open dwell retains exclusive control');
flapTick($m,1);check(!$m->values['FlapCalibrationActive']&&str_starts_with($m->values['FlapCalibrationStatus'],'Completed'),'Control returns after six minutes');
check(!value($m,'GasPumpID')&&!value($m,'HeatPumpOnID'),'Returning normal control retains source transfer protections');
flapTick($m,120);check(value($m,'GasPumpID'),'Normal heating resumes after protected handover');

$m=fixture();$m->SetFlapCalibrationWaitSeconds(3);$m->StartFlapCalibration();flapTick($m);
flapTick($m,3);check(flapState($m)['phase']===1,'Custom dwell is used');
expectFailure(fn()=>$m->SetFlapCalibrationWaitSeconds(20),'Active wait is immutable');
expectFailure(fn()=>$m->StartHeatPumpTest(),'Heat-pump tests cannot overlap flap calibration');
expectFailure(fn()=>$m->StartFlapCalibration(),'A duplicate start is rejected');
$m->CancelFlapCalibration();check(!$m->values['FlapCalibrationActive'],'Cancel returns control');
foreach([0,-1,3601] as $bad)expectFailure(fn()=>$m->SetFlapCalibrationWaitSeconds($bad),'Invalid wait rejected');

// Calibration deliberately reissues end-stop commands when stored positions already match.
$m=fixture();foreach(json_decode($m->properties['Rooms'],true) as $r){$GLOBALS['variables'][$r['flapID']]['value']=$r['open'];$GLOBALS['variables'][$r['flapID']]['VariableAction']=1;}
$GLOBALS['commands']=[];$m->StartFlapCalibration();ackCommands();$GLOBALS['commands']=[];$m->ProcessHeating();
foreach(json_decode($m->properties['Rooms'],true) as $r)check(in_array([$r['flapID'],$r['open']],$GLOBALS['commands'],true),'Reissue open for end-stop calibration: '.$r['name']);

// If stop acknowledgement is lost mid-dwell, no next position is allowed.
$m=fixture();$m->StartFlapCalibration();flapTick($m);flapTick($m,110);
$GLOBALS['variables'][$m->properties['FanOnID']]['value']=true;
$GLOBALS['commands']=[];$m->clock+=20;$m->ProcessHeating();
check(flapState($m)['phase']===0&&flapState($m)['until']===0,'Lost fan OFF acknowledgement pauses the sequence');
ackCommands();flapTick($m);check(flapState($m)['until']===$m->clock+120,'Full dwell restarts after stop acknowledgement recovers');
flapTick($m,119);check(flapState($m)['phase']===0,'Paused dwell cannot advance early');
flapTick($m,1);check(flapState($m)['phase']===1,'Sequence advances after full recovered dwell');

// Disabling never sends shutdown, restore, normal control or legacy writes.
$m=fixture();$GLOBALS['variables'][$m->properties['GasPumpID']]['value']=true;
$GLOBALS['variables'][$m->properties['FanOnID']]['value']=true;
$GLOBALS['commands']=[];$GLOBALS['directWrites']=[];$m->SetModuleEnabled(false);
check(!$GLOBALS['commands']&&!$GLOBALS['directWrites']&&value($m,'GasPumpID')&&value($m,'FanOnID'),'Disable leaves running device values untouched');
flapTick($m,1000);$m->ApplyChanges();$m->HeatPumpTestTick();
check(!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'Timers, events and apply cannot write while disabled');
expectFailure(fn()=>$m->StartFlapCalibration(),'Disabled controller cannot start calibration');
$m->SetModuleEnabled(true);check($m->properties['Enabled']&&$GLOBALS['commands'],'Enable restores rule-based control');

$m=fixture();$m->StartFlapCalibration();flapTick($m);$snapshot=$GLOBALS['variables'];
$GLOBALS['commands']=[];$GLOBALS['directWrites']=[];$m->SetModuleEnabled(false);flapTick($m,1000);
check(!$m->values['FlapCalibrationActive']&&!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'Disable interrupts calibration without restoring or changing equipment');
foreach(json_decode($m->properties['Rooms'],true) as $r)check(GetValue($r['flapID'])===$snapshot[$r['flapID']]['value'],'Disabled calibration retains flap state');
$m->SetModuleEnabled(true);check(!$m->values['FlapCalibrationActive'],'Interrupted sequence never resumes on enable');
$m=fixture();$m->StartFlapCalibration();flapTick($m);$GLOBALS['commands']=[];$m->ApplyChanges();
check(!$m->values['FlapCalibrationActive']&&!$GLOBALS['commands'],'Apply/restart interrupts flap sequence without stale output commands');
$m=fixture();$m->StartFlapCalibration();flapTick($m);$GLOBALS['variables'][$m->properties['MasterDisableID']]['value']=true;$GLOBALS['commands']=[];$m->ProcessHeating();
check(!$m->values['FlapCalibrationActive']&&!$GLOBALS['commands'],'Master bypass interrupts without linked output writes');
echo 'PASS: '.($checks-$beforeChecks)." flap sequence, control handover and disable assertions\n";
