<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';

function IPS_GetInstanceListByModuleID($module) {return array_keys(array_filter($GLOBALS['heatingControllers']??[],static fn($p)=>$p['module']===$module));}
function IPS_GetProperty($id,$name) {return $GLOBALS['heatingControllers'][$id][$name]??0;}
$checks=0;
function verify(bool $condition,string $message):void {global $checks;++$checks;check($condition,$message);}
$z=new DateTimeZone('Europe/Berlin');$at=static fn(string $s):int=>(new DateTimeImmutable($s,$z))->getTimestamp();
$a=$at('2026-10-20');$b=$at('2026-10-21');
$states=['heatPumpOn'=>[$a-3600=>false,$a+3600=>true,$a+8*3600=>false],
    'heatPumpHeating'=>[$a-7200=>true], 'gasAirPump'=>[$a-100=>false,$a+10*3600=>true,$a+13*3600=>false]];
$usage=LichtBlickDailyHeatingUsage::analyse($a,$b,$states);
verify($usage['heatPumpSeconds']===7*3600&&$usage['gasSeconds']===3*3600,'On-state intervals, rather than event counts or 24-hour fractions, determine usage.');
verify($usage['heatPumpPercent']===29.2&&$usage['gasPercent']===12.5&&$usage['idlePercent']===58.3&&$usage['idleSeconds']===14*3600,'Shares include idle time and add to 100.');
$constant=['heatPumpOn'=>[$a=>false],'heatPumpHeating'=>[$a=>true],'gasAirPump'=>[$a=>true]];
$gas=LichtBlickDailyHeatingUsage::analyse($a,$b,$constant);verify($gas['gasPercent']===100.&&$gas['heatPumpPercent']===0.,'One-source days show 100 / 0.');
$constant['gasAirPump'][$a]=false;$idle=LichtBlickDailyHeatingUsage::analyse($a,$b,$constant);
verify($idle['available']&&$idle['heatPumpPercent']===0.&&$idle['gasPercent']===0.&&$idle['idlePercent']===100.&&$idle['idleSeconds']===86400,'An all-idle day shows 0% / 0% / 100% without dividing by zero.');
$constant['heatPumpOn'][$a]=true;$constant['heatPumpHeating'][$a]=false;
verify(LichtBlickDailyHeatingUsage::analyse($a,$b,$constant)['totalHeatingSeconds']===0,'Heat-pump cooling is excluded.');
$constant['heatPumpHeating'][$a]=true;$constant['gasAirPump'][$a]=true;
$overlap=LichtBlickDailyHeatingUsage::analyse($a,$b,$constant);verify(!$overlap['available']&&$overlap['heatPumpPercent']===null&&$overlap['overlapSeconds']===86400,'Conflicting simultaneous control states cannot certify a split.');
$bad=$states;$bad['heatPumpOn']=[$a+1=>false];throws(fn()=>LichtBlickDailyHeatingUsage::analyse($a,$b,$bad),'Missing midnight baseline must fail.');
foreach(['2026-03-29'=>23,'2026-10-25'=>25] as $date=>$hours) {
    [$from,$to]=LichtBlickPriceStore::dates($date,$date,1);
    $s=['heatPumpOn'=>[$from-3600=>true,$to=>false],'heatPumpHeating'=>[$from-3600=>true],'gasAirPump'=>[$from-3600=>false]];
    $u=LichtBlickDailyHeatingUsage::analyse($from,$to,$s);verify($u['heatPumpSeconds']===$hours*3600&&$u['heatPumpPercent']===100.,'Berlin DST calendar boundaries cover the correct number of hours and exclude next midnight.');
}
$thirds=['heatPumpOn'=>[$a=>true,$a+28800=>false],'heatPumpHeating'=>[$a=>true],'gasAirPump'=>[$a=>false,$a+28800=>true,$a+57600=>false]];
$thirds=LichtBlickDailyHeatingUsage::analyse($a,$b,$thirds);
verify(abs($thirds['heatPumpPercent']+$thirds['gasPercent']+$thirds['idlePercent']-100.)<1e-9,'Rounded percentages sum to exactly 100.0%, including equal thirds.');
$ids=['heatPumpOn'=>101,'heatPumpHeating'=>102,'gasAirPump'=>103];
foreach($ids as $key=>$id){$GLOBALS['liveVariables'][$id]=['VariableType'=>0,'value'=>true];$GLOBALS['history'][$id]=[];foreach($states[$key] as $t=>$v)$GLOBALS['history'][$id][]=['TimeStamp'=>$t,'Value'=>$v?1.:0.];}
$GLOBALS['history'][101][]=['TimeStamp'=>$b,'Value'=>1];
$before=serialize([$GLOBALS['history'],$GLOBALS['liveVariables']]);$actual=LichtBlickDailyHeatingUsage::read(14817,$ids,$a,$b);
verify($actual===$usage,'Archive integration includes prior boundary, excludes next day and ignores misleading current values.');
verify($before===serialize([$GLOBALS['history'],$GLOBALS['liveVariables']]),'Daily usage never mutates archives or live states.');
$GLOBALS['unlogged'][102]=true;throws(fn()=>LichtBlickDailyHeatingUsage::read(14817,$ids,$a,$b),'Unlogged states must be unavailable.');unset($GLOBALS['unlogged'][102]);
array_unshift($GLOBALS['history'][101],['TimeStamp'=>$a+3600,'Value'=>0]);
verify(LichtBlickDailyHeatingUsage::read(14817,$ids,$a,$b)['heatPumpSeconds']===0,'First returned same-second event is used.');array_shift($GLOBALS['history'][101]);
array_unshift($GLOBALS['history'][103],['TimeStamp'=>$a-100,'Value'=>1]);
verify(LichtBlickDailyHeatingUsage::read(14817,$ids,$a,$b)['gasSeconds']===6*3600,'First returned same-second baseline is used.');array_shift($GLOBALS['history'][103]);
$GLOBALS['history'][102][]=['TimeStamp'=>$a+2,'Value'=>2];throws(fn()=>LichtBlickDailyHeatingUsage::read(14817,$ids,$a,$b),'Invalid Boolean state must be unavailable.');array_pop($GLOBALS['history'][102]);
$GLOBALS['history'][102][0]['Value']='1.0';$GLOBALS['history'][103][0]['Value']='0.0';verify(LichtBlickDailyHeatingUsage::read(14817,$ids,$a,$b)['heatPumpPercent']===29.2,'Numeric Boolean strings retain their numeric meaning.');
$GLOBALS['history'][102]=[];throws(fn()=>LichtBlickDailyHeatingUsage::read(14817,$ids,$a,$b),'No baseline is not measured zero.');$GLOBALS['history'][102]=[['TimeStamp'=>$a-7200,'Value'=>1]];
class FixtureHeatingDailyReport extends FixturePrices {
    public int $now;
    protected function consumptionReportNow():int {return $this->now;}
    public function GetTotalConsumption(string $from,string $through,string $resolution='auto'):string {
        $s=['knownCostEuro'=>1.,'knownVolume'=>1.,'unit'=>'kWh','incompleteCostDays'=>0,'incompleteVolumeDays'=>0,'provisionalDays'=>0];
        $streams=array_fill_keys(['electricity','gas','house','garden','well'],$s);foreach(['house','garden','well'] as $key)$streams[$key]['unit']='m³';return json_encode(['streams'=>$streams],JSON_THROW_ON_ERROR);
    }
}
$m=new FixtureHeatingDailyReport();$m->now=$b+3600;$m->Create();$GLOBALS['instance']=$m;
$controller=['module'=>'{8B333AC9-EE67-4D24-8CE7-433BBB391E6C}','ElectricityPriceInstanceID'=>1,'HeatPumpOnID'=>101,'HeatPumpHeatModeID'=>102,'GasPumpID'=>103];
$GLOBALS['heatingControllers']=[5001=>$controller];
$before=serialize([$m->properties,$m->attributes,$m->values,$GLOBALS['history'],$GLOBALS['liveVariables']]);$html=$m->GetDailyConsumptionReport('2026-10-20');$happyHTML=$html;
verify(str_contains($html,'29,2 %')&&str_contains($html,'12,5 %')&&str_contains($html,'7 h 00 min')&&str_contains($html,'3 h 00 min')&&str_contains($html,'14 h 00 min')&&str_contains($html,'58,3 %'),'The actual daily email displays the selected day’s hours and percentages.');
verify(str_contains($html,'control on/off states')&&str_contains($html,'radiators and hot water'),'The report identifies the operating-state basis and gas air-heating scope.');
verify($before===serialize([$m->properties,$m->attributes,$m->values,$GLOBALS['history'],$GLOBALS['liveVariables']]),'Report preview remains read-only.');
$GLOBALS['heatingControllers'][5002]=$controller;$html=$m->GetDailyConsumptionReport('2026-10-20');
verify(str_contains($html,'Several heating controllers')&&!str_contains($html,'29,2 %')&&str_contains($html,'Total calculated cost'),'Ambiguous sources affect the heating section, not the rest of the report.');unset($GLOBALS['heatingControllers'][5002]);
$GLOBALS['heatingControllers'][5001]['ElectricityPriceInstanceID']=999;$html=$m->GetDailyConsumptionReport('2026-10-20');
verify(str_contains($html,'No Heating Control instance')&&!str_contains($html,'29,2 %'),'An unrelated controller is never guessed.');
// Force the capped reader path: the implementation must split a saturated batch.
$GLOBALS['archiveReader']=static function($archive,$id,$from,$to,$limit)use($a,$b) {
    if($limit===1)return [['TimeStamp'=>$a-1,'Value'=>0]];
    if($from===$a&&$to===$b-1)return array_fill(0,10000,['TimeStamp'=>$a,'Value'=>0]);
    return [['TimeStamp'=>$from,'Value'=>0]];
};
verify(LichtBlickDailyHeatingUsage::read(14817,$ids,$a,$b)['totalHeatingSeconds']===0,'Saturated daily queries are split, not silently truncated.');unset($GLOBALS['archiveReader']);
file_put_contents('/tmp/lichtblick-heating-usage-report.html',$happyHTML);
echo 'Daily heating usage: '.$checks." assertions passed; calendar-day runtime, archives, incomplete data and report integration verified.\n";
