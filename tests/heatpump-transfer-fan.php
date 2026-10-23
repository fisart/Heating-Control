<?php
declare(strict_types=1);
require __DIR__.'/source-selection.php';
$startChecks=$checks;
function fanTransferFixture(): TimedHeating
{
    $m=fixture();$m->clock=strtotime('2026-10-23T10:29:43+02:00');
    $m->values['AutomaticSourceSelection']=true;$m->attributes['AutomaticWasActive']='1';
    $m->properties['HeatPumpCOPMethod']='surface';
    foreach(['OperatingModeID'=>1,'HeatPumpOnID'=>false,'GasPumpID'=>false,'FanOnID'=>true,'FanSpeedID'=>90,
        'OutsideTempID'=>9.2,'IncomingAirTempID'=>24.3,'OutgoingAirTempID'=>27.0,'HeatPumpTempID'=>5.0] as $key=>$v)
        $GLOBALS['variables'][$m->properties[$key]]['value']=$v;
    $rooms=json_decode($m->properties['Rooms'],true);
    foreach($rooms as $i=>$room) {
        $GLOBALS['variables'][$room['targetID']]['value']=[23.5,21.0,20.0,23.0][$i];
        foreach($room['sensors'] as $id)$GLOBALS['variables'][$id]['value']=[22.2,21.0,19.8,23.0][$i];
    }
    $m->attributes['SourceTransfer']=json_encode(['target'=>1,'since'=>$m->clock-177]);
    $m->attributes['HeatPumpStoppedAt']=(string)($m->clock-177);
    plan($m,1,34,['priceCt'=>36.09,'safetyMarginPercent'=>5,'hysteresisCt'=>1,'validUntil'=>strtotime('2026-10-23T10:30:00+02:00')]);
    return $m;
}
$m=fanTransferFixture();run($m);
check(value($m,'FanOnID')&&value($m,'FanSpeedID')===90,'Warm outgoing air keeps the fan running through the HP off-time wait');
check(!value($m,'HeatPumpOnID')&&!value($m,'GasPumpID'),'Fan continuity cannot bypass either heat-source protection');
check(str_contains($m->values['SourceTransferStatus'],'heat-pump off wait 123s'),'Original compressor wait remains unchanged');
check(!commanded($m,'FanOnID',false)&&!commanded($m,'FanSpeedID',0),'A source transfer itself sends no fan stop');

// Reproduce the supplied log: expired old quote, then a fresh cheap quote.
$m->clock=strtotime('2026-10-23T10:30:02+02:00');run($m);
check(value($m,'OperatingModeID')===2&&value($m,'FanOnID'),'Gas fallback does not stop warm-air delivery');
$m->clock=strtotime('2026-10-23T10:30:42+02:00');
plan($m,1,34,['priceCt'=>34.94,'safetyMarginPercent'=>5,'hysteresisCt'=>1,'validUntil'=>strtotime('2026-10-23T10:45:00+02:00')]);run($m);
check(value($m,'OperatingModeID')===1&&value($m,'FanOnID'),'Retargeting to HP still retains warm-air delivery');
check(!value($m,'HeatPumpOnID')&&!value($m,'GasPumpID'),'Retargeting retains break-before-make and minimum off time');

$GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value']=23.49;run($m);
check(!value($m,'FanOnID')&&value($m,'FanSpeedID')===0,'Outgoing air below the highest demanding-room target stops the fan');
$GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value']=23.5;run($m);
check(value($m,'FanOnID')&&value($m,'FanSpeedID')===90,'Equality at target is warm enough; no extra margin or coil gate');
check(value($m,'HeatPumpTempID')===5.0,'Fan decision uses measured outgoing air rather than exchanger temperature');
$guest=json_decode($m->properties['Rooms'],true)[1];
$GLOBALS['variables'][$guest['targetID']]['value']=30.0;foreach($guest['sensors'] as $id)$GLOBALS['variables'][$id]['value']=31.0;run($m);
check(value($m,'FanOnID')&&GetValue($guest['flapID'])===$guest['closed'],'A closed room with no demand does not set the shared fan target');

// Once the transfer finishes, normal heating uses exactly the same outlet rule.
$m->clock+=120;plan($m,1,34,['priceCt'=>34.94,'safetyMarginPercent'=>5,'hysteresisCt'=>1]);
// Restore the unused guest target to keep the economic capacity test supported.
$GLOBALS['variables'][$guest['targetID']]['value']=21.0;
run($m);check(value($m,'HeatPumpOnID')&&value($m,'FanOnID'),'Normal HP heating retains the outgoing-air fan rule after protected start');
$GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value']=23.4;run($m);
check(value($m,'HeatPumpOnID')&&!value($m,'FanOnID'),'Cold outgoing air stops the fan without restarting compressor protection');
$GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value']=23.5;run($m);
check(value($m,'HeatPumpOnID')&&value($m,'FanOnID'),'Fan resumes at the target without a new source transfer');

$m=fanTransferFixture();$GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value']=23.4;
$GLOBALS['variables'][$m->properties['HeatPumpTempID']]['value']=50.0;run($m);
check(!value($m,'FanOnID'),'A hot coil cannot authorize below-target outgoing air');
$m=fanTransferFixture();$GLOBALS['variables'][$m->properties['FanOnID']]['value']=false;run($m);
check(value($m,'FanOnID'),'Warm outgoing air can start the fan during a transfer');
echo 'PASS: '.($checks-$startChecks)." fan independence, target threshold and protected source-transfer checks\n";
