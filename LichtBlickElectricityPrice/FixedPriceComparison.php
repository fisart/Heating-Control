<?php
declare(strict_types=1);

/** Consumption-weighted energy charges only, using the same physical kWh. */
final class LichtBlickFixedPriceComparison
{
    public static function prices(array $rows,int $from,int $to,?array $previous): array
    {
        $map=[];$last=null;$out=[];
        if($previous&&($previous['end']??PHP_INT_MAX)<=$from&&is_numeric($previous['price']??null))$last=$previous;
        foreach($rows as $r) {
            if(!is_int($r['start']??null)||$r['start']%900!==0||($r['end']??0)!==$r['start']+900
                ||(isset($r['price'])&&(!is_numeric($r['price'])||!is_finite((float)$r['price'])||abs((float)$r['price'])>1000)))throw new RuntimeException('Invalid comparison price interval');
            $map[$r['start']]=$r;
        }
        for($at=$from;$at<$to;$at+=900) {
            $p=$map[$at]??null;
            if($p&&is_numeric($p['price']??null)){$last=$p;$out[$at]=$p;}
            elseif($last)$out[$at]=['start'=>$at,'end'=>$at+900,'price'=>(float)$last['price'],'verified'=>false,'source'=>'carried-forward','originalPriceStart'=>$last['start']];
        }
        return $out;
    }

    public static function report(array $rows,array $prices,int $from,int $to,float $fixed): array
    {
        if(!is_finite($fixed)||$fixed<0||$fixed>1000)throw new InvalidArgumentException('Enter a fixed gross price from 0 to 1000 ct/kWh');
        $zone=new DateTimeZone('Europe/Berlin');$days=[];$seen=[];$errors=[];
        $empty=static fn()=>['knownMeterKWh'=>0.0,'matchedKWh'=>0.0,'dynamicCostEuro'=>0.0,'matchedSeconds'=>0,'missingMeterIntervals'=>0,
            'missingPriceIntervals'=>0,'carriedPriceSeconds'=>0,'unconfirmedPriceKWh'=>0.0,'appEstimateKWh'=>0.0,'estimatedMeterIntervals'=>0];
        $totals=$empty();
        foreach($rows as $r) {
            if($r['start']<$from||$r['end']>$to)continue;
            if($r['end']-$r['start']!==900||isset($seen[$r['start']]))throw new RuntimeException('Expected unique quarter-hour consumption intervals');
            $seen[$r['start']]=true;$date=(new DateTimeImmutable('@'.$r['start']))->setTimezone($zone)->setTime(0,0);$key=$date->format('Y-m-d');
            if(!isset($days[$key]))$days[$key]=['date'=>$key,'start'=>max($from,$date->getTimestamp()),'end'=>min($to,$date->modify('+1 day')->getTimestamp())]+$empty();
            $values=$empty();$energy=$r['cleanedKWh'];$p=$prices[$r['start']]??null;
            if($r['coverageError']??null)$errors[$r['coverageError']]=true;
            if(!is_numeric($energy)||!is_finite((float)$energy)||$energy<0)$values['missingMeterIntervals']=1;
            else {
                $values['knownMeterKWh']=(float)$energy;
                if($energy==0.||($p&&is_numeric($p['price']??null))) {
                    $values['matchedKWh']=(float)$energy;$values['matchedSeconds']=900;
                    $values['dynamicCostEuro']=$energy==0.?0.:$energy*(float)$p['price']/100;
                    if(($p['source']??'')==='carried-forward')$values['carriedPriceSeconds']=900;
                    if($energy>0&&!($p['verified']??false))$values['unconfirmedPriceKWh']=(float)$energy;
                    if(str_contains($p['source']??'','app-normalized-estimate'))$values['appEstimateKWh']=(float)$energy;
                    if(($r['qualityFlags']??0)&2)$values['estimatedMeterIntervals']=1;
                }else $values['missingPriceIntervals']=1;
            }
            foreach($values as $k=>$v){$totals[$k]+=$v;$days[$key][$k]+=$v;}
        }
        $summarize=static function(array $v,int $seconds) use ($fixed): array {
            $paired=$v['matchedSeconds']>0;$fixedCost=$paired?$v['matchedKWh']*$fixed/100:null;$cost=$paired?$v['dynamicCostEuro']:null;
            $saving=$paired?$fixedCost-$cost:null;
            return $v+['fixedPriceCt'=>$fixed,'weightedPriceCt'=>$v['matchedKWh']>0?100*$cost/$v['matchedKWh']:null,
                'comparisonDynamicCostEuro'=>$cost,'fixedCostEuro'=>$fixedCost,'savingEuro'=>$saving,
                'savingPercent'=>$fixedCost!==null&&$fixedCost>0?100*$saving/$fixedCost:null,
                'timeCoveragePercent'=>$seconds>0?100*$v['matchedSeconds']/$seconds:0.,
                'consumptionCoveragePercent'=>$v['knownMeterKWh']>0?100*$v['matchedKWh']/$v['knownMeterKWh']:null,
                'complete'=>$seconds>0&&$v['matchedSeconds']===$seconds,
                'provisional'=>$v['unconfirmedPriceKWh']>0||$v['estimatedMeterIntervals']>0];
        };
        foreach($days as &$d)$d=$summarize($d,$d['end']-$d['start']);unset($d);
        return ['schema'=>1,'from'=>$from,'to'=>$to,'timezone'=>'Europe/Berlin','summary'=>$summarize($totals,$to-$from),
            'days'=>array_values($days),'missingArchiveIntervals'=>max(0,intdiv($to-$from,900)-count($seen)),'errors'=>array_keys($errors),
            'scope'=>'Whole-house electricity, all hours. Physical cleaned meter kWh, without invoice/reference normalization. Both costs use identical paired consumption. Standing charges, bonuses, invoice residuals and separate fixed costs are not added. Imported app-normalized prices remain provisional and may already absorb fixed charges.'];
    }
}

