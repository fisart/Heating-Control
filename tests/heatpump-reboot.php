<?php
declare(strict_types=1);
require __DIR__.'/heatpump-fixture.php';
date_default_timezone_set('Europe/Berlin');

function state(TestHeating $m): array {return json_decode($m->attributes['HeatPumpTestState']?:'{}',true,64,JSON_THROW_ON_ERROR);}
function rebootFixture(string $time='2026-10-15 02:00:00'): TestHeating {
    $GLOBALS['kernelRunlevel']=KR_READY;$GLOBALS['kernelStartTime']=strtotime('2026-10-14 03:02:00');
    $m=fixture();$m->clock=strtotime($time);$m->properties['HeatPumpTestRebootGuardEnabled']=true;
    $GLOBALS['loggedValues'][38023]=[['TimeStamp'=>$m->clock-60,'Value'=>100000.0-180*60/3600]];
    foreach($GLOBALS['variables'] as &$v)$v['VariableUpdated']=$m->clock;unset($v);
    return $m;
}
function reachPause(TestHeating $m): void {
    for($i=0;$i<500&&$m->values['HeatPumpTestActive']&&!in_array(state($m)['phase']??'',['reboot_wait'],true);$i++)tick($m);
    check($m->values['HeatPumpTestActive']&&(state($m)['phase']??'')==='reboot_wait','Test reaches a persistent scheduled reboot pause');
}
function reload(TestHeating $m,int $at,int $boot): TestHeating {
    $saved=[$m->properties,$m->attributes,$m->values];
    $n=new TestHeating();$n->Create();[$n->properties,$n->attributes,$n->values]=$saved;$n->clock=$at;
    $GLOBALS['instance']=$n;$GLOBALS['kernelStartTime']=$boot;
    return $n;
}

$m=rebootFixture();$m->StartHeatPumpTest(15);reachPause($m);$s=state($m);
check(array_column($s['points'],'powerPercent')===[30,40]&&$s['index']===2,'Completed 30/40 steps retained; pending 50% not advanced');
check($m->clock<strtotime('2026-10-15 02:55:00'),'Pause occurs before the five-minute reboot lead');
check(!GetValue(35931)&&GetValue(19207)===0&&!GetValue(46921)&&!GetValue(32875),'Sources and fan are confirmed off while paused');
check(GetValue(36911)===100&&!GetValue(40259),'Original flap positions restored during the pause');
check($s['rebootPause']['stopConfirmed']&&count($s['baselines'])===1,'Stop acknowledgement and first baseline are persisted');
for($i=0;$i<10;$i++)tick($m);
check(count(state($m)['points'])===2&&state($m)['points'][0]['counterFinalized'],'Completed counter measurements finalize before the logging interruption');
check(abs(state($m)['points'][0]['baselineKW']-.18)<.001,'Pre-reboot points use the original house baseline');
// Even an ApplyChanges at the scheduled time is not proof of an actual reboot.
$m->ApplyChanges();tick($m);
check(state($m)['phase']==='reboot_wait','Unchanged configuration apply preserves pause without resuming');

