<?php
declare(strict_types=1);

/** Manual garden volumes are period references, never interpolated to days. */
final class LichtBlickManualWater
{
    public static function periods(string $json): array
    {
        if(strlen($json)>65536)throw new InvalidArgumentException('Manual garden periods are too large');
        $rows=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>200)throw new InvalidArgumentException('Use a list of manual garden periods');
        $previous=0;
        foreach($rows as &$r){
            if(!is_array($r)||!is_string($r['from']??null)||!is_string($r['through']??null)||!is_numeric($r['m3']??null)
                ||!is_finite((float)$r['m3'])||$r['m3']<0||$r['m3']>100000||!is_string($r['source']??null)||trim($r['source'])===''||strlen($r['source'])>256)throw new InvalidArgumentException('Manual garden dates, m3 and source are required');
            [$r['start'],$r['end']]=LichtBlickPriceStore::dates($r['from'],$r['through'],2192);
            if($r['start']<$previous)throw new InvalidArgumentException('Manual garden periods must be ordered without overlap');$previous=$r['end'];$r['m3']=(float)$r['m3'];
        }unset($r);return $rows;
    }
    public static function variableCost(?float $m3,array $days,array $tariffs,string $key): ?float
    {
        if($m3===null)return null;if($m3===0.)return 0.;$unit=null;
        foreach($days as $day){$rate=null;foreach($tariffs as $r)if($r['stream']===$key && $r['start']<=$day['start'] && $r['end']>=$day['end']){$rate=$r;break;}
            if(!$rate)return null;$value=$rate['drinkingNetEuroM3']*(1+$rate['vatPercent']/100)+$rate['sewageGrossEuroM3'];
            if($unit!==null && abs($value-$unit)>1e-9)return null;$unit=$value;
        }return $unit===null?null:$m3*$unit;
    }
    public static function references(array $periods,array $main,array $tariffs,int $from,int $to,array $garden=[]): array
    {
        $refs=[];
        foreach($periods as $r){if($r['start']>=$to||$r['end']<=$from)continue;$full=$r['start']>=$from && $r['end']<=$to;
            $house=null;$mainM3=0.;$seconds=0;$days=LichtBlickWaterHistory::days($r['start'],$r['end']);
            if($full){$valid=true;foreach($main as $p)if($p['start']>=$r['start'] && $p['end']<=$r['end']){
                $seconds+=$p['end']-$p['start'];$v=$p['mainMeterM3']??$p['observedM3'];$flags=$p['mainMeterQualityFlags']??$p['qualityFlags'];
                if($v===null || (!($p['confirmedNoConsumption']??false) && ($flags&(1|8))))$valid=false;else $mainM3+=$v;
            }if($valid && $seconds===$r['end']-$r['start'] && $mainM3+1e-8>=$r['m3'])$house=max(0.,$mainM3-$r['m3']);}
            $r['wholePeriodSelected']=$full;$r['mainMeterM3']=$full&&($valid??false)&&$seconds===$r['end']-$r['start']?$mainM3:null;$r['houseOnlyM3']=$house;
            $r['gardenVariableCostEuro']=$full?self::variableCost($r['m3'],$days,$tariffs,'garden'):null;
            $r['houseVariableCostEuro']=$full?self::variableCost($house,$days,$tariffs,'house'):null;
            $r['days']=count($days);
            foreach(['house'=>$main,'garden'=>$garden] as $key=>$points){$missingVolume=0;$missingCost=0;$coverage=true;
                foreach($days as $day){$found=null;foreach($points as $p)if($p['start']===$day['start'] && $p['end']===$day['end']){$found=$p;break;}
                    if(!$found || $found['observedM3']===null || (!($found['confirmedNoConsumption']??false) && ($found['qualityFlags']&(1|8|256|16384))))$missingVolume++;
                    if(!$found || ($found['costEuro']??null)===null)$missingCost++;
                    $rateFound=false;foreach($tariffs as $rate)if($rate['stream']===$key && $rate['start']<=$day['start'] && $rate['end']>=$day['end']){$rateFound=true;break;}$coverage=$coverage&&$rateFound;
                }
                $r[$key.'MissingVolumeDays']=$missingVolume;$r[$key.'MissingCostDays']=$missingCost;$r[$key.'TariffCoverage']=$coverage;
            }
            $refs[]=$r;
        }return $refs;
    }
    public static function merge(array $report,array $references,bool $total): array
    {
        $report['manualGardenPeriods']=$references;$report['unallocatedManualCostEuro']=0.;$report['unallocatedManualVolumeM3']=0.;$report['manualVolumeAllocation']='Period references only; no daily interpolation';
        foreach($references as $r){if(!$r['wholePeriodSelected'])continue;
            foreach(['garden'=>['volume'=>$r['m3'],'cost'=>$r['gardenVariableCostEuro']],'house'=>['volume'=>$r['houseOnlyM3'],'cost'=>$r['houseVariableCostEuro']]] as $key=>$add){
                $t=&$report['streams'][$key];if(!$total)$t=&$report['streams'][$key]['totals'];
                $volumeKey=$total?'knownVolume':'knownM3';$missingKey=$total?'incompleteVolumeDays':'missingVolumeDays';
                if($add['volume']!==null){$t[$volumeKey]=($t[$volumeKey]??0)+$add['volume'];$t[$missingKey]=max(0,$t[$missingKey]-$r[$key.'MissingVolumeDays']);}
                if($add['cost']!==null){$t['knownCostEuro']=($t['knownCostEuro']??0)+$add['cost'];if(!$total)$t['knownVariableCostEuro']+=$add['cost'];
                    // Fixed charges still require complete dated coverage.
                    $fixedComplete=$r[$key.'TariffCoverage'];
                    if($fixedComplete)$t['incompleteCostDays']=max(0,$t['incompleteCostDays']-$r[$key.'MissingCostDays']);
                }
                if(!$total){$t['completeVolume']=$t[$missingKey]===0;$t['completeCost']=$t['incompleteCostDays']===0;}
                unset($t);$placed=false;
                foreach($report['points'] as &$bucket)if($bucket['start']<=$r['start'] && $bucket['end']>=$r['end']){
                    $s=&$bucket['streams'][$key];if($add['volume']!==null){$s[$volumeKey]=($s[$volumeKey]??0)+$add['volume'];$s[$missingKey]=max(0,$s[$missingKey]-$r[$key.'MissingVolumeDays']);}
                    if($add['cost']!==null){$s['knownCostEuro']=($s['knownCostEuro']??0)+$add['cost'];if($r[$key.'TariffCoverage'])$s['incompleteCostDays']=max(0,$s['incompleteCostDays']-$r[$key.'MissingCostDays']);}
                    $s['manualPeriodReference']=true;$placed=true;unset($s);break;
                }unset($bucket);
                if(!$placed){$report['unallocatedManualCostEuro']+=$add['cost']??0.;$report['unallocatedManualVolumeM3']+=$add['volume']??0.;}
            }
        }
        if($total){$report['totals']['knownCostEuro']=0.;$report['totals']['knownWaterM3']=0.;$report['totals']['completeCost']=true;
            foreach($report['streams'] as $key=>$t){$report['totals']['knownCostEuro']+=$t['knownCostEuro']??0.;$report['totals']['completeCost']=$report['totals']['completeCost']&&$t['incompleteCostDays']===0;if(in_array($key,['house','garden','well'],true))$report['totals']['knownWaterM3']+=$t['knownVolume']??0.;}
            foreach($report['points'] as &$b){$b['knownTotalCostEuro']=0.;$b['knownWaterM3']=0.;$b['completeCost']=true;foreach($b['streams'] as $key=>$s){$b['knownTotalCostEuro']+=$s['knownCostEuro']??0.;$b['completeCost']=$b['completeCost']&&$s['incompleteCostDays']===0;if(in_array($key,['house','garden','well'],true))$b['knownWaterM3']+=$s['knownVolume']??0.;}}unset($b);
        }return $report;
    }
}
