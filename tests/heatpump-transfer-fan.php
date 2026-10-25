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
        'OutsideTempID'=>9.2,'IncomingAirTempID'=>24.3,'OutgoingAirTempID'=>27.0,'HeatPumpTempID'=>27.0] as $key=>$v)
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
check(value($m,'FanOnID')&&value($m,'FanSpeedID')===90,'Warm exchanger keeps the fan running at immediate HP start');
check(value($m,'HeatPumpOnID')&&!value($m,'GasPumpID'),'Acknowledged stop permits immediate HP start');
check(str_contains($m->values['SourceTransferStatus'],'no timed delay'),'No compressor/transfer countdown is applied');
check(!commanded($m,'FanOnID',false)&&!commanded($m,'FanSpeedID',0),'A source transfer itself sends no fan stop');

// Reproduce the supplied log without its temporary gas switch at price rollover.
$m->clock=strtotime('2026-10-23T10:30:02+02:00');run($m);
check(value($m,'OperatingModeID')===1&&value($m,'FanOnID')&&value($m,'HeatPumpOnID')&&!value($m,'GasPumpID'),'Previous price keeps heating and airflow running during rollover');
check(!commanded($m,'HeatPumpOnID',false)&&!commanded($m,'GasPumpID',true),'Rollover causes no unnecessary heat-source commands');
$m->clock=strtotime('2026-10-23T10:30:42+02:00');
plan($m,1,34,['priceCt'=>34.94,'safetyMarginPercent'=>5,'hysteresisCt'=>1,'validUntil'=>strtotime('2026-10-23T10:45:00+02:00')]);run($m);
check(value($m,'OperatingModeID')===1&&value($m,'FanOnID'),'Fresh cheap price keeps warm-air delivery running');
check(value($m,'HeatPumpOnID')&&!value($m,'GasPumpID'),'Replacement cheap quote requires no source transfer');

$GLOBALS['variables'][$m->properties['HeatPumpTempID']]['value']=23.49;run($m);
check(!value($m,'FanOnID')&&value($m,'FanSpeedID')===0,'Selected exchanger below the highest demanding-room target stops the fan');
$GLOBALS['variables'][$m->properties['HeatPumpTempID']]['value']=23.5;run($m);
check(value($m,'FanOnID')&&value($m,'FanSpeedID')===90,'Equality at target is warm enough; no extra margin');
check(value($m,'OutgoingAirTempID')===27.0,'Warm outgoing air cannot override a cold exchanger');
$guest=json_decode($m->properties['Rooms'],true)[1];
$GLOBALS['variables'][$guest['targetID']]['value']=30.0;foreach($guest['sensors'] as $id)$GLOBALS['variables'][$id]['value']=31.0;run($m);
check(value($m,'FanOnID')&&GetValue($guest['flapID'])===$guest['closed'],'A closed room with no demand does not set the shared fan target');

// Once the transfer finishes, normal heating uses exactly the same exchanger rule.
$m->clock+=120;plan($m,1,34,['priceCt'=>34.94,'safetyMarginPercent'=>5,'hysteresisCt'=>1]);
// Restore the unused guest target to keep the economic capacity test supported.
$GLOBALS['variables'][$guest['targetID']]['value']=21.0;
run($m);check(value($m,'HeatPumpOnID')&&value($m,'FanOnID'),'Normal HP heating retains the exchanger fan rule after protected start');
$GLOBALS['variables'][$m->properties['HeatPumpTempID']]['value']=23.4;run($m);
check(value($m,'HeatPumpOnID')&&!value($m,'FanOnID'),'Cold exchanger stops the fan without stopping the compressor');
$GLOBALS['variables'][$m->properties['HeatPumpTempID']]['value']=23.5;run($m);
check(value($m,'HeatPumpOnID')&&value($m,'FanOnID'),'Fan resumes at the target without a new source transfer');

// A stopped fan leaves the outgoing sensor cold; a hot selected exchanger starts it.
$m=fanTransferFixture();$GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value']=10.0;
$GLOBALS['variables'][$m->properties['FanOnID']]['value']=false;run($m);
check(value($m,'FanOnID'),'Hot HP exchanger starts the fan despite cold outgoing air during a transfer');
$GLOBALS['variables'][$m->properties['HeatPumpTempID']]['value']=10.0;
$GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value']=50.0;run($m);
check(!value($m,'FanOnID'),'Warm outgoing air cannot keep the fan on with a cold selected HP exchanger');

// The threshold follows runtime target changes, not a fixed value from the log.
$m=fanTransferFixture();$living=json_decode($m->properties['Rooms'],true)[0];
$GLOBALS['variables'][$living['targetID']]['value']=28.0;run($m);
check(!value($m,'FanOnID'),'Raising the demanding-room target above the exchanger stops the fan');
$GLOBALS['variables'][$living['targetID']]['value']=26.0;run($m);
check(value($m,'FanOnID'),'Lowering the current target below the exchanger restarts the fan');

// Gas mode must use its own exchanger, including while both sources are stopped.
$m=fanTransferFixture();plan($m,2,0);
$GLOBALS['variables'][$m->properties['HeatPumpTempID']]['value']=60.0;
$GLOBALS['variables'][$m->properties['HeatExchangerTempID']]['value']=23.49;run($m);
check(!value($m,'FanOnID'),'Cold gas exchanger stops the fan despite hot HP exchanger');
$GLOBALS['variables'][$m->properties['HeatExchangerTempID']]['value']=23.5;
$GLOBALS['variables'][$m->properties['HeatPumpTempID']]['value']=5.0;
$GLOBALS['variables'][$m->properties['OutgoingAirTempID']]['value']=10.0;run($m);
check(value($m,'FanOnID'),'Gas exchanger at target starts the fan despite cold HP and outgoing sensors');
check(value($m,'GasPumpID')&&!value($m,'HeatPumpOnID'),'Gas starts without a timer after HP OFF acknowledgement');
$m->clock+=120;plan($m,2,0);run($m);
check(value($m,'GasPumpID')&&value($m,'FanOnID'),'Normal gas heating uses the same gas-exchanger threshold');
$GLOBALS['variables'][$m->properties['HeatExchangerTempID']]['value']=23.4;run($m);
check(value($m,'GasPumpID')&&!value($m,'FanOnID'),'Cold gas exchanger stops only the fan after transfer');
$m=fanTransferFixture();$m->properties['HeatPumpModeValue']=7;$m->properties['GasModeValue']=8;
$GLOBALS['variables'][$m->properties['OperatingModeID']]['value']=7;
$m->attributes['SourceTransfer']=json_encode(['target'=>1,'since'=>$m->clock-177]);run($m);
check(value($m,'OperatingModeID')===7&&value($m,'FanOnID'),'Configured HP mode selects HP exchanger');
echo 'PASS: '.($checks-$startChecks)." exchanger fan, dynamic target and protected source-transfer checks\n";