// Recreate the module with only persisted properties/attributes/variables. The
// source clock and boot identity change; archive history has a real gap/reset.
$m=reload($m,strtotime('2026-10-15 03:08:00'),strtotime('2026-10-15 03:07:00'));
$GLOBALS['kernelRunlevel']=10101;$commandCount=count($GLOBALS['commands'])+count($GLOBALS['directWrites']);
$m->ApplyChanges();$m->ProcessHeating();
check(count($GLOBALS['commands'])+count($GLOBALS['directWrites'])===$commandCount,'No hardware command is sent before KR_READY');
check(state($m)['phase']==='reboot_wait','Planned pause survives module recreation before readiness');
$GLOBALS['kernelRunlevel']=KR_READY;$m->MessageSink($m->clock,0,IPS_KERNELMESSAGE,[KR_READY]);
$GLOBALS['testHouseBaselineWatts']=400.0;$GLOBALS['variables'][13869]['value']=400.0;
$GLOBALS['variables'][38023]['value']=1000.0; // Reset during the excluded shutdown gap.
$GLOBALS['loggedValues'][38023][]=['TimeStamp'=>$m->clock,'Value'=>1000.0];
tick($m);
check(state($m)['phase']==='reboot_wait'&&!GetValue(35931),'Actual reboot still requires the startup settling wait');
for($i=0;$i<40&&state($m)['phase']==='reboot_wait';$i++)tick($m);
check(state($m)['phase']==='prepare'&&state($m)['index']===2,'Actual reboot resumes at preparation for the pending 50% step');
check(count(state($m)['rebootHistory'])===1,'Pause and confirmed restart have a single audit-history entry');
check(state($m)['nextRebootAt']===strtotime('2026-10-16 03:00:00'),'Next reboot is the next local calendar day');
for($i=0;$i<150&&state($m)['phase']!=='step';$i++)tick($m);
$s=state($m);
check($s['phase']==='step'&&count($s['baselines'])===2&&$s['baselineIndex']===1,'A fresh 15-minute baseline precedes resumed measurements');
check($s['baselines'][1]['startedAt']>$s['rebootHistory'][0]['resumedAt'],'New baseline begins after resumed off/transfer preparation');
check(array_column($s['points'],'powerPercent')===[30,40],'Fresh baseline does not overwrite earlier completed steps');
for($i=0;$i<600&&$m->values['HeatPumpTestActive'];$i++)tick($m);
$report=json_decode($m->values['HeatPumpTestResults'],true);
check(!$m->values['HeatPumpTestActive']&&$report['complete']&&count($report['points'])===8,'All eight unique steps finish across the planned restart');
check(array_column($report['points'],'powerPercent')===[30,40,50,60,70,80,90,100],'No duplicate or skipped power step across restart');
check($report['modelQualified']&&$report['relationships']['usablePointCount']===8,'Valid segmented measurements still qualify for the matrix');
check(abs($report['points'][0]['baselineKW']-.18)<.001&&abs($report['points'][2]['baselineKW']-.4)<.001,'Each side of the reboot uses its own baseline, despite the shutdown counter reset');
check(count($report['baselines'])===2&&count($report['rebootHistory'])===1,'Downloadable report carries baselines and reboot history');
check($report['points'][1]['stepEndedAt']<$report['rebootHistory'][0]['pausedAt']+1&&$report['points'][2]['stepStartedAt']>$report['baselines'][1]['startedAt'],'No completed measurement spans the shutdown');
$raw=file_get_contents($root.'/media/heatpump-tests/1/'.$report['runID'].'.jsonl');
check(str_contains($raw,'"kind":"reboot_pause"')&&str_contains($raw,'"kind":"reboot_resume"'),'Append-only raw readings contain both pause and resume audit events');
check(abs($report['baseline']['houseKW']-.18)<.001,'Legacy report baseline remains the first segment baseline');

// Resumption must reject dangerous temperatures and a power source that has
// not returned, even if the expected kernel restart was detected.
foreach(['room','exchanger','power'] as $fault) {
    $m=rebootFixture('2026-10-15 02:50:00');$m->StartHeatPumpTest();
    $m=reload($m,strtotime('2026-10-15 03:08:00'),strtotime('2026-10-15 03:07:00'));$m->ApplyChanges();tick($m);
    $m->clock+=300;
    foreach($GLOBALS['variables'] as &$v)$v['VariableUpdated']=$m->clock;unset($v);
    if($fault==='room')$GLOBALS['variables'][57694]['value']=28.0;
    if($fault==='exchanger')$GLOBALS['variables'][58696]['value']=55.0;
    if($fault==='power')$GLOBALS['variables'][13869]['VariableUpdated']=$m->clock-181;
    $m->ProcessHeating();
    check(!$m->values['HeatPumpTestActive']&&!GetValue(35931),'Post-reboot safety failure prevents resumption: '.$fault);
}

// A second restart during startup settling restarts that wait as well.
$m=rebootFixture('2026-10-15 02:50:00');$m->StartHeatPumpTest();
$m=reload($m,strtotime('2026-10-15 03:08:00'),strtotime('2026-10-15 03:07:00'));$m->ApplyChanges();tick($m);
$m=reload($m,strtotime('2026-10-15 03:12:00'),strtotime('2026-10-15 03:11:00'));$m->ApplyChanges();tick($m);
for($i=0;$i<25;$i++)tick($m);
check(state($m)['phase']==='reboot_wait','A second restart gets a fresh startup settling interval');
for($i=0;$i<10&&state($m)['phase']==='reboot_wait';$i++)tick($m);
check(state($m)['phase']==='prepare','Test resumes only after the latest restart has settled');

