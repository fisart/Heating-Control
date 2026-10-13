<?php
declare(strict_types=1);
require __DIR__.'/lichtblick-fixture.php';
$zone=new DateTimeZone('Europe/Berlin');$from=(new DateTimeImmutable('2024-01-01',$zone))->getTimestamp();$to=(new DateTimeImmutable('2025-01-01',$zone))->getTimestamp();
$GLOBALS['history'][9001]=$GLOBALS['history'][9002]=[];$queries=0;
// Produce provider batches on demand rather than preloading the fixture itself.
$GLOBALS['archiveReader']=static function($archive,$id,$a,$b,$limit)use($from,$to,&$queries){
    $queries++;$rows=[];$first=max($from-60,(int)(ceil($a/60)*60));$last=min($to-60,(int)(floor($b/60)*60));
    for($t=$last;$t>=$first && count($rows)<$limit;$t-=60)$rows[]=['TimeStamp'=>$t,'Value'=>$id===9002?1000:1000+($t-$from)/60];
    return $rows;
};
$m=newModule();$m->properties['WaterHistoryEnabled']=true;$m->properties['WaterHistoryHouseIncludesGarden']=true;
$m->properties['WaterHistorySources']=json_encode(['house'=>['counterID'=>9001,'scaleM3'=>.001,'source'=>'Dense fixture main'], 'well'=>['counterID'=>9002,'scaleM3'=>.001,'source'=>'Dense fixture well']]);
$m->properties['WaterHistoryGardenPeriods']=json_encode([['from'=>'2024-01-01','through'=>'2024-12-31','m3'=>10.,'source'=>'Fixture manual garden year']]);
$m->properties['ConsumptionZeroPeriods']=json_encode([['from'=>'2024-01-04','through'=>'2024-01-06','utilities'=>['water'],'source'=>'Fixture outage']]);
$expected=($to-$from)/60*.001-3*1.44;
$water=json_decode($m->GetWaterHistory('2024-01-01','2024-12-31','selected'),true,64,JSON_THROW_ON_ERROR);
check(abs($water['streams']['house']['totals']['knownMainMeterM3']-$expected)<1e-7,'Full-year dense main events retain exact deltas and confirmed-zero rule');
check(abs($water['streams']['house']['totals']['knownM3']-($expected-10.))<1e-7 && $water['streams']['garden']['totals']['knownM3']==10.,'Full-year manual garden split stays exact');
check($water['streams']['well']['totals']['knownM3']==0. && $water['streams']['well']['totals']['knownCostEuro']==0.,'Dense unchanged well events remain zero volume and cost');
unset($water);
$total=json_decode($m->GetTotalConsumption('2024-01-01','2024-12-31','monthly'),true,64,JSON_THROW_ON_ERROR);
check(abs($total['totals']['knownWaterM3']-$expected)<1e-7 && count($total['points'])===12,'Total-consumption year uses bounded raw reads and monthly buckets');
check(abs($total['unallocatedManualVolumeM3'])<1e-7,'Annual garden reference is not added twice to the electronic main-water chart');
check(memory_get_peak_usage(true)<32*1048576,'Dense year stays below 32 MiB rather than retaining every raw event');
unset($GLOBALS['archiveReader']);
$GLOBALS['history'][9001]=[['TimeStamp'=>10,'Value'=>1],['TimeStamp'=>10,'Value'=>1],['TimeStamp'=>11,'Value'=>2]];
$reader=new LichtBlickConsumptionHistory(14817,[]);
check(iterator_to_array($reader->events(9001,0,20))===[10=>1.,11=>2.],'Streaming deduplicates equal same-second events');
$GLOBALS['history'][9001][]=['TimeStamp'=>10,'Value'=>3];
throws(fn()=>iterator_to_array($reader->events(9001,0,20)),'Conflicting same-second events still rejected');
$GLOBALS['saturated']=true;throws(fn()=>iterator_to_array($reader->events(9001,10,10)),'Saturated one-second range still rejected');unset($GLOBALS['saturated']);
echo 'Dense water year and total consumption passed with '.round(memory_get_peak_usage(true)/1048576,1).' MiB peak memory; '.$queries." bounded archive queries.\n";
