<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';
class TimedHeating extends HeatingControl {
    public int $clock=2000000000;
    protected function sourceClock(): int { return $this->clock; }
}
$checks=0;
function check($ok,$why) {global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function fixture(): TimedHeating {
    $m=heatingFixture(TimedHeating::class);
    foreach(json_decode($m->properties['Rooms'],true) as $room) foreach($room['sensors'] as $id) $GLOBALS['variables'][$id]['value']=20.0;
    $GLOBALS['variables'][$m->properties['GasMixerID']]['value']=$m->properties['MixerClosed'];
    $m->properties['ElectricityPriceInstanceID']=20;
    $GLOBALS['instanceModules'][20]='{5C0EEDE9-5C09-4F18-BCD1-CE65CFA7B1B7}';
    $GLOBALS['instanceIdents'][20]['RecommendationJSON']=82000;
    $GLOBALS['variables'][82000]=['VariableType'=>3,'value'=>''];
    foreach(['HeatPumpOnID','HeatPumpPowerID','HeatPumpHeatModeID','GasPumpID','GasMixerID','FanOnID','FanSpeedID','GasFlowTargetID'] as $key) $GLOBALS['variables'][$m->properties[$key]]['VariableAction']=1;
    plan($m,1,35);return $m;
}
function plan($m,$source,$power=35,$overrides=[]) {
    $GLOBALS['variables'][82000]['value']=json_encode(array_replace(['schema'=>1,'source'=>$source,'powerPercent'=>$power,'valid'=>true,'updatedAt'=>$m->clock,'validUntil'=>$m->clock+900,'reason'=>'Fixture'], $overrides));
}
function run($m,$ack=true) {
    $GLOBALS['commands']=[];$m->ProcessHeating();
    if($ack) {
        foreach($GLOBALS['commands'] as [$id,$v])$GLOBALS['variables'][$id]['value']=$v;
        // A second evaluation observes the simulated command-state update at this clock.
        $before=count($GLOBALS['commands']);$m->ProcessHeating();
        foreach(array_slice($GLOBALS['commands'],$before) as [$id,$v])$GLOBALS['variables'][$id]['value']=$v;
    }
    check(!str_starts_with($m->values['DecisionStatus'],'ERROR'),$m->values['DecisionStatus']);
}
function value($m,$key) {return GetValue($m->properties[$key]);}
function commanded($m,$key,$v) {return in_array([$m->properties[$key],$v],$GLOBALS['commands'],true);}
$m=fixture();check($m->values['AutomaticSourceSelection']===false,'Default remains manual');run($m);
check(value($m,'GasPumpID')===true && value($m,'HeatPumpOnID')===false,'Manual gas unchanged by cheap recommendation');
$m->SetAutomaticSourceSelection(true);run($m);
check(!value($m,'GasPumpID')&&!value($m,'HeatPumpOnID'),'Gas stops before heat-pump start');
check(value($m,'HeatPumpPowerID')===0&&value($m,'GasMixerID')===$m->properties['MixerClosed'],'Stop power and close mixer');
$m->clock+=299;plan($m,1);run($m);check(!value($m,'HeatPumpOnID'),'Heat-pump off time enforced');
$m->clock++;plan($m,1);run($m);check(value($m,'HeatPumpOnID')&&value($m,'HeatPumpPowerID')===35,'Heat pump starts at provider power after delays');
check(value($m,'HeatPumpHeatModeID')===true,'Heat mode commanded');
$commands=$GLOBALS['commands'];check(array_search([$m->properties['HeatPumpPowerID'],35],$commands,true)<array_search([$m->properties['HeatPumpOnID'],true],$commands,true),'Power set before enabling compressor');
check(value($m,'OperatingModeID')===2&&value($m,'HeatPumpDesiredPowerID')===50,'Manual source and desired power preserved');
check($m->values['EffectiveSource']===1&&$m->values['SelectedHeatPumpPower']===35&&$m->values['LearningSourceMode']===1,'Selection and learning outputs');
plan($m,2,0);run($m);check(!value($m,'HeatPumpOnID')&&!value($m,'GasPumpID'),'Heat pump stops before gas');
$m->clock+=119;plan($m,2,0);run($m);check(!value($m,'GasPumpID'),'Transfer clock starts at acknowledged stop, not request');
$m->clock++;plan($m,2,0);run($m);check(value($m,'GasPumpID')&&value($m,'GasMixerID')===$m->properties['MixerOpen'],'Gas starts after acknowledged delay');
// Failed stop command cannot be bypassed by passage of time.
$m=fixture();$m->values['AutomaticSourceSelection']=true;$GLOBALS['variables'][$m->properties['GasPumpID']]['value']=true;
run($m,false);$m->clock+=1000;plan($m,1);run($m,false);check(!commanded($m,'HeatPumpOnID',true),'Unacknowledged gas stop prevents compressor start');
$GLOBALS['variables'][$m->properties['GasPumpID']]['value']=false;
$GLOBALS['variables'][$m->properties['GasMixerID']]['value']=$m->properties['MixerClosed'];run($m);
$m->clock+=119;plan($m,1);run($m);check(!value($m,'HeatPumpOnID'),'Full transfer delay after late stop');
$m->clock++;plan($m,1);run($m);check(value($m,'HeatPumpOnID'),'Start after late command acknowledgment plus delay');
// Snapshot failures always select gas, never malformed capacity or stale heat-pump commands.
foreach([['valid'=>false],['validUntil'=>2000000000],['updatedAt'=>1999999819],['updatedAt'=>2000000031],['powerPercent'=>0],['powerPercent'=>101],['powerPercent'=>35.5],['source'=>'1'],['schema'=>2]] as $bad) {
 $m=fixture();$m->values['AutomaticSourceSelection']=true;plan($m,1,35,$bad);run($m);
 check($m->values['EffectiveSource']===2 && value($m,'GasPumpID')&&!value($m,'HeatPumpOnID'),'Invalid plan gas fallback '.json_encode($bad));
}
$m=fixture();$m->values['AutomaticSourceSelection']=true;$GLOBALS['variables'][82000]['value']='{bad';run($m);check(value($m,'GasPumpID'),'Malformed JSON gas fallback');
$m=fixture();$m->values['AutomaticSourceSelection']=true;$m->properties['ElectricityPriceInstanceID']=0;run($m);check(value($m,'GasPumpID'),'Missing provider gas fallback');
$m=fixture();$m->values['AutomaticSourceSelection']=true;$m->attributes['SourceTransfer']='{bad';run($m);check(!value($m,'HeatPumpOnID')&&!value($m,'GasPumpID'),'Corrupt transfer state waits with both sources off');
// Returning to manual obeys the same handover, then uses unchanged desired capacity.
$m=fixture();$m->values['AutomaticSourceSelection']=true;plan($m,2,0);run($m);
$GLOBALS['variables'][$m->properties['OperatingModeID']]['value']=1;
$m->SetAutomaticSourceSelection(false);run($m);$m->clock+=300;run($m);
check(value($m,'HeatPumpOnID')&&value($m,'HeatPumpPowerID')===50,'Manual desired power restored after protected handover');
// Existing shutdowns and no-demand state outrank provider.
foreach(['NightDisableID','OperatingModeID'] as $key) {
 $m=fixture();$m->values['AutomaticSourceSelection']=true;$GLOBALS['variables'][$m->properties[$key]]['value']=$key==='OperatingModeID'?0:true;run($m);
 check(!value($m,'HeatPumpOnID')&&!value($m,'GasPumpID')&&$m->values['EffectiveSource']===0,'Shutdown veto '.$key);
}
$m=fixture();$m->values['AutomaticSourceSelection']=true;foreach(json_decode($m->properties['Rooms'],true) as $room)foreach($room['sensors'] as $id)$GLOBALS['variables'][$id]['value']=24.0;run($m);
check(!value($m,'HeatPumpOnID')&&!value($m,'GasPumpID')&&$m->values['SelectedHeatPumpPower']===0,'No demand no source');
$m=fixture();$m->values['AutomaticSourceSelection']=true;$GLOBALS['variables'][$m->properties['MasterDisableID']]['value']=true;run($m);check(!$GLOBALS['commands'],'Master bypass retains existing behavior');
$m=fixture();$m->values['AutomaticSourceSelection']=true;$m->attributes['HeatPumpStoppedAt']=(string)($m->clock-1000);$m->ApplyChanges();run($m);check(!value($m,'HeatPumpOnID'),'Restart reobserves full off period');
// A short demand interruption cannot bypass an HP-to-gas stop delay.
$m=fixture();$m->values['AutomaticSourceSelection']=true;
$GLOBALS['variables'][$m->properties['HeatPumpOnID']]['value']=true;
foreach(json_decode($m->properties['Rooms'],true) as $room)foreach($room['sensors'] as $id)$GLOBALS['variables'][$id]['value']=24.0;
run($m);$m->clock++;plan($m,2,0);
foreach(json_decode($m->properties['Rooms'],true) as $room)foreach($room['sensors'] as $id)$GLOBALS['variables'][$id]['value']=20.0;
run($m);check(!value($m,'GasPumpID'),'Brief no-demand interval preserves stop delay');
$m->clock+=119;plan($m,2,0);run($m);check(value($m,'GasPumpID'),'Observed idle stop counts toward delay');
// Protected page toggles own Boolean; direct hardware writes remain unavailable.
$m=fixture();$GLOBALS['authenticated']=true;
check(response($m,'POST',[],['control'=>'AutomaticSourceSelection','value'=>1])['status']===422,'Web switch requires strict Boolean');
check(response($m,'POST',[],['control'=>'AutomaticSourceSelection','value'=>true])['status']===200&&$m->values['AutomaticSourceSelection']===true,'Authenticated page enables automatic selection');
check(response($m,'POST',[],['control'=>'OperatingModeID','value'=>1])['status']===422,'Manual source edit locked during automatic selection');
check(response($m,'POST',[],['control'=>'HeatPumpDesiredPowerID','value'=>80])['status']===422,'Manual power edit locked during automatic selection');
check(response($m,'POST',[],['control'=>'AutomaticSourceSelection','value'=>false])['status']===200&&!$m->values['AutomaticSourceSelection'],'Authenticated page returns to manual');
$m->values['AutomaticSourceSelection']=true;$backup=$m->ExportConfig();$m->ImportConfig($backup);check(!$m->values['AutomaticSourceSelection'],'Restore never enables automatic selection');
echo "Automatic source selection: $checks assertions passed.\n";