// A counter fault in one segment cannot relabel another segment or silently
// turn excluded electrical data into usable points.
$good=$report['points'][0];$bad=$report['points'][2];
unset($bad['counterFinalized']);$bad['usable']=false;
$m=rebootFixture();$segmented=['points'=>[$good,$bad],'baselines'=>$report['baselines'],'settings'=>['HeatPumpTestArchiveID'=>9,'HeatPumpTestHouseCounterID'=>38023,'HeatPumpTestHouseCounterScale'=>.001,'HeatPumpTestAirflowM3h'=>2000]];
$m->clock=$bad['stepEndedAt']+75;$GLOBALS['loggedValues'][38023]=[];
$method=new ReflectionMethod(HeatingControl::class,'finalizeHouseCounterPoints');$method->invokeArgs($m,[&$segmented]);
check($segmented['points'][0]===$good&&$segmented['points'][0]['usable'],'Already finalized pre-reboot data remains intact when the resumed counter segment is missing');
check(!$segmented['points'][1]['usable']&&isset($segmented['points'][1]['counterError']),'Missing post-reboot counter boundaries produce an explicit excluded-point reason');

// Pause before starting the baseline when a newly requested test is too close.
$m=rebootFixture('2026-10-15 02:50:00');$m->StartHeatPumpTest();
check(state($m)['phase']==='reboot_wait'&&!state($m)['points']&&!GetValue(35931),'Starting near reboot safely waits before collecting a baseline');
$m->clock=strtotime('2026-10-15 03:12:00');tick($m);
check(state($m)['phase']==='reboot_wait','Passing the scheduled time without an actual reboot never resumes');
$m->CancelHeatPumpTest();
check(!$m->values['HeatPumpTestActive']&&!json_decode($m->values['HeatPumpTestResults'],true)['modelQualified'],'User can cancel a paused test without resumption or learning');
$m->ApplyChanges();check(!$m->values['HeatPumpTestActive'],'Cancelled progress cannot revive after ApplyChanges');

$m=rebootFixture('2026-10-15 02:50:00');$m->StartHeatPumpTest();$m->clock=strtotime('2026-10-15 05:00:10');tick($m);
check(!$m->values['HeatPumpTestActive']&&str_contains($m->values['HeatPumpTestStatus'],'timed out'),'Missing restart has a two-hour bounded wait and a stopped result');

foreach(['DryRun'=>true,'Enabled'=>false,'HeatPumpTestAirflowM3h'=>2100] as $key=>$value) {
    $m=rebootFixture('2026-10-15 02:50:00');$m->StartHeatPumpTest();$m->properties[$key]=$value;$m->ApplyChanges();
    check(!$m->values['HeatPumpTestActive']&&!GetValue(35931),'Configuration/safety edit cannot automatically resume: '.$key);
}
foreach(['MasterDisableID'=>true,'WinterID'=>false,'OperatingModeID'=>0] as $key=>$value) {
    $m=rebootFixture('2026-10-15 02:50:00');$m->StartHeatPumpTest();$GLOBALS['variables'][$m->properties[$key]]['value']=$value;tick($m);
    check(!$m->values['HeatPumpTestActive']&&!GetValue(35931),'Runtime bypass cancels a planned pause: '.$key);
}
$m=rebootFixture();$m->StartHeatPumpTest();$m=reload($m,strtotime('2026-10-15 02:05:00'),strtotime('2026-10-15 02:04:00'));$m->ApplyChanges();
check(!$m->values['HeatPumpTestActive'],'Unexpected restart outside an acknowledged pause still cancels the test');

