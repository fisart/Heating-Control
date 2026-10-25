<?php
declare(strict_types=1);
require __DIR__.'/webhook-fixture.php';

class RolloverHeating extends HeatingControl
{
    public int $clock=2000000000;
    public array $publications=[];
    protected function sourceClock(): int {return $this->clock;}
    public function SetValue($name,$value) {$this->publications[]=[$name,$value];parent::SetValue($name,$value);}
}
function LBEP_EvaluatePrices($instance) {
    $GLOBALS['refreshCalls']=($GLOBALS['refreshCalls']??0)+1;
    if(isset($GLOBALS['refreshQuote']))$GLOBALS['variables'][82000]['value']=json_encode($GLOBALS['refreshQuote']);
    if(!empty($GLOBALS['refreshThrows']))throw new RuntimeException('Fixture refresh busy');
}
$checks=0;
function check($condition,$message) {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function fixture(): RolloverHeating {
    $m=heatingFixture(RolloverHeating::class);
    $m->properties['ElectricityPriceInstanceID']=20;
    $m->properties['HeatPumpMeasuredMatrix']=json_encode(json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-calibration-20261016.json'),true)['points']);
    $GLOBALS['instanceModules'][20]='{5C0EEDE9-5C09-4F18-BCD1-CE65CFA7B1B7}';
    $GLOBALS['instanceIdents'][20]['PriceQuoteJSON']=82000;
    $GLOBALS['variables'][82000]=['VariableType'=>3,'value'=>json_encode(['schema'=>1,'enabled'=>true,'valid'=>true,
        'updatedAt'=>$m->clock,'validUntil'=>$m->clock+30,'priceCt'=>20,'gasPriceCt'=>10.48,'safetyMarginPercent'=>25,'hysteresisCt'=>2])];
    foreach(['OutsideTempID'=>12.0,'IncomingAirTempID'=>23.0,'FanDesiredSpeedID'=>90,'GasMixerID'=>$m->properties['MixerClosed']] as $key=>$value)
        $GLOBALS['variables'][$m->properties[$key]]['value']=$value;
    foreach(json_decode($m->properties['Rooms'],true) as $room)foreach($room['sensors'] as $id)$GLOBALS['variables'][$id]['value']=20.0;
    foreach(['HeatPumpOnID','HeatPumpPowerID','HeatPumpHeatModeID','GasPumpID','GasMixerID','FanOnID','FanSpeedID','GasFlowTargetID'] as $key)$GLOBALS['variables'][$m->properties[$key]]['VariableAction']=1;
    $m->values['AutomaticSourceSelection']=true;
    unset($GLOBALS['refreshQuote'],$GLOBALS['refreshThrows']);$GLOBALS['refreshCalls']=0;
    return $m;
}
function run($m): array {
    $GLOBALS['commands']=[];$m->ProcessHeating();
    check(!str_starts_with($m->values['DecisionStatus'],'ERROR'),'Heating calculation completes');
    foreach($GLOBALS['commands'] as [$id,$value])$GLOBALS['variables'][$id]['value']=$value;
    return json_decode($m->values['HeatPumpEconomicsJSON'],true);
}
function quote(array $changes): void {
    $q=json_decode($GLOBALS['variables'][82000]['value'],true);
    $GLOBALS['variables'][82000]['value']=json_encode(array_replace($q,$changes));
}
$m=fixture();run($m);$m->publications=[];run($m);
foreach($m->publications as [$name,$value])if(in_array($name,['HeatPumpBreakEvenEuro','HeatPumpEntryEuro','HeatPumpExitEuro'],true))check($value>0,'Successful recalculation never publishes intermediate zero thresholds');
check($m->values['EffectiveSource']===1,'Initially selects the heat pump');
$m->clock+=31;$e=run($m);
check($GLOBALS['refreshCalls']===1&&$e['priceHeld']===true&&$e['currentPriceCt']===20,'Expired accepted quote is refreshed locally then retained during a short rollover gap');
check($e['validUntil']===2000000150&&$e['quoteValidUntil']===2000000030,'Hold deadline is anchored to original quote expiry');
check($m->values['EffectiveSource']===1&&!in_array([$m->properties['HeatPumpOnID'],false],$GLOBALS['commands'],true)
    &&!in_array([$m->properties['GasPumpID'],true],$GLOBALS['commands'],true),'Rollover sends neither a heat-pump stop nor a gas-start command');
$compact=json_decode($m->values['HeatPumpDecisionHistoryJSON'],true);
check($compact['priceHeld']===true&&$compact['quoteValidUntil']===2000000030,'Archived decision identifies retained price and original expiry');
$m->clock+=60;$e=run($m);check($e['validUntil']===2000000150,'Repeated evaluations cannot extend the hold');
quote(['priceCt'=>100,'updatedAt'=>$m->clock,'validUntil'=>$m->clock+900]);$e=run($m);
check(!$e['priceHeld']&&$m->values['EffectiveSource']===2,'New expensive quote takes effect immediately, without waiting for hold expiry');
check(in_array([$m->properties['HeatPumpOnID'],false],$GLOBALS['commands'],true),'A genuine expensive price stops the heat pump');

$m=fixture();run($m);$m->clock+=31;
$GLOBALS['refreshQuote']=array_replace(json_decode($GLOBALS['variables'][82000]['value'],true),['priceCt'=>100,'updatedAt'=>$m->clock,'validUntil'=>$m->clock+900]);
$e=run($m);check(!$e['priceHeld']&&$e['currentPriceCt']===100&&$m->values['EffectiveSource']===2,'On-demand evaluation uses the next cached price immediately');
$m=fixture();run($m);$m->clock+=31;$GLOBALS['refreshThrows']=true;$e=run($m);
check($e['priceHeld']&&$m->values['EffectiveSource']===1,'Busy local refresh retains the eligible previous price');
$m=fixture();run($m);$m->clock+=150;$e=run($m);
check(!$e['valid']&&$m->values['EffectiveSource']===2&&$m->values['HeatPumpBreakEvenEuro']===0.0,'Unavailable replacement falls back at the fixed two-minute deadline');
foreach([['valid'=>false],['enabled'=>false],['schema'=>2],['gasPriceCt'=>99],['priceCt'=>21],['hysteresisCt'=>3],['updatedAt'=>1999999800]] as $bad) {
    $m=fixture();run($m);$m->clock+=31;quote($bad);$e=run($m);
    check(!$e['valid']&&$m->values['EffectiveSource']===2,'Invalid or altered expired quotes cannot borrow the previous validation: '.json_encode($bad));
}
$m=fixture();$m->clock+=31;$e=run($m);check(!$e['valid'],'An expired quote never previously accepted is not reused');
$m=fixture();run($m);$m->clock+=31;$m->buffers=[];$e=run($m);check(!$e['valid'],'Restart does not resurrect an old quote');
$m=fixture();run($m);$m->clock+=31;$GLOBALS['variables'][$m->properties['OutsideTempID']]['value']=-21.0;$e=run($m);
check(!$e['valid']&&str_contains($e['reason'],'No qualified point'),'Hold cannot bypass current heat-pump operating limits');
$m=fixture();quote(['priceCt'=>27]);run($m);$m->clock+=31;$e=run($m);
check($e['priceHeld']&&$m->values['EffectiveSource']===2,'Previous gas decision is also retained inside the hysteresis band');
echo "PASS: $checks rollover price, deadline, command continuity and invalidation checks\n";
