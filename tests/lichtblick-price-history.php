<?php
declare(strict_types=1);
require __DIR__ . '/lichtblick-fixture.php';
$from=(new DateTimeImmutable('2026-10-01',new DateTimeZone('Europe/Berlin')))->getTimestamp();
$to=$from+86400; $dir=sys_get_temp_dir().'/lbep-history-'.bin2hex(random_bytes(6));
$store=new LichtBlickPriceStore($dir);$id=LichtBlickPriceStore::identity('14167',0.0);
$rows=[['start'=>$from,'end'=>$from+900,'price'=>18.4,'marketCt'=>-0.01,'source'=>'app-normalized-estimate','verified'=>false],
    ['start'=>$from+900,'end'=>$from+1800,'price'=>30.3,'marketCt'=>10.0,'source'=>'app-normalized-estimate','verified'=>false],
    ['start'=>$from+2700,'end'=>$from+3600,'price'=>null,'marketCt'=>20.0,'source'=>'smard-wholesale','verified'=>false]];
try {
    check($store->merge($id,$rows)===3,'Manual history rows retained');
    check(count($store->query($id,$from,$to))===3,'Missing quarter-hour remains a gap');
    check($store->merge($id,$rows)===0,'Repeat merge changes nothing');
    $chart=LichtBlickPriceStore::chart($rows,$from,$to);
    check($chart['estimatedIntervals']===2&&$chart['confirmedIntervals']===0&&$chart['wholesaleOnlyIntervals']===1,'Imported estimates distinct from verified retail and wholesale');
    $quote=$rows[0];$quote['price']=32.0;$quote['source']='lichtblick-quote';$quote['verified']=true;
    $store->merge($id,[$quote]);$store->merge($id,$rows);
    check(abs($store->query($id,$from,$to)[0]['price']-32.0)<1e-9,'Direct quote preserved over imported estimate');
    $unconfirmed=$quote;$unconfirmed['verified']=false;$unconfirmed['price']=19.0;$store->merge($id,[$unconfirmed]);
    check(abs($store->query($id,$from,$to)[0]['price']-32.0)<1e-9,'Unconfirmed quote cannot downgrade confirmed quote');
    $newStore=new LichtBlickPriceStore($dir);check(count($newStore->query($id,$from,$to))===3,'Price files survive a new object');
    $other=LichtBlickPriceStore::identity('14167',1.0);$store->merge($other,[$quote]);
    check(count($store->query($id,$from,$to))===3&&count($store->query($other,$from,$to))===1,'Other correction identity retained');
    [$a,$b]=LichtBlickPriceStore::dates('2026-10-25','2026-10-25');check(($b-$a)/900===100,'Autumn DST quarter-hours');
    $daily=LichtBlickPriceStore::chart($rows,$from,$from+9*86400);check($daily['resolution']==='daily mean'&&count($daily['points'])===1&&str_contains($daily['points'][0]['source'],'app-normalized-estimate'),'Daily mean keeps estimate provenance');
    throws(fn()=>LichtBlickPriceStore::dates('2026-02-30','2026-03-01'),'Invalid dates rejected');
    throws(fn()=>LichtBlickPriceStore::dates('2026-10-02','2026-10-01'),'Reversed dates rejected');
} finally {foreach(glob($dir.'/*')?:[] as $file)unlink($file);if(is_dir($dir))rmdir($dir);}
class ManualHistoryFixture extends FixturePrices {
    public int $marketRequests=0;
    public function storeDirectory(): string {return $this->priceHistoryDirectory();}
    protected function downloadMarket(?int $week): string {$this->marketRequests++;throw new RuntimeException('Historical downloads are retired');}
}
$m=new ManualHistoryFixture();$m->Create();$GLOBALS['instance']=$m;
$m->properties['PostalCode']='14167';$m->properties['ThresholdCt']=30.0;
$m->properties['HistoricalFetchEnabled']=true;$m->properties['HistoricalTariffs']='old unused tariff text';$m->ApplyChanges();
$manual=new LichtBlickPriceStore($m->storeDirectory());$manual->merge($id,$rows);
check(!method_exists($m,'RetrieveHistoricalPrices')&&!method_exists($m,'ProcessHistoricalImport')&&!method_exists($m,'CancelHistoricalImport'),'Historical downloader API removed');
check(!method_exists(LichtBlickPriceStore::class,'market')&&!method_exists(LichtBlickPriceStore::class,'reconstruct'),'Downloader parsing and tariff reconstruction removed');
throws(fn()=>$m->GetPriceHistory($from-900,$to),'Pre-contract reads rejected');
$before=$m->GetPriceHistory($from,$to);$m->Tick();check($m->marketRequests===0,'Legacy true setting never restarts SMARD downloads');
check($m->GetPriceHistory($from,$to)===$before,'Normal tick retains imported history');
$backup=$m->ExportConfig();check(str_starts_with($m->ImportConfig($backup),'Imported'),'Old schema-1 configuration restores');
check(!$m->properties['HistoricalFetchEnabled']&&$m->properties['HistoricalTariffs']==='[]','Restore clears retired controls');
check($m->GetPriceHistory($from,$to)===$before,'Configuration restore preserves imported files');
check(!$m->properties['Enabled']&&$m->properties['PriceHistoryEnabled'],'Storage independent of heating recommendation');
$form=json_decode(file_get_contents(__DIR__.'/../LichtBlickElectricityPrice/form.json'),true,64,JSON_THROW_ON_ERROR);$formText=json_encode($form);
check(!str_contains($formText,'Retrieve historical prices')&&!str_contains($formText,'HistoricalFetchEnabled')&&!str_contains($formText,'HistoricalTariffs'),'Retired controls removed from form');
echo "Persistent quotes/manual estimates, provenance, gaps, DST, legacy settings, no SMARD downloads and configuration restore checks passed.\n";