// A reboot before output confirmation must not gain the automatic-resume right.
$m=rebootFixture('2026-10-15 02:50:00');$GLOBALS['variables'][35931]['value']=true;$GLOBALS['variables'][35931]['VariableAction']=1;
$m->StartHeatPumpTest();check(state($m)['phase']==='reboot_pausing','Unacknowledged heat stop stays in pausing, not confirmed wait');
$m=reload($m,strtotime('2026-10-15 03:08:00'),strtotime('2026-10-15 03:07:00'));$GLOBALS['variables'][35931]['value']=false;$m->ApplyChanges();tick($m);
check(!$m->values['HeatPumpTestActive']&&str_contains($m->values['HeatPumpTestStatus'],'before the planned test stop was confirmed'),'Restart before stop acknowledgement cancels rather than resuming');

// If an old in-flight step is found at the guard, its samples are retained only
// as raw telemetry; the same pending power gets a fresh complete measurement.
$m=rebootFixture('2026-10-15 02:00:00');$m->StartHeatPumpTest();
for($i=0;$i<150&&(state($m)['phase']??'')!=='step';$i++)tick($m);
tick($m);$s=state($m);$s['nextRebootAt']=$m->clock+360;webInvoke($m,'saveTestState',$s);tick($m);
check(state($m)['phase']==='reboot_wait'&&state($m)['index']===0&&!state($m)['points']&&!state($m)['fullWindow'],'Partial step is discarded from measurement state without advancing the sequence');

// Native SelectTime schema validation and authenticated page controls.
$m=rebootFixture();$GLOBALS['authenticated']=true;
check(response($m,'POST',[],['control'=>'HeatPumpTestReboot','value'=>['enabled'=>true,'time'=>'04:15']])['status']===200,'Protected page saves reboot settings');
check(json_decode($m->properties['HeatPumpTestRebootTime'],true)['hour']===4,'Reboot time uses the native SelectTime property schema');
foreach(['24:00','3:00','03:60','03:00:00'] as $time)check(response($m,'POST',[],['control'=>'HeatPumpTestReboot','value'=>['enabled'=>true,'time'=>$time]])['status']===422,'Invalid reboot time rejected: '.$time);
check(response($m,'POST',[],['control'=>'HeatPumpTestReboot','value'=>['enabled'=>true,'time'=>'03:00','extra'=>1]])['status']===422,'Reboot controls reject extra fields');
$GLOBALS['authenticated']=false;
check(response($m,'POST',[],['control'=>'HeatPumpTestReboot','value'=>['enabled'=>true,'time'=>'03:00']])['status']===401,'Reboot controls cannot bypass the passkey gate');
$GLOBALS['authenticated']=true;
check(response($m,'POST',[],['control'=>'HeatPumpTestReboot','value'=>['enabled'=>true,'time'=>'03:00']],['HTTP_X_HEATING_CSRF'=>'bad'])['status']===403,'Reboot controls cannot bypass CSRF');
$m->StartHeatPumpTest();
check(response($m,'POST',[],['control'=>'HeatPumpTestReboot','value'=>['enabled'=>false,'time'=>'03:00']])['status']===422,'Running-test reboot settings remain immutable');

// DST and midnight boundaries must follow the local calendar, not fixed seconds.
$settings=['HeatPumpTestRebootTime'=>'{"hour":3,"minute":0,"second":0}','rebootTimezone'=>'Europe/Berlin'];
check(webInvoke($m,'testNextReboot',$settings,strtotime('2026-10-24 04:00:00'),false)===strtotime('2026-10-25 03:00:00'),'Autumn DST next reboot uses Berlin wall time');
check(webInvoke($m,'testNextReboot',$settings,strtotime('2026-03-28 04:00:00'),false)===strtotime('2026-03-29 03:00:00'),'Spring DST next reboot uses Berlin wall time');
$settings['HeatPumpTestRebootTime']='{"hour":0,"minute":0,"second":0}';
check(webInvoke($m,'testNextReboot',$settings,strtotime('2026-10-15 23:50:00'),false)===strtotime('2026-10-16 00:00:00'),'Midnight schedule advances to the next local date');
check(webInvoke($m,'testRebootTime','{"second":0,"minute":15,"hour":4}')===['hour'=>4,'minute'=>15,'second'=>0],'SelectTime keys are canonicalized rather than depending on JSON order');
echo "PASS: $checks planned-reboot pause, recovery, measurement and safety checks\n";

