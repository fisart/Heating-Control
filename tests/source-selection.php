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
    $GLOBALS['instanceIdents'][20]['PriceQuoteJSON']=82000;
    $GLOBALS['variables'][82000]=['VariableType'=>3,'value'=>''];
    foreach(['HeatPumpOnID','HeatPumpPowerID','HeatPumpHeatModeID','GasPumpID','GasMixerID','FanOnID','FanSpeedID','GasFlowTargetID'] as $key) $GLOBALS['variables'][$m->properties[$key]]['VariableAction']=1;
    $GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=12.0;
    $GLOBALS['variables'][$m->properties['FanDesiredSpeedID']]['value']=90;
    plan($m,1,35);return $m;
}
function plan($m,$source,$power=35,$overrides=[]) {
    $point=HeatPumpMatrix::seed()[2];$point['powerPercent']=$power?:35;
    $m->properties['HeatPumpMeasuredMatrix']=json_encode([$point]);
    $quote=array_replace(['schema'=>1,'enabled'=>true,'valid'=>true,'updatedAt'=>$m->clock,'validUntil'=>$m->clock+900,
        'priceCt'=>$source===1?10.0:100.0,'gasPriceCt'=>10.48,'safetyMarginPercent'=>25,'hysteresisCt'=>2],$overrides);
    if($source===0)$quote['valid']=false;
    if(isset($overrides['powerPercent']))$m->properties['HeatPumpMeasuredMatrix']=json_encode([array_replace($point,['powerPercent'=>$overrides['powerPercent']])]);
    if(isset($overrides['source']))$quote['valid']=false;
    $GLOBALS['variables'][82000]['value']=json_encode($quote);
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
$m=fixture();$m->RequestAction('AutomaticSourceSelection',true);
check($m->values['AutomaticSourceSelection']===true,'Instance switch action enables automatic selection');
$m->RequestAction('AutomaticSourceSelection',false);
check($m->values['AutomaticSourceSelection']===false,'Instance switch action disables automatic selection');
$m=fixture();check($m->values['AutomaticSourceSelection']===false,'Default remains manual');run($m);
check(value($m,'GasPumpID')===true && value($m,'HeatPumpOnID')===false,'Manual gas unchanged by cheap recommendation');
$clock=$m->clock;$m->SetAutomaticSourceSelection(true);
check(commanded($m,'GasPumpID',false)&&!commanded($m,'HeatPumpOnID',true),'Gas stop is requested before HP start');
run($m);
check(value($m,'HeatPumpOnID')&&!value($m,'GasPumpID')&&value($m,'HeatPumpPowerID')===35,'HP starts as soon as stop commands are acknowledged');
check($m->clock===$clock,'Source handover requires no elapsed time');
check(value($m,'HeatPumpHeatModeID')===true,'Heat mode commanded');
$commands=$GLOBALS['commands'];check(array_search([$m->properties['HeatPumpPowerID'],35],$commands,true)<array_search([$m->properties['HeatPumpOnID'],true],$commands,true),'Power set before enabling compressor');
check(value($m,'OperatingModeID')===1&&value($m,'HeatPumpDesiredPowerID')===50,'Automatic heat pump mirrored to manual source, desired power preserved');
check($m->values['EffectiveSource']===1&&$m->values['SelectedHeatPumpPower']===35&&$m->values['LearningSourceMode']===1,'Selection and learning outputs');
// A weather-driven power change updates the running HP without a source handover.
plan($m,1,65);run($m);
check(value($m,'HeatPumpOnID')&&value($m,'HeatPumpPowerID')===65&&$m->values['SelectedHeatPumpPower']===65,'Running HP follows a new provider percentage');
check(!commanded($m,'HeatPumpOnID',false)&&!value($m,'GasPumpID'),'Power update does not stop the heat pump or activate gas');
check(value($m,'HeatPumpDesiredPowerID')===50,'Automatic power change does not overwrite manual desired power');
plan($m,2,0);run($m);
check(commanded($m,'HeatPumpOnID',false)&&value($m,'GasPumpID')&&!value($m,'HeatPumpOnID'),'HP stops and gas starts after acknowledgement without a timed pause');
check(value($m,'OperatingModeID')===2,'Gas source mirrored during handover');
// Failed stop command cannot be bypassed by passage of time.
$m=fixture();$m->values['AutomaticSourceSelection']=true;$GLOBALS['variables'][$m->properties['GasPumpID']]['value']=true;
run($m,false);$m->clock+=1000;plan($m,1);run($m,false);check(!commanded($m,'HeatPumpOnID',true),'Unacknowledged gas stop prevents compressor start');
$GLOBALS['variables'][$m->properties['GasPumpID']]['value']=false;
$GLOBALS['variables'][$m->properties['GasMixerID']]['value']=$m->properties['MixerClosed'];run($m);
check(value($m,'HeatPumpOnID'),'A late stop acknowledgement permits immediate HP start');
// Snapshot failures always select gas, never malformed capacity or stale heat-pump commands.
foreach([['valid'=>false],['validUntil'=>2000000000],['updatedAt'=>1999999819],['updatedAt'=>2000000031],['powerPercent'=>0],['powerPercent'=>101],['powerPercent'=>35.5],['source'=>'1'],['schema'=>2]] as $bad) {
 $m=fixture();$m->values['AutomaticSourceSelection']=true;$GLOBALS['variables'][$m->properties['OperatingModeID']]['value']=1;plan($m,1,35,$bad);run($m);
 check(value($m,'OperatingModeID')===2,'Fallback gas mirrored '.json_encode($bad));
 check($m->values['EffectiveSource']===2 && value($m,'GasPumpID')&&!value($m,'HeatPumpOnID'),'Invalid plan gas fallback '.json_encode($bad));
}
$m=fixture();$m->values['AutomaticSourceSelection']=true;$GLOBALS['variables'][82000]['value']='{bad';run($m);check(value($m,'GasPumpID'),'Malformed JSON gas fallback');
$m=fixture();$m->values['AutomaticSourceSelection']=true;$m->properties['ElectricityPriceInstanceID']=0;run($m);check(value($m,'GasPumpID'),'Missing provider gas fallback');
$m=fixture();$m->values['AutomaticSourceSelection']=true;$m->attributes['SourceTransfer']='{bad';run($m);check(value($m,'HeatPumpOnID')&&!value($m,'GasPumpID'),'Old/corrupt transfer state does not add a timer when OFF/CLOSED is acknowledged');
// Mirroring bypasses legacy action scripts, writes only changes and respects permission gates.
$m=fixture();$m->values['AutomaticSourceSelection']=true;
$GLOBALS['variables'][$m->properties['OperatingModeID']]['VariableCustomAction']=123;
run($m);
check(value($m,'OperatingModeID')===1&&!commanded($m,'OperatingModeID',1),'Mode mirror uses direct state write, never legacy source action');
$count=count(array_filter($GLOBALS['directWrites'],fn($write)=>$write[0]===$m->properties['OperatingModeID']));run($m);
check(count(array_filter($GLOBALS['directWrites'],fn($write)=>$write[0]===$m->properties['OperatingModeID']))===$count,'Unchanged source is not rewritten on reevaluation');
$m->SetAutomaticSourceSelection(false);run($m);
check(value($m,'OperatingModeID')===1&&value($m,'HeatPumpOnID')&&value($m,'HeatPumpPowerID')===50,'Automatic off retains last mirrored source with manual power');
$m=fixture();$m->values['AutomaticSourceSelection']=true;$m->properties['HeatPumpModeValue']=7;$m->properties['GasModeValue']=8;
$GLOBALS['variables'][$m->properties['OperatingModeID']]['value']=8;run($m);
check(value($m,'OperatingModeID')===7,'Configured heat-pump mode value mirrored');
plan($m,2,0);run($m);check(value($m,'OperatingModeID')===8,'Configured gas mode value mirrored');
foreach(['DryRun','Disabled','Summer','Master','Night','Cooling'] as $gate) {
 $m=fixture();$m->values['AutomaticSourceSelection']=true;
 if($gate==='DryRun')$m->properties['DryRun']=true;
 elseif($gate==='Disabled')$m->properties['Enabled']=false;
 else {$key=['Summer'=>'WinterID','Master'=>'MasterDisableID','Night'=>'NightDisableID','Cooling'=>'OperatingModeID'][$gate];$GLOBALS['variables'][$m->properties[$key]]['value']=$gate==='Cooling'?0:($gate!=='Summer');}
 $before=value($m,'OperatingModeID');run($m);
 check(value($m,'OperatingModeID')===$before,'Mode mirror respects '.$gate);
}
$m=fixture();$m->values['AutomaticSourceSelection']=true;
$GLOBALS['variables'][$m->properties['OperatingModeID']]['VariableType']=2;
$m->ProcessHeating();
check(str_starts_with($m->values['DecisionStatus'],'ERROR')&&!$GLOBALS['commands']&&!$GLOBALS['directWrites'],'Wrong mode type fails before sending any commands');
// Returning to manual obeys the same handover, then uses unchanged desired capacity.
$m=fixture();$m->values['AutomaticSourceSelection']=true;plan($m,2,0);run($m);
$GLOBALS['variables'][$m->properties['OperatingModeID']]['value']=1;
$m->SetAutomaticSourceSelection(false);run($m);
check(value($m,'HeatPumpOnID')&&value($m,'HeatPumpPowerID')===50,'Manual desired power restored after protected handover');
// Existing shutdowns and no-demand state outrank provider.
foreach(['NightDisableID','OperatingModeID'] as $key) {
 $m=fixture();$m->values['AutomaticSourceSelection']=true;$GLOBALS['variables'][$m->properties[$key]]['value']=$key==='OperatingModeID'?0:true;run($m);
 check(!value($m,'HeatPumpOnID')&&!value($m,'GasPumpID')&&$m->values['EffectiveSource']===0,'Shutdown veto '.$key);
}
$m=fixture();$m->values['AutomaticSourceSelection']=true;foreach(json_decode($m->properties['Rooms'],true) as $room)foreach($room['sensors'] as $id)$GLOBALS['variables'][$id]['value']=24.0;run($m);
check(!value($m,'HeatPumpOnID')&&!value($m,'GasPumpID')&&$m->values['SelectedHeatPumpPower']===0,'No demand no source');
check(value($m,'OperatingModeID')===1,'No demand retains selected heat-pump mode without enabling equipment');
$m=fixture();$m->values['AutomaticSourceSelection']=true;$GLOBALS['variables'][$m->properties['MasterDisableID']]['value']=true;run($m);check(!$GLOBALS['commands'],'Master bypass retains existing behavior');
$m=fixture();$m->values['AutomaticSourceSelection']=true;$m->attributes['HeatPumpStoppedAt']=(string)($m->clock-1000);$m->ApplyChanges();run($m);check(value($m,'HeatPumpOnID'),'Apply/restart does not impose a minimum-off countdown');
// A short demand interruption does not create an HP-to-gas timer.
$m=fixture();$m->values['AutomaticSourceSelection']=true;
$GLOBALS['variables'][$m->properties['HeatPumpOnID']]['value']=true;
foreach(json_decode($m->properties['Rooms'],true) as $room)foreach($room['sensors'] as $id)$GLOBALS['variables'][$id]['value']=24.0;
run($m);$m->clock++;plan($m,2,0);
foreach(json_decode($m->properties['Rooms'],true) as $room)foreach($room['sensors'] as $id)$GLOBALS['variables'][$id]['value']=20.0;
run($m);check(value($m,'GasPumpID'),'Gas starts immediately after an acknowledged idle stop');
// Persisted settings from old installations/exports cannot restore either delay.
$m=fixture();$m->properties['SourceTransferDelaySeconds']=3600;$m->properties['HeatPumpMinimumOffSeconds']=3600;
$m->attributes['HeatPumpStoppedAt']=(string)$m->clock;$m->attributes['SourceTransfer']='{"target":1,"since":0}';
$m->values['AutomaticSourceSelection']=true;run($m);
check(value($m,'HeatPumpOnID'),'Legacy nonzero wait settings are inert');
check(!str_contains($m->values['SourceTransferStatus'],'off wait')&&!str_contains($m->values['SourceTransferStatus'],'transfer wait'),'Status has no protection countdowns');
// Manual source changes retain command acknowledgement without adding a timer.
$m=fixture();$m->attributes['AutomaticWasActive']='1';$GLOBALS['variables'][$m->properties['GasPumpID']]['value']=true;$GLOBALS['variables'][$m->properties['OperatingModeID']]['value']=1;
run($m,false);check(!commanded($m,'HeatPumpOnID',true),'Manual HP change also waits for gas stop acknowledgement');
$GLOBALS['variables'][$m->properties['GasPumpID']]['value']=false;$GLOBALS['variables'][$m->properties['GasMixerID']]['value']=$m->properties['MixerClosed'];run($m);
check(value($m,'HeatPumpOnID'),'Manual HP starts on acknowledgement without elapsed time');
// Provider display uses the same atomic snapshot, with independent price context.
$m=fixture();$GLOBALS['authenticated']=true;
$GLOBALS['instanceProperties'][20]=['WebEnabled'=>true,'MaxCacheAgeHours'=>24,'VaultInstanceID'=>7];
$GLOBALS['instanceProperties'][5]['Hooks']='[{"Hook":"/hook/lichtblick_20","TargetID":20}]';
$display=['CurrentPriceCt'=>[2,25.84],'PriceValid'=>[0,true],'PriceStart'=>[1,$m->clock-60],
 'PriceEnd'=>[1,$m->clock+900],'LastSuccessfulFetch'=>[1,$m->clock],
 'EffectiveThresholdCt'=>[2,27.82],'EntryThresholdCt'=>[2,25.82]];
$i=83000;foreach($display as $ident=>[$type,$v]){
 $GLOBALS['instanceIdents'][20][$ident]=$i;$GLOBALS['variables'][$i]=['VariableType'=>$type,'value'=>$v];$i++;
}
$before=serialize([$m->values,$m->attributes,$GLOBALS['commands'],$GLOBALS['directWrites']]);
$provider=json_decode(response($m,'GET',['view'=>'state'])['body'],true)['sourceSelection']['provider'];
check($provider['connected']&&$provider['valid']&&$provider['source']===0,'Manual page shows a verified price quote without a duplicate provider command');
check($provider['path']==='/hook/lichtblick_20'&&$provider['readings']['CurrentPriceCt']===25.84&&$provider['readings']['PriceValid'],'Provider dashboard link and tariff context');
check(serialize([$m->values,$m->attributes,$GLOBALS['commands'],$GLOBALS['directWrites']])===$before,'Reading provider context never changes heating or settings');
plan($m,1,35,['validUntil'=>$m->clock]);
$provider=json_decode(response($m,'GET',['view'=>'state'])['body'],true)['sourceSelection']['provider'];
check(!$provider['valid']&&$provider['source']===0&&$provider['powerPercent']===0,'Expired provider is not displayed as a valid power command');
plan($m,2,0);$provider=json_decode(response($m,'GET',['view'=>'state'])['body'],true)['sourceSelection']['provider'];
check($provider['valid']&&$provider['source']===0&&$provider['powerPercent']===0,'Verified quote displayed without provider source/power advice');
plan($m,2,65);$provider=json_decode(response($m,'GET',['view'=>'state'])['body'],true)['sourceSelection']['provider'];
check($provider['valid'],'Retired provider power advice does not invalidate a verified electricity quote');
$m->ApplyChanges();check(($m->messages[82000]??0)===VM_UPDATE,'Provider final JSON changes trigger heating evaluation');
$GLOBALS['instanceModules'][20]='{WRONG}';
$provider=json_decode(response($m,'GET',['view'=>'state'])['body'],true)['sourceSelection']['provider'];
check(!$provider['connected']&&!$provider['valid'],'Different module cannot supply heating commands');
// Protected page toggles own Boolean; direct hardware writes remain unavailable.
$m=fixture();$GLOBALS['authenticated']=true;
check(response($m,'POST',[],['control'=>'AutomaticSourceSelection','value'=>1])['status']===422,'Web switch requires strict Boolean');
check(response($m,'POST',[],['control'=>'AutomaticSourceSelection','value'=>true])['status']===200&&$m->values['AutomaticSourceSelection']===true,'Authenticated page enables automatic selection');
check(response($m,'POST',[],['control'=>'OperatingModeID','value'=>1])['status']===422,'Manual source edit locked during automatic selection');
check(response($m,'POST',[],['control'=>'HeatPumpDesiredPowerID','value'=>80])['status']===422,'Manual power edit locked during automatic selection');
check(response($m,'POST',[],['control'=>'AutomaticSourceSelection','value'=>false])['status']===200&&!$m->values['AutomaticSourceSelection'],'Authenticated page returns to manual');
$m->values['AutomaticSourceSelection']=true;$backup=$m->ExportConfig();$m->ImportConfig($backup);check(!$m->values['AutomaticSourceSelection'],'Restore never enables automatic selection');
echo "Automatic source selection: $checks assertions passed.\n";

