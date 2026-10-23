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
    $GLOBALS['testHouseBaselineWatts']=180.0;
    $m=heatingFixture(TestHeating::class);$m->properties['HeatPumpTestArchiveID']=9;
    $m->properties['HeatPumpTestRebootGuardEnabled']=false;
    $m->properties['HeatPumpTestMeterSource']='house'; // Explicit legacy fixture; dedicated meter has its own regression suite.
    $GLOBALS['variables'][$m->properties['GasMixerID']]['value']=100;
    $GLOBALS['variables'][$m->properties['FanDesiredSpeedID']]['value']=90;
    $GLOBALS['variables'][$m->properties['HeatPumpTestPowerMeterID']]['value']=100.0;
    $GLOBALS['variables'][$m->properties['HeatPumpTestFanMeterID']]['value']=80.0;
    $GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=15.0;
    $GLOBALS['variables'][13869]['value']=180.0;$GLOBALS['variables'][38023]['value']=100000.0;
    $GLOBALS['loggedValues'][38023]=[['TimeStamp'=>$m->clock-60,'Value'=>100000.0-180*60/3600]];
    return $m;
}
function tick(TestHeating $m,int $seconds=10,?int $heldIncomingUpdated=null):void {
    $energy=$GLOBALS['variables'][13869]['value']*$seconds/3600;
    $GLOBALS['variables'][38023]['value']+=$energy;
    $m->clock+=$seconds;
    $GLOBALS['loggedValues'][38023][]=['TimeStamp'=>$m->clock,'Value'=>$GLOBALS['variables'][38023]['value']];$s=json_decode($m->attributes['HeatPumpTestState']?:'{}',true);
    if(($s['phase']??'')==='step') {
        $power=$s['powers'][$s['index']];
        foreach(['IncomingAirTempID'=>23.0,'OutgoingAirTempID'=>23+$power*0.1,'HeatPumpTempID'=>23+$power*0.24,
            'HeatPumpTestPowerMeterID'=>100+$power*20.0,'HeatPumpTestFanMeterID'=>680.0,'HeatPumpTestHousePowerID'=>($GLOBALS['testHouseBaselineWatts']??180)+$power*20.0+600.0] as $k=>$v)$GLOBALS['variables'][$m->properties[$k]]['value']=$v;
    }
    foreach($GLOBALS['variables'] as &$v)$v['VariableUpdated']=$m->clock;unset($v);
    if($heldIncomingUpdated!==null)$GLOBALS['variables'][$m->properties['IncomingAirTempID']]['VariableUpdated']=$heldIncomingUpdated;
    $m->ProcessHeating();
}

