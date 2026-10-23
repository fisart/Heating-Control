<?php
declare(strict_types=1);
require __DIR__.'/heatpump-fixture.php';

function dedicatedFixture(): TestHeating {
    $m=fixture();$m->properties['HeatPumpTestMeterSource']='dedicated';unset($GLOBALS['meterTrueEnergy']);
    foreach([28681=>100.0,22365=>10.0,57639=>0.48] as $id=>$value)$GLOBALS['variables'][$id]=['VariableType'=>2,'value'=>$value,'VariableUpdated'=>$m->clock];
    $GLOBALS['variables'][37456]=['VariableType'=>0,'value'=>true,'VariableUpdated'=>$m->clock];
    foreach([22365,57639] as $id)$GLOBALS['loggedValues'][$id]=[['TimeStamp'=>$m->clock,'Value'=>GetValue($id)]];
    return $m;
}
function dedicatedTick(TestHeating $m,int $seconds=10,int $pollSeconds=10): void {
    $s=json_decode($m->attributes['HeatPumpTestState']?:'{}',true);
    $true=$pollSeconds===10?GetValue(22365):($GLOBALS['meterTrueEnergy']??GetValue(22365));
    $GLOBALS['meterTrueEnergy']=$true+GetValue(28681)*$seconds/3600000;
    $m->clock+=$seconds;
    $polled=$m->clock%$pollSeconds===0;
    if($polled)$GLOBALS['variables'][22365]['value']=$GLOBALS['meterTrueEnergy'];
    if(($s['phase']??'')==='step') {
        $power=$s['powers'][$s['index']];
        foreach(['IncomingAirTempID'=>23.0,'OutgoingAirTempID'=>23+$power*.1,'HeatPumpTempID'=>23+$power*.24] as $key=>$value)$GLOBALS['variables'][$m->properties[$key]]['value']=$value;
        $GLOBALS['variables'][28681]['value']=100+$power*20.0;
    } else $GLOBALS['variables'][28681]['value']=100.0;
    if($polled)foreach([22365,57639] as $id){$GLOBALS['loggedValues'][$id][]=['TimeStamp'=>$m->clock,'Value'=>GetValue($id)];$GLOBALS['variables'][$id]['VariableUpdated']=$m->clock;}
    foreach($GLOBALS['variables'] as $id=>&$v)if(!in_array($id,[22365,57639],true))$v['VariableUpdated']=$m->clock;unset($v);
    $m->ProcessHeating();
}

