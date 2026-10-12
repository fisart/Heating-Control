<?php
declare(strict_types=1);

/** Reads prepared archive intervals. Rebuilds neither raw history nor invoices. */
final class LichtBlickConsumptionHistory
{
    public function __construct(private int $archive, private array $ids) {}

    public static function dataset(string $manifestFile, array $ids): array
    {
        if (!is_file($manifestFile) || filesize($manifestFile) > 262144) throw new RuntimeException('Prepared-history import manifest is unavailable');
        $manifest = json_decode((string)file_get_contents($manifestFile), true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['schema'] ?? '') !== 'Symcon.PreparedElectricityImport.v1'
            || !in_array($manifest['summary']['status'] ?? '', ['import_saved','import_saved_reaggregation_pending'], true)
            || ($manifest['summary']['target_variable_ids'] ?? []) !== $ids) throw new RuntimeException('Prepared-history variable IDs differ from the import manifest');
        $file = dirname($manifestFile) . '/strom-aufbereitung-geprueft.json';
        if (!is_file($file) || filesize($file) > 16777216
            || hash_file('sha256', $file) !== ($manifest['payload_sha256'] ?? '')) throw new RuntimeException('Prepared-history dataset is unavailable or changed');
        $data = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? '') !== 'Symcon.PreparedElectricityHistory.v1' || !is_array($data['intervals'] ?? null)
            || count($data['intervals']) < 1 || ($data['archive_id'] ?? 0) !== ($manifest['archive_id'] ?? -1)) throw new RuntimeException('Invalid prepared-history dataset');
        $data['_archive_id'] = $manifest['archive_id'];
        return $data;
    }

    /** Adaptive bounded queries also retain conflicting same-second records. */
    public function rows(int $id, int $from, int $to): array
    {
        if ($id <= 0 || !IPS_VariableExists($id) || !AC_GetLoggingStatus($this->archive, $id)) throw new RuntimeException('Consumption archive variable is unavailable or unlogged: ' . $id);
        $read = function (int $a, int $b) use (&$read, $id): array {
            $part = AC_GetLoggedValues($this->archive, $id, $a, $b, 10000);
            if (!is_array($part)) throw new RuntimeException('Consumption archive could not be read');
            if (count($part) >= 10000) {
                if ($a === $b) throw new RuntimeException('Consumption archive has too many same-second records');
                $middle = $a + intdiv($b - $a, 2);
                return array_merge($read($a, $middle), $read($middle + 1, $b));
            }
            return $part;
        };
        $result = [];
        foreach ($read($from, $to) as $r) {
            if (!is_int($r['TimeStamp'] ?? null) || !is_numeric($r['Value'] ?? null) || !is_finite((float)$r['Value'])) throw new RuntimeException('Invalid consumption archive value');
            $t = $r['TimeStamp']; $v = (float)$r['Value'];
            if (isset($result[$t]) && abs($result[$t] - $v) > 1e-9) throw new RuntimeException('Ambiguous consumption archive timestamp');
            $result[$t] = $v;
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    public function prepared(array $data, int $from, int $to, array $quotes = []): array
    {
        if ($data['_archive_id'] !== $this->archive) throw new RuntimeException('Configured archive differs from the import');
        $selected = array_values(array_filter($data['intervals'], static fn($r) => $r['start'] >= $from && $r['end'] <= $to));
        if (!$selected) return [];
        $a = $selected[0]['start']; $b = $selected[count($selected)-1]['end'];
        $clean = $this->rows($this->ids['CleanedCounter'], $a, $b);
        $ref = $this->rows($this->ids['ReferenceCounter'], $a, $b);
        $quality = $this->rows($this->ids['QualityFlags'], $a, $b);
        $quoteMap=[];foreach($quotes as $quote)if(($quote['source']??'')==='lichtblick-quote')$quoteMap[$quote['start']]=$quote;
        $result = [];
        foreach ($selected as $r) {
            foreach ([$clean,$ref] as $counter) if (!isset($counter[$r['start']],$counter[$r['end']])) throw new RuntimeException('Prepared counter boundary is missing');
            $kwh = $clean[$r['end']] - $clean[$r['start']]; $reference = $ref[$r['end']] - $ref[$r['start']];
            if (!isset($quality[$r['start']]) || $quality[$r['start']] !== (float)$r['quality_flags']
                || abs($kwh - $r['cleaned_kwh']) > 1e-7 || abs($reference - $r['reference_kwh']) > 1e-7) throw new RuntimeException('Prepared archive differs from the reviewed dataset');
            $price=$r['unit_price_gross_ct_per_kwh'];$verified=$r['price_verified'];$source=$r['price_source'];$flags=$r['quality_flags'];
            $quote=$quoteMap[$r['start']]??null;
            if($r['price_source']==='app-normalized-estimate' && $quote && ($quote['verified']??false)
                && ($quote['end']??0)===$r['end'] && is_numeric($quote['price']??null) && is_finite((float)$quote['price'])){
                $price=(float)$quote['price'];$verified=true;$source='lichtblick-quote';$flags&=~512;
            }
            $result[] = ['start'=>$r['start'],'end'=>$r['end'],'cleanedKWh'=>$kwh,'referenceKWh'=>$reference,
                'tariffCostEuro'=>$reference*$price/100+$r['fixed_cost_eur_candidate'],
                'unitPriceCt'=>$price,'fixedCostEuro'=>$r['fixed_cost_eur_candidate'],
                'qualityFlags'=>$flags,'archiveQualityFlags'=>$r['quality_flags'],'priceVerified'=>$verified,'priceSource'=>$source,
                'source'=>'prepared-archive','estimatedResetKWh'=>$r['estimated_component_kwh']];
        }
        return $result;
    }

    /** Later periods use raw meter deltas and stored retail quotes; no guessed reset energy. */
    public function later(int $counterID, float $scale, array $prices, int $from, int $to, int $now): array
    {
        $end = min($to, (int)(floor($now/900)*900));
        if ($from >= $end) return [];
        $priceMap = [];
        foreach ($prices as $p) $priceMap[$p['start']] = $p;
        $meter = []; $error = null;
        try {
            $meter = $this->rows($counterID, max(0,$from-180), min($now,$end+180));
        } catch (RuntimeException $e) { $error = $e->getMessage(); }
        $times = array_keys($meter); $n = count($times); $cursor = 0;
        $boundary = static function (int $t) use ($times, $meter, $n, &$cursor): ?float {
            while ($cursor+1<$n && $times[$cursor+1]<=$t) $cursor++;
            if (!$n || $times[$cursor]>$t) return null;
            $a = $times[$cursor]; $v = $meter[$a];
            if ($a===$t) return $v;
            if ($cursor+1 >= $n) return null;
            $b=$times[$cursor+1]; $w=$meter[$b];
            if ($b-$a>180 || $w<$v || $v<0) return null;
            return $v+($w-$v)*($t-$a)/($b-$a);
        };
        $result = []; $scan = 0;
        for ($t=$from; $t<$end; $t+=900) {
            $a=$boundary($t); $b=$boundary($t+900); $kwh=null; $bad=false;
            while ($scan+1<$n && $times[$scan+1]<=$t) $scan++;
            for ($j=$scan; $j+1<$n && $times[$j]<$t+900; $j++) {
                $x=$times[$j];$y=$times[$j+1];
                if ($y-$x>180 || $meter[$y]<$meter[$x] || $meter[$x]<0
                    || ($meter[$y]-$meter[$x])*$scale > 60*($y-$x)/3600+.05) $bad=true;
            }
            if ($a!==null && $b!==null && !$bad && $b>=$a) $kwh=($b-$a)*$scale;
            $p=$priceMap[$t]??null;
            $validPrice=$p && ($p['end']??0)===$t+900 && is_numeric($p['price']??null) && is_finite((float)$p['price']);
            $flags=256|1; if($kwh===null)$flags|=2048;if(!$validPrice)$flags|=4096;
            if ($validPrice && !($p['verified']??false)) $flags|=512;
            $result[]=['start'=>$t,'end'=>$t+900,'cleanedKWh'=>$kwh,'referenceKWh'=>$kwh,
                'tariffCostEuro'=>$kwh!==null && $validPrice ? $kwh*(float)$p['price']/100 : null,
                'unitPriceCt'=>$validPrice ? (float)$p['price'] : null,'fixedCostEuro'=>null,
                'qualityFlags'=>$flags,'priceVerified'=>$validPrice && ($p['verified']??false),
                'priceSource'=>$validPrice ? $p['source'] : 'missing','source'=>'live-meter-archive',
                'estimatedResetKWh'=>0.0,'coverageError'=>$error];
        }
        return $result;
    }

    public static function report(array $rows, array $data, int $from, int $to, string $resolution): array
    {
        $zone=new DateTimeZone('Europe/Berlin'); $buckets=[];$knownEnergy=0.0;$knownRef=0.0;$knownCost=0.0;
        $missingEnergy=0;$missingPrice=0;$estimated=0;$resetEstimate=0.0;$unconfirmedPrices=0;$unreferenced=0;$covered=0;
        foreach ($rows as $r) {
            $date=(new DateTimeImmutable('@'.$r['start']))->setTimezone($zone);
            $start=match($resolution){'monthly'=>$date->modify('first day of this month')->setTime(0,0),'yearly'=>$date->setDate((int)$date->format('Y'),1,1)->setTime(0,0),default=>$date->setTime(0,0)};
            $key=$resolution==='intervals'?(string)$r['start']:$start->format('Y-m-d');
            if(!isset($buckets[$key]))$buckets[$key]=['start'=>$r['start'],'end'=>$r['end'],'cleanedKWh'=>0.0,'referenceKWh'=>0.0,'tariffCostEuro'=>0.0,'qualityFlags'=>0,'missingEnergyIntervals'=>0,'missingPriceIntervals'=>0,'intervals'=>0,'knownReferenceKWh'=>0.0,'knownCleanedKWh'=>0.0,'knownTariffCostEuro'=>0.0,'knownPriceIntervals'=>0];
            $p=&$buckets[$key];$p['end']=$r['end'];$p['intervals']++;$p['qualityFlags']|=$r['qualityFlags'];
            $covered+=$r['end']-$r['start'];
            if($r['cleanedKWh']===null){$p['missingEnergyIntervals']++;$missingEnergy++;}
            else{$p['cleanedKWh']+=$r['cleanedKWh'];$p['referenceKWh']+=$r['referenceKWh'];$knownEnergy+=$r['cleanedKWh'];$knownRef+=$r['referenceKWh'];$p['knownReferenceKWh']+=$r['referenceKWh'];$p['knownCleanedKWh']+=$r['cleanedKWh'];}
            if($r['unitPriceCt']===null && !($r['confirmedNoConsumption']??false)){$p['missingPriceIntervals']++;$missingPrice++;}
            if($r['tariffCostEuro']!==null){$p['tariffCostEuro']+=$r['tariffCostEuro'];$knownCost+=$r['tariffCostEuro'];$p['knownTariffCostEuro']+=$r['tariffCostEuro'];$p['knownPriceIntervals']++;}
            if($r['qualityFlags']&2)$estimated++;if($r['qualityFlags']&256)$unreferenced++;
            if(!$r['priceVerified'])$unconfirmedPrices++;$resetEstimate+=$r['estimatedResetKWh'];unset($p);
        }
        foreach($buckets as &$p){if($p['missingEnergyIntervals'])$p['cleanedKWh']=$p['referenceKWh']=null;if($p['missingEnergyIntervals']||$p['missingPriceIntervals'])$p['tariffCostEuro']=null;}unset($p);
        $references=[];$adjustment=0.0;
        foreach($data['reference_reconciliation'] as $ref){
            [$a,$b]=LichtBlickPriceStore::dates($ref['from'],$ref['to_inclusive'],400);
            if($b<=$from||$a>=$to)continue;
            $full=$a>=$from&&$b<=$to;
            if($full){
                $cost=0.0;$seconds=0;$complete=true;
                foreach($rows as $r)if($r['start']>=$a && $r['end']<=$b){$seconds+=$r['end']-$r['start'];if($r['tariffCostEuro']===null)$complete=false;else $cost+=$r['tariffCostEuro'];}
                if(!$complete||$seconds!==$b-$a)throw new RuntimeException('Complete invoice period has missing cost coverage');
                $ref['dated_tariff_cost_on_reference_curve_eur']=$cost;
                $ref['billing_reconciliation_eur']=$ref['billed_gross_eur']-$cost;
                $adjustment+=$ref['billing_reconciliation_eur'];
            }
            $references[]=$ref+['wholeReferenceSelected'=>$full];
        }
        return ['schema'=>1,'from'=>$from,'to'=>$to,'resolution'=>$resolution,'points'=>array_values($buckets),
            'preparedThrough'=>$data['to_exclusive'],'references'=>$references,
            'qualityBits'=>$data['quality_bits']+['outside_snapshot'=>1024,'missing_meter_coverage'=>2048,'missing_retail_price'=>4096],
            'totals'=>['knownCleanedKWh'=>$knownEnergy,'knownReferenceKWh'=>$knownRef,'knownTariffCostEuro'=>$knownCost,
                'fullPeriodReconciliationEuro'=>$adjustment,'knownCostWithFullPeriodReconciliationEuro'=>$knownCost+$adjustment,
                'missingEnergyIntervals'=>$missingEnergy,'missingPriceIntervals'=>$missingPrice,'longGapOrEstimateIntervals'=>$estimated,
                'explicitResetEstimateKWh'=>$resetEstimate,'unconfirmedPriceIntervals'=>$unconfirmedPrices,'withoutConsumptionReferenceIntervals'=>$unreferenced,
                'coveredSeconds'=>$covered,'requestedSeconds'=>$to-$from],
            'laterCostScope'=>'Later meter periods contain energy charges only. No undocumented basic charges are added. App-normalized estimates may already absorb such charges.',
            'policy'=>'Invoices are authoritative totals. Their residual is shown separately for complete reference periods; it is not spread into daily prices or partial billing periods. Reference normalization is period-specific. The prepared archive remains a fixed reviewed snapshot.'];
    }
}

trait LichtBlickConsumptionHistoryAccess
{
    private function consumptionSettings(): array
    {
        $enabled=$this->ReadPropertyBoolean('PreparedHistoryEnabled');
        $settings=['consumptionHistoryEnabled'=>$enabled,'consumptionHistoryFrom'=>'','consumptionHistoryThrough'=>''];
        if(!$enabled)return $settings;
        try{
            $path=$this->preparedManifestFile();
            if(is_file($path) && filesize($path)<262144){
                $manifest=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
                $settings['consumptionHistoryFrom']=substr($manifest['summary']['from']??'',0,10);
                $settings['consumptionHistoryThrough']=substr($manifest['summary']['to_exclusive']??'',0,10);
            }
        }catch(Throwable $e){/* The history endpoint reports configuration/read errors. */}
        return $settings;
    }
    private function consumptionIDs(): array
    {
        return ['CleanedCounter'=>$this->ReadPropertyInteger('PreparedCleanedCounterID'),
            'ReferenceCounter'=>$this->ReadPropertyInteger('PreparedReferenceCounterID'),
            'QualityFlags'=>$this->ReadPropertyInteger('PreparedQualityID')];
    }

    protected function preparedManifestFile(): string
    {
        $relative=$this->ReadPropertyString('PreparedHistoryManifest');
        if($relative===''||str_contains($relative,'..')||str_starts_with($relative,'/')||str_contains($relative,'\\'))throw new RuntimeException('Select the imported manifest path relative to the Symcon kernel directory');
        return rtrim(IPS_GetKernelDir(),'/\\').'/'.$relative;
    }

    public function GetConsumptionHistory(string $from, string $through, string $resolution = 'auto'): string
    {
        return json_encode($this->consumptionChart($from,$through,$resolution),JSON_THROW_ON_ERROR);
    }

    private function consumptionChart(string $from, string $through, string $resolution): array
    {
        if(!$this->ReadPropertyBoolean('PreparedHistoryEnabled'))throw new RuntimeException('Prepared consumption history is not connected');
        if(!in_array($resolution,['auto','intervals','daily','monthly','yearly'],true))throw new InvalidArgumentException('Select a valid consumption-history resolution');
        [$a,$b]=LichtBlickPriceStore::dates($from,$through,2192);
        $data=LichtBlickConsumptionHistory::dataset($this->preparedManifestFile(),$this->consumptionIDs());
        $first=$data['intervals'][0]['start'];$last=$data['intervals'][count($data['intervals'])-1]['end'];
        if($a<$first)throw new InvalidArgumentException('Prepared consumption starts on '.substr($data['from'],0,10));
        if($resolution==='auto')$resolution=$b-$a>100*86400?'monthly':($b-$a>7*86400?'daily':'intervals');
        if($resolution==='intervals')LichtBlickPriceStore::dates($from,$through,31);
        $adapter=new LichtBlickConsumptionHistory($this->ReadPropertyInteger('ArchiveID'),$this->consumptionIDs());
        $quotes=[];$quoteFrom=max($a,$this->historyStart());$quoteTo=min($b,$last);
        for($t=$quoteFrom;$t<$quoteTo;$t=$end){$end=min($quoteTo,$t+360*86400);$quotes=array_merge($quotes,$this->priceStore()->query($this->historyIdentity(),$t,$end));}
        $rows=$adapter->prepared($data,$a,min($b,$last),$quotes);
        $now=time();$laterFrom=max($a,$last);$laterTo=min($b,(int)(floor($now/900)*900));
        if($laterFrom<$laterTo){
            $prices=[];
            for($t=$laterFrom;$t<$laterTo;$t=$end){$end=min($laterTo,$t+360*86400);$prices=array_merge($prices,$this->priceStore()->query($this->historyIdentity(),$t,$end));}
            $rows=array_merge($rows,$adapter->later($this->ReadPropertyInteger('ConsumptionCounterID'),$this->ReadPropertyFloat('ConsumptionCounterScale'),$prices,$laterFrom,$laterTo,$now));
        }
        $rows=LichtBlickNoConsumption::apply($rows,LichtBlickNoConsumption::periods($this->ReadPropertyString('ConsumptionZeroPeriods')),'electricity');
        return LichtBlickConsumptionHistory::report($rows,$data,$a,$b,$resolution);
    }
}

