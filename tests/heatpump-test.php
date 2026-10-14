<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
require_once __DIR__.'/../LichtBlickElectricityPrice/WeatherModel.php';
$root=sys_get_temp_dir().'/hc-test-'.bin2hex(random_bytes(6));mkdir($root);
function IPS_GetKernelDir(){return $GLOBALS['root'];}
function AC_SetLoggingStatus($archive,$id,$active){$GLOBALS['archiveLogging'][$archive][$id]=$active;return true;}
class TestHeating extends HeatingControl {public int $clock=2000000000;protected function sourceClock():int{return $this->clock;}}
$checks=0;
function check($ok,$why){global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function fixture():TestHeating {
    $m=heatingFixture(TestHeating::class);$m->properties['HeatPumpTestArchiveID']=9;
    $GLOBALS['variables'][$m->properties['GasMixerID']]['value']=100;
    $GLOBALS['variables'][$m->properties['FanDesiredSpeedID']]['value']=90;
    $GLOBALS['variables'][$m->properties['HeatPumpTestPowerMeterID']]['value']=100.0;
    $GLOBALS['variables'][$m->properties['HeatPumpTestFanMeterID']]['value']=80.0;
    $GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=15.0;
    return $m;
}
function tick(TestHeating $m,int $seconds=10,?int $heldIncomingUpdated=null):void {
    $m->clock+=$seconds;$s=json_decode($m->attributes['HeatPumpTestState']?:'{}',true);
    if(($s['phase']??'')==='step') {
        $power=$s['powers'][$s['index']];
        foreach(['IncomingAirTempID'=>23.0,'OutgoingAirTempID'=>23+$power*0.1,'HeatPumpTempID'=>23+$power*0.24,
            'HeatPumpTestPowerMeterID'=>100+$power*20.0,'HeatPumpTestFanMeterID'=>680.0] as $k=>$v)$GLOBALS['variables'][$m->properties[$k]]['value']=$v;
    }
    foreach($GLOBALS['variables'] as &$v)$v['VariableUpdated']=$m->clock;unset($v);
    if($heldIncomingUpdated!==null)$GLOBALS['variables'][$m->properties['IncomingAirTempID']]['VariableUpdated']=$heldIncomingUpdated;
    $m->ProcessHeating();
}
$samples=[];for($t=0;$t<=300;$t+=10)$samples[]=['timestamp'=>$t,'outside'=>15,'incoming'=>23,'outgoing'=>33,'coil'=>45,'hpWatts'=>2100,'fanWatts'=>680,'target'=>23];
$p=HeatPumpPerformance::point($samples,['hpWatts'=>100,'fanWatts'=>80],2000,60,300);
check($p['usable']&&abs($p['estimatedHeatKW']-6.7)<0.001,'Airflow and temperature rise give estimated heat output');
check(abs($p['estimatedSystemCOP']-6.7/2.78)<0.001,'System COP includes gross fan/shared circuit electricity');
check(abs($p['heatPumpKW']-2.0)<0.001,'HP baseline excluded from incremental HP input');
$changed=$samples;foreach($changed as $i=>&$r)$r['outgoing']+=2*$i;unset($r);
check(!HeatPumpPerformance::point($changed,['hpWatts'=>100,'fanWatts'=>80],2000,60,300)['usable'],'Unsettled thermal data excluded');
check(HeatPumpPerformance::choose([$p],15,23,40,23,5,5)!==null,'Qualified occupied point chosen');
check(HeatPumpPerformance::choose([$p],-5,23,40,23,5,5)===null,'No unmeasured weather extrapolation');
check(HeatPumpPerformance::choose([$p],15,10,20,10,5,5)===null,'Warm-intake test cannot claim cold holiday COP');
$m=fixture();$m->properties['DryRun']=true;try{$m->StartHeatPumpTest();check(false,'Dry run must refuse');}catch(RuntimeException $e){check(!$m->values['HeatPumpTestActive'],'Dry run cannot energise test');}
$m=fixture();$m->properties['HeatPumpTestExcludedFlapID']=999;try{$m->StartHeatPumpTest();check(false,'Missing exclusion must refuse');}catch(RuntimeException $e){check(!$m->values['HeatPumpTestActive'],'Master-bedroom exclusion must match configured room');}
$m=fixture();$m->StartHeatPumpTest(3);
check($m->values['HeatPumpTestActive'],'Form/API starts test');
check(AC_GetLoggingStatus(9,15315)&&AC_GetLoggingStatus(9,35702),'Start enables missing selected-variable logging');
for($i=0;$i<300&&$m->values['HeatPumpTestActive'];$i++) {
    tick($m);
    if($m->values['HeatPumpTestActive'])check(GetValue(40259)===false,'Master bedroom stays closed throughout every step');
}
check(!$m->values['HeatPumpTestActive'],'Eight-step test finishes without blocking or sleeps');
$report=json_decode($m->values['HeatPumpTestResults'],true);
check($report['complete']&&count($report['points'])===8,'All eight step summaries saved');
check(array_column($report['points'],'powerPercent')===[30,40,50,60,70,80,90,100],'Ascending sequence exact');
check($report['relationships']['usablePointCount']===8,'Stable points become local relationships');
check(is_file($root.'/media/heatpump-tests/1/'.$report['runID'].'.jsonl'),'Raw samples persist');
check(is_file($root.'/media/heatpump-tests/1/model.json'),'Model persists separately from transient state');
check(GetValue(40259)===false&&GetValue(36911)===100,'Previous flap positions restored');
check(!GetValue(35931)&&!GetValue(46921),'Sources and fan stopped before normal controller resumes');
check(GetValue(59800)===50&&GetValue(48646)===90,'Manual desired power/fan settings retained');
$m=fixture();$m->StartHeatPumpTest(15);
for($i=0;$i<100;$i++){tick($m);$s=json_decode($m->attributes['HeatPumpTestState'],true);if(($s['phase']??'')==='step')break;}
$heldIncomingUpdated=$m->clock;
for($i=0;$i<100&&$m->values['HeatPumpTestActive'];$i++)tick($m,10,$heldIncomingUpdated);
$s=json_decode($m->attributes['HeatPumpTestState'],true);
check($m->values['HeatPumpTestActive']&&$s['index']===1,'Default age policy allows held intake beyond 15 minutes during the 40% step');
$last=$s['window'][count($s['window'])-1];
check($last['sensorUpdated']['IncomingAirTempID']===$heldIncomingUpdated&&$last['sensorAgeSeconds']['IncomingAirTempID']>900,'Raw readings retain held intake timestamp and age');
check($s['points'][0]['maximumSensorAgeSeconds']['IncomingAirTempID']>=900,'Completed point retains maximum temperature age');
$m->CancelHeatPumpTest();
check(json_decode($m->values['HeatPumpTestResults'],true)['temperatureMaxAgeSeconds']===0,'Summary records disabled age limit rather than implying fresh telemetry');
$m=fixture();$m->properties['MaxSensorAgeSeconds']=900;$m->StartHeatPumpTest(15);
for($i=0;$i<100;$i++){tick($m);$s=json_decode($m->attributes['HeatPumpTestState'],true);if(($s['phase']??'')==='step')break;}
$heldIncomingUpdated=$m->clock;
for($i=0;$i<100&&$m->values['HeatPumpTestActive'];$i++)tick($m,10,$heldIncomingUpdated);
check(!$m->values['HeatPumpTestActive']&&str_contains($m->values['HeatPumpTestStatus'],'Test sensor stale: IncomingAirTempID'),'Configured positive sensor age limit still stops a held input');
check(json_decode($m->values['HeatPumpTestResults'],true)['temperatureMaxAgeSeconds']===900,'Summary records configured temperature age cutoff');
$m=fixture();$m->StartHeatPumpTest(3);tick($m);$m->CancelHeatPumpTest();
check(!$m->values['HeatPumpTestActive']&&!json_decode($m->values['HeatPumpTestResults'],true)['complete'],'Cancellation stores partial run without learning');
$m=fixture();$m->StartHeatPumpTest(3);$m->properties['Enabled']=false;$m->ProcessHeating();
check(!$m->values['HeatPumpTestActive']&&!GetValue(35931),'Disabling module still stops test-owned heat');
$m=fixture();$m->StartHeatPumpTest(3);$GLOBALS['variables'][35931]['value']=true;$GLOBALS['variables'][46921]['value']=true;$m->properties['DryRun']=true;$m->ProcessHeating();
check(!$m->values['HeatPumpTestActive']&&!GetValue(35931)&&!GetValue(46921),'New dry-run permission cannot leave test-owned heat/fan running');
$m=fixture();$m->StartHeatPumpTest(3);$GLOBALS['variables'][35931]['value']=true;$GLOBALS['variables'][35931]['VariableAction']=1;$m->CancelHeatPumpTest();
check($m->values['HeatPumpTestActive'],'Unacknowledged heat stop retains exclusive ownership');
$GLOBALS['variables'][35931]['value']=false;tick($m);check(!$m->values['HeatPumpTestActive'],'Acknowledged stop releases ownership');
$m=fixture();$m->StartHeatPumpTest(3);$m->ApplyChanges();
check(!$m->values['HeatPumpTestActive'],'Apply/restart never resumes an experiment');
$m=fixture();$m->StartHeatPumpTest(3);$GLOBALS['variables'][57694]['value']=28.0;tick($m);
check(!$m->values['HeatPumpTestActive']&&str_contains($m->values['HeatPumpTestStatus'],'Room temperature limit'),'Room limit cancels test');
$m=fixture();$m->StartHeatPumpTest(3);
for($i=0;$i<150;$i++){tick($m);$s=json_decode($m->attributes['HeatPumpTestState'],true);if(count($s['points']??[])>=2)break;}
$GLOBALS['variables'][57694]['value']=28.0;tick($m);
$cutoff=json_decode($m->values['HeatPumpTestResults'],true);
check(!$cutoff['complete']&&$cutoff['modelQualified']&&count($cutoff['points'])>=2,'Temperature-limited run retains qualified completed steps without claiming all eight');
$m=fixture();$m->StartHeatPumpTest(3);tick($m,100);
check(!$m->values['HeatPumpTestActive'],'Timer interruption rejects unattended experiment');
$m=fixture();$GLOBALS['authenticated']=false;
check(response($m,'POST',[],['control'=>'HeatPumpTestStart','value'=>true])['status']===401,'Test start retains passkey gate');
$GLOBALS['authenticated']=true;
check(response($m,'POST',[],['control'=>'HeatPumpTestStart','value'=>true],['HTTP_X_HEATING_CSRF'=>'bad'])['status']===403,'Test start retains CSRF gate');
check(response($m,'POST',[],['control'=>'HeatPumpTestMinutes','value'=>10])['status']===200&&$m->properties['HeatPumpTestStepMinutes']===10,'Protected page saves duration');
check(response($m,'POST',[],['control'=>'HeatPumpTestStart','value'=>true])['status']===200,'Protected page starts test');
check(response($m,'POST',[],['control'=>'HeatPumpDesiredPowerID','value'=>80])['status']===422,'Normal edits blocked while test owns outputs');
check(response($m,'POST',[],['control'=>'HeatPumpTestCancel','value'=>true])['status']===200,'Protected page cancels test');
$GLOBALS['authenticated']=false;
check(response($m,'GET',['view'=>'heatpump-test-readings'])['status']===401,'Raw readings cannot bypass authentication');
$m=fixture();$req=webInvoke($m,'heatPumpRequirement',webInvoke($m,'rooms'));
check($req['coilMinimum']===40.0,'Occupied exchanger minimum is 40C');
$GLOBALS['variables'][46970]['value']=true;foreach([12594,40975,30481,37341] as $id)$GLOBALS['variables'][$id]['value']=10.0;
check(webInvoke($m,'heatPumpRequirement',webInvoke($m,'rooms'))['coilMinimum']===20.0,'Holiday 10C targets permit 20C exchanger');
$m=fixture();$m->properties['ElectricityPriceInstanceID']=20;
$point=$p;$point['powerPercent']=80;$point['outside']=15;$point['incoming']=23;$point['airRise']=10;$point['coilLift']=22;
$model=['schema'=>1,'createdAt'=>$m->clock,'fanSpeed'=>90,'roomSignature'=>hash('sha256',json_encode(webInvoke($m,'rooms'))),'points'=>[$point]];
$m->attributes['HeatPumpPerformanceModel']=json_encode($model);
$GLOBALS['instanceProperties'][20]=['WeatherCurve'=>LichtBlickWeatherModel::DEFAULT_CURVE,'WeatherReferenceTemperatureC'=>2.0,'WeatherPowerStepPercent'=>5,'WeatherFanKW'=>0.6,'GasPriceCt'=>10.48,'WeatherReferenceGasPriceCt'=>10.48,'SafetyMarginPercent'=>25.0,'HysteresisCt'=>2.0];
foreach(['PriceValid'=>true,'CurrentPriceCt'=>10.0,'EffectiveThresholdCt'=>22.5,'ReferenceThresholdCt'=>30.0] as $ident=>$v){$id=83000+count($GLOBALS['instanceIdents'][20]??[]);$GLOBALS['instanceIdents'][20][$ident]=$id;$GLOBALS['variables'][$id]=['value'=>$v,'VariableType'=>is_bool($v)?0:2];}
$reason='Test';$method=new ReflectionMethod(HeatingControl::class,'measuredPowerChoice');
check($method->invokeArgs($m,[35,&$reason])===80,'Qualified model can raise power after conservative price recheck');
$GLOBALS['variables'][$GLOBALS['instanceIdents'][20]['CurrentPriceCt']]['value']=40.0;$reason='Test';
check($method->invokeArgs($m,[35,&$reason])===0,'Expensive higher-power point uses gas instead of old cheap threshold');
echo "PASS: $checks heat-pump test/model checks\n";