$m=dedicatedFixture();unset($GLOBALS['variables'][13869],$GLOBALS['variables'][38023]);
$m->StartHeatPumpTest(3);
check(AC_GetLoggingStatus(9,28681)&&AC_GetLoggingStatus(9,22365)&&AC_GetLoggingStatus(9,57639),'Dedicated power/import/export logging enabled without the house meter');
$stepsAt=0;
for($i=0;$i<300&&$m->values['HeatPumpTestActive'];$i++) {
    dedicatedTick($m);$s=json_decode($m->attributes['HeatPumpTestState']?:'{}',true);
    if(!$stepsAt&&($s['phase']??'')==='step')$stepsAt=$m->clock;
}
$r=json_decode($m->values['HeatPumpTestResults'],true);
check(!$m->values['HeatPumpTestActive']&&$r['complete']&&count($r['points'])===8,'Dedicated eight-step run completes');
check($stepsAt-$r['startedAt']<900,'Dedicated source uses a two-minute check rather than a fifteen-minute house baseline');
check($r['modelQualified']&&$r['relationships']['usablePointCount']===8,'Dedicated points enter the measured model');
check($r['electricalMethod']==='dedicated-heatpump-import-counter'&&$r['electricalProvenance']['counterID']===22365,'Summary identifies the new meter and method');
foreach($r['points'] as $p) {
    check($p['usable']&&$p['counterFinalized']&&$p['counterID']===22365&&$p['powerMeterID']===28681&&(float)$p['counterScale']===1.0,'Each point retains meter IDs/units and finalized quality');
    check($p['baselineSubtracted']===false&&(float)$p['baselineKW']===0.0&&$p['netElectricityKWh']===$p['grossElectricityKWh'],'Standby/household energy is not subtracted');
    check(abs($p['systemInputKW']-$p['netElectricityKWh']*3600/$p['measurementSeconds'])<1e-9,'Energy and mean electrical power cover the same full step');
    check(abs($p['estimatedSystemCOP']-$p['estimatedHeatKWh']/$p['netElectricityKWh'])<1e-9,'COP uses matched heat and dedicated imported electricity');
}
check(count(HeatPumpMatrix::validate($r['points']))===8,'Strict matrix validation accepts dedicated provenance');
$matrix=webInvoke($m,'measuredMatrix');
check(count(array_filter($matrix,static fn($p)=>$p['electricalMethod']==='house-counter-minus-pretest-baseline'))===25,'Historical household calibration remains intact');
check(count(array_filter($matrix,static fn($p)=>$p['electricalMethod']==='dedicated-heatpump-import-counter'))===26,'Dedicated measurements coexist with historical observations');
check(str_contains(HeatPumpMatrix::economics($r['points'][0],10.48,100,25,2,20,2)['assumptions'],'dedicated circuit only'),'Price calculation exposes the circuit scope');
$good=$r['points'][0];
foreach(['counterID'=>0,'baselineSubtracted'=>true,'exportElectricityKWh'=>.01,'counterScale'=>0.0,'counterFinalized'=>false] as $key=>$value) {
    try {HeatPumpMatrix::validate([array_replace($good,[$key=>$value])]);check(false,'Bad meter provenance must fail');}
    catch(InvalidArgumentException $error){check(true,'Bad meter provenance rejected: '.$key);}
}
foreach(['offline','stale-power','stale-energy','missing-energy','negative-power'] as $fault) {
    $m=dedicatedFixture();
    if($fault==='offline')$GLOBALS['variables'][37456]['value']=false;
    if($fault==='stale-power')$GLOBALS['variables'][28681]['VariableUpdated']=$m->clock-181;
    if($fault==='stale-energy')$GLOBALS['variables'][22365]['VariableUpdated']=$m->clock-181;
    if($fault==='missing-energy')unset($GLOBALS['variables'][22365]);
    if($fault==='negative-power')$GLOBALS['variables'][28681]['value']=-100.0;
    try{$m->StartHeatPumpTest(3);check(false,'Faulty meter must fail start');}catch(RuntimeException $error){check(!$m->values['HeatPumpTestActive'],'Faulty dedicated meter cannot start a test: '.$fault);}
}
foreach(['reset','export'] as $fault) {
    $m=dedicatedFixture();$m->StartHeatPumpTest(3);dedicatedTick($m);
    $GLOBALS['variables'][$fault==='reset'?22365:57639]['value']=$fault==='reset'?0.0:0.5;
    dedicatedTick($m);
    check(!$m->values['HeatPumpTestActive']&&!GetValue(35931),'Meter reset/export stops the test-owned heat source: '.$fault);
    check(!json_decode($m->values['HeatPumpTestResults'],true)['modelQualified'],'Meter fault cannot qualify a model');
}
// Missing archive boundaries must remain excluded, even if instantaneous power is available.
$m=dedicatedFixture();$bad=$good;$bad['counterFinalized']=false;$bad['usable']=false;$bad['meterBoundaries']=[];
foreach([22365,57639] as $id)$GLOBALS['loggedValues'][$id]=[];
$s=['settings'=>['HeatPumpTestMeterSource'=>'dedicated','HeatPumpTestArchiveID'=>9,'HeatPumpTestDedicatedCounterID'=>22365,'HeatPumpTestDedicatedExportID'=>57639,'HeatPumpTestDedicatedCounterScale'=>1.0,'HeatPumpTestDedicatedPowerID'=>28681,'HeatPumpTestAirflowM3h'=>2000],'points'=>[$bad]];
$fn=new ReflectionMethod(HeatingControl::class,'finalizeTestCounterPoints');$fn->invokeArgs($m,[&$s]);
check(!$s['points'][0]['usable']&&isset($s['points'][0]['counterError']),'Missing interval boundaries exclude a point; instantaneous power is not substituted');
// Realistic one-minute energy polling must not be turned into ten-second counter samples.
$m=dedicatedFixture();$m->StartHeatPumpTest(3);
for($i=0;$i<300&&$m->values['HeatPumpTestActive'];$i++)dedicatedTick($m,10,60);
$minute=json_decode($m->values['HeatPumpTestResults'],true);
check($minute['complete']&&$minute['relationships']['usablePointCount']===8,'One-minute import/export polls produce eight matched, usable points');
check(count(array_unique(array_column($minute['points'][0]['meterBoundaries'],'importUpdated')))<count($minute['points'][0]['meterBoundaries']),'Actual counter poll timestamps are retained rather than fabricated at every test tick');