trait LichtBlickFixedPriceAccess
{
    public function GetElectricityPriceComparison(string $from,string $through,float $fixedPriceCt=34.0): string
    {
        return json_encode($this->electricityPriceComparison($from,$through,$fixedPriceCt),JSON_THROW_ON_ERROR);
    }

    private function electricityPriceComparison(string $from,string $through,float $fixed): array
    {
        if(!is_finite($fixed)||$fixed<0||$fixed>1000)throw new InvalidArgumentException('Enter a fixed gross price from 0 to 1000 ct/kWh');
        [$a,$requestedEnd]=LichtBlickPriceStore::dates($from,$through,366);$now=time();$zone=new DateTimeZone('Europe/Berlin');
        $today=(new DateTimeImmutable('@'.$now))->setTimezone($zone)->setTime(0,0);
        if($a<$this->historyStart())throw new InvalidArgumentException('Select dates on or after the LichtBlick contract start');
        if($a>=$now||$through>$today->format('Y-m-d'))throw new InvalidArgumentException('Select today or past dates');
        $b=min($requestedEnd,intdiv($now,900)*900);
        if($b<=$a)throw new InvalidArgumentException('No completed quarter-hour is available in this period yet');
        $stored=$this->priceStore()->query($this->historyIdentity(),$a,$b);$prices=[];
        foreach($stored as $p)$prices[$p['start']]=$p;
        $adapter=new LichtBlickConsumptionHistory($this->ReadPropertyInteger('ArchiveID'),$this->consumptionIDs());$rows=[];$last=$a;
        if($this->ReadPropertyBoolean('PreparedHistoryEnabled')) {
            $data=LichtBlickConsumptionHistory::dataset($this->preparedManifestFile(),$this->consumptionIDs());
            $last=max($a,$data['intervals'][count($data['intervals'])-1]['end']);
            $rows=$adapter->prepared($data,$a,min($b,$last),$stored);
        }
        // One calendar day at a time bounds raw-meter memory on long selections.
        for($at=$last;$at<$b;$at=$end) {
            $day=(new DateTimeImmutable('@'.$at))->setTimezone($zone)->setTime(0,0);
            $end=min($b,$day->modify('+1 day')->getTimestamp());
            $rows=array_merge($rows,$adapter->later($this->ReadPropertyInteger('ConsumptionCounterID'),$this->ReadPropertyFloat('ConsumptionCounterScale'),$stored,$at,$end,$now));
        }
        $rows=LichtBlickNoConsumption::apply($rows,LichtBlickNoConsumption::periods($this->ReadPropertyString('ConsumptionZeroPeriods')),'electricity');
        // Reviewed interval prices are also existing evidence if their store copy is absent.
        foreach($rows as $r)if(!is_numeric($prices[$r['start']]['price']??null)&&is_numeric($r['unitPriceCt']??null))
            $prices[$r['start']]=['start'=>$r['start'],'end'=>$r['end'],'price'=>$r['unitPriceCt'],'source'=>$r['priceSource'],'verified'=>$r['priceVerified']];
        $missing=false;for($at=$a;$at<$b;$at+=900)if(!is_numeric($prices[$at]['price']??null)){$missing=true;break;}
        $recovery=null;$warnings=[];
        if($missing)try {
            $repair=json_decode($this->RecoverPriceHistory($a,$b),true,32,JSON_THROW_ON_ERROR);$recovery=$repair['recovery']??null;
            foreach($repair['intervals'] as $p)if(is_numeric($p['price']??null))$prices[$p['start']]=$p;
        }catch(Throwable $e){$warnings[]='Price recovery: '.$e->getMessage();}
        $previous=null;try{$previous=$this->priceStore()->lastKnown($this->historyIdentity(),$a);}catch(Throwable $e){$warnings[]='Pre-period price: '.$e->getMessage();}
        $prices=LichtBlickFixedPriceComparison::prices(array_values($prices),$a,$b,$previous);
        $report=LichtBlickFixedPriceComparison::report($rows,$prices,$a,$b,$fixed);
        return $report+['fromDate'=>$from,'throughDate'=>$through,'updatedAt'=>$now,'priceRecovery'=>$recovery,'warnings'=>$warnings,
            'contractStartDate'=>$this->ReadPropertyString('PriceHistoryStartDate'),'meterCounterID'=>$this->ReadPropertyInteger('ConsumptionCounterID')];
    }
}
