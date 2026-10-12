<?php
declare(strict_types=1);
require __DIR__ . '/lichtblick-fixture.php';

$from=(new DateTimeImmutable('2026-10-01',new DateTimeZone('Europe/Berlin')))->getTimestamp();
$to=$from+86400; $dir=sys_get_temp_dir().'/lbep-history-'.bin2hex(random_bytes(6));
$store=new LichtBlickPriceStore($dir);$id=LichtBlickPriceStore::identity('14167',0.0);
$tariffs=LichtBlickPriceStore::tariffs('[{"from":"2026-10-01","postcode":"14167","adderNetCt":15,"vatPercent":19,"verified":true}]');
try {
    $market=json_encode(['series'=>[[$from*1000,-50],[($from+900)*1000,100],[($from+1800)*1000,null],[($from+2700)*1000,200]]]);
    $slots=LichtBlickPriceStore::market($market,$from,$to);check(count($slots)===3 && $slots[0]['marketCt']===-5.0,'Negative prices and null gaps preserved');
    $rows=LichtBlickPriceStore::reconstruct($slots,$tariffs,'14167',time());check(abs($rows[0]['price']-11.9)<1e-9,'Net adder and VAT exactly once');
    check($rows[0]['source']==='smard-reconstructed'&&$rows[0]['verified'],'Reconstruction provenance');
    $unknown=LichtBlickPriceStore::reconstruct($slots,[],'14167',time());check($unknown[0]['price']===null&&!$unknown[0]['verified'],'Missing tariffs are not zero/free retail prices');
    check(LichtBlickPriceStore::reconstruct($slots,$tariffs,'20099',time())[0]['price']===null,'Wrong postcode cannot use historic tariff');
    $gap=json_encode(['series'=>[[$from*1000,100],[($from+900)*1000,100],[($from+4500)*1000,100],[($from+5400)*1000,100]]]);
    check(count(LichtBlickPriceStore::market($gap,$from,$to))===3,'A missing hour in quarter-hour data is not expanded as hourly data');
    check($store->merge($id,$rows)===3&&$store->merge($id,$rows)===0,'Repeat imports do not duplicate prices');
    $quote=$rows[0];$quote['price']=12.25;$quote['source']='lichtblick-quote';$quote['fetchedAt']=time();
    $store->merge($id,[$quote]);$store->merge($id,$rows);check($store->query($id,$from,$to)[0]['price']===12.25,'Direct quotes take precedence');
    $unconfirmed=$quote;$unconfirmed['verified']=false;$unconfirmed['price']=11; $store->merge($id,[$unconfirmed]);
    check($store->query($id,$from,$to)[0]['verified'],'Unconfirmed refresh cannot downgrade confirmed history');
    $newStore=new LichtBlickPriceStore($dir);check(count($newStore->query($id,$from,$to))===3,'History survives a new store process/object');
    $other=LichtBlickPriceStore::identity('14167',1.0);$store->merge($other,[$quote]);
    check(count($store->query($id,$from,$to))===3&&count($store->query($other,$from,$to))===1,'Changed correction isolated without deleting prior identity');
    [$a,$b]=LichtBlickPriceStore::dates('2026-10-25','2026-10-25');check(($b-$a)/900===100,'Autumn DST has 100 quarter-hours');
    $chart=LichtBlickPriceStore::chart($store->query($id,$from,$to),$from,$to);
    check($chart['missingIntervals']===93&&$chart['confirmedIntervals']===3,'Coverage reports gaps and confirmed retail separately');
    $daily=LichtBlickPriceStore::chart($rows,$from,$from+9*86400);check($daily['resolution']==='daily mean'&&count($daily['points'])===1&&$daily['points'][0]['retailIntervals']===3,'Long charts bounded, incomplete day labelled');
    throws(fn()=>LichtBlickPriceStore::dates('2026-02-30','2026-03-01'),'Invalid date normalized silently');
    throws(fn()=>LichtBlickPriceStore::dates('2026-10-02','2026-10-01'),'Reversed dates accepted');
    throws(fn()=>LichtBlickPriceStore::tariffs('[{"from":"2026-10-01","postcode":"14167","adderNetCt":15,"vatPercent":19,"verified":"true"}]'),'Nonboolean tariff confirmation accepted');
} finally {foreach(glob($dir.'/*')?:[] as $file)unlink($file);if(is_dir($dir))rmdir($dir);}

class BackfillFixture extends FixturePrices {
    public bool $marketFails=false;
    public array $requests=[];
    protected function downloadMarket(?int $week): string {
        $this->requests[]=$week;if($this->marketFails)throw new RuntimeException('Market outage');
        $zone=new DateTimeZone('Europe/Berlin');$first=(new DateTimeImmutable('2026-09-28',$zone))->getTimestamp();
        $second=(new DateTimeImmutable('2026-10-05',$zone))->getTimestamp();$third=(new DateTimeImmutable('2026-10-12',$zone))->getTimestamp();
        if($week===null)return json_encode(['timestamps'=>[$first*1000,$second*1000,$third*1000]]);
        $series=[];for($t=intdiv($week,1000);$t<intdiv($week,1000)+7*86400;$t+=900)$series[]=[$t*1000,100];
        return json_encode(['series'=>$series]);
    }
}
$m=new BackfillFixture();$m->Create();$GLOBALS['instance']=$m;$m->properties['PostalCode']='14167';$m->properties['ThresholdCt']=30.0;
$m->properties['HistoricalTariffs']=json_encode($tariffs);$m->ApplyChanges();
check(str_contains($m->RetrieveHistoricalPrices('2026-09-30','2026-10-01'),'before contract start'),'Pre-contract imports rejected');
throws(fn()=>$m->GetPriceHistory($from-900,$to),'Pre-contract reads accepted');
check(str_starts_with($m->RetrieveHistoricalPrices('2026-10-01','2026-10-11'),'Queued'),'Owner contract range queued');
$m->marketFails=true;$m->ProcessHistoricalImport();check($m->attributes['HistoricalImportQueue']!==''&&str_contains($m->values['HistoricalFetchStatus'],'failed'),'Outage retains resumable queue');
$m->marketFails=false;$m->ProcessHistoricalImport();check($m->attributes['HistoricalImportQueue']!=='','Weekly bounded first batch');
$m->ProcessHistoricalImport();check($m->attributes['HistoricalImportQueue']===''&&str_contains($m->values['HistoricalFetchStatus'],'Completed'),'Weekly queue completes');
$history=json_decode($m->GetPriceHistory($from,$from+11*86400),true);
check(count($history['intervals'])===11*96&&abs($history['intervals'][0]['price']-29.75)<1e-9,'Exact full contract range with reconstructed prices');
$before=$m->GetPriceHistory($from,$to);$backup=$m->ExportConfig();check(str_starts_with($m->ImportConfig($backup),'Imported'),'Configuration restores');
check($m->GetPriceHistory($from,$to)===$before,'Configuration import retains price files');
check(!$m->properties['Enabled']&&$m->properties['PriceHistoryEnabled'],'Storage independent from heating recommendation');
$m->CancelHistoricalImport();check(str_contains($m->values['HistoricalFetchStatus'],'retained'),'Cancellation retains records');
$m->properties['HistoricalTariffs']='[]';$m->ApplyChanges();check($m->attributes['HistoryAutomaticDay']==='','Tariff edits reschedule historical reconstruction');
echo "Persistent price history, contract start, provenance, gaps, DST, weekly backfill, outages and restore checks passed.\n";