// Planned reboot: preserve completed dedicated steps; a new meter segment may
// have a different counter origin without bridging energy across the shutdown.
date_default_timezone_set('Europe/Berlin');$m=dedicatedFixture();
$m->clock=strtotime('2026-10-15 02:40:00');$GLOBALS['kernelStartTime']=strtotime('2026-10-15 01:00:00');
foreach($GLOBALS['variables'] as &$v)$v['VariableUpdated']=$m->clock;unset($v);
foreach([22365,57639] as $id)$GLOBALS['loggedValues'][$id]=[['TimeStamp'=>$m->clock,'Value'=>GetValue($id)]];
$m->properties['HeatPumpTestRebootGuardEnabled']=true;$m->StartHeatPumpTest(3);
for($i=0;$i<200;$i++){dedicatedTick($m);$paused=json_decode($m->attributes['HeatPumpTestState'],true);if(($paused['phase']??'')==='reboot_wait')break;}
check(($paused['phase']??'')==='reboot_wait'&&count($paused['points'])>0,'Dedicated test stops safely before the scheduled reboot');
for($i=0;$i<9;$i++)dedicatedTick($m);
$paused=json_decode($m->attributes['HeatPumpTestState'],true);$old=$paused['points'];
check($old[0]['counterFinalized']&&$old[0]['usable'],'Completed dedicated steps finalize before the reboot gap');
$m->clock=strtotime('2026-10-15 03:00:10');$GLOBALS['kernelStartTime']=$m->clock;
$GLOBALS['variables'][22365]['value']=1.0;$GLOBALS['variables'][57639]['value']=0.0;
foreach($GLOBALS['variables'] as &$v)$v['VariableUpdated']=$m->clock;unset($v);
foreach([22365,57639] as $id)$GLOBALS['loggedValues'][$id]=[['TimeStamp'=>$m->clock,'Value'=>GetValue($id)]];
$m->ApplyChanges();
for($i=0;$i<400&&$m->values['HeatPumpTestActive'];$i++)dedicatedTick($m);
$reboot=json_decode($m->values['HeatPumpTestResults'],true);
check($reboot['complete']&&count($reboot['points'])===8&&$reboot['relationships']['usablePointCount']===8,'All dedicated steps finish after the planned reboot with a fresh counter segment');
check($reboot['points'][0]===$old[0],'Pre-reboot finalized dedicated measurement remains unchanged');
check(count($reboot['rebootHistory'])===1&&count($reboot['baselines'])===2,'Reboot history and separate meter checks persist');
unset($GLOBALS['kernelStartTime']);
echo "PASS: $checks dedicated-meter provenance, matched-energy, legacy-retention and failure checks\n";

