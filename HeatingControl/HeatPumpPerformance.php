<?php
declare(strict_types=1);

/** Air-side estimates and local empirical relationships; never a certified COP. */
final class HeatPumpPerformance
{
    public static function point(array $samples, array $baseline, float $airflow, int $power, int $window): array
    {
        if (count($samples) < 6) throw new RuntimeException('Too few samples in the measurement window');
        usort($samples, static fn($a,$b)=>$a['timestamp']<=>$b['timestamp']);
        $first=$samples[0]['timestamp']; $last=$samples[count($samples)-1]['timestamp'];
        $maxGap=0; foreach ($samples as $i=>$s) if ($i) $maxGap=max($maxGap,$s['timestamp']-$samples[$i-1]['timestamp']);
        $mean=static function(string $key,array $rows): float {return array_sum(array_column($rows,$key))/count($rows);};
        $keys=['outside','incoming','outgoing','coil','hpWatts','fanWatts','target']; $p=['powerPercent'=>$power,'sampleCount'=>count($samples)];
        foreach ($keys as $key) $p[$key]=$mean($key,$samples);
        $p['airRise']=$p['outgoing']-$p['incoming']; $p['coilLift']=$p['coil']-$p['incoming'];
        $p['heatPumpKW']=max(0,($p['hpWatts']-$baseline['hpWatts'])/1000);
        $p['fanIncrementKW']=max(0,($p['fanWatts']-$baseline['fanWatts'])/1000);
        $p['systemInputKW']=($p['hpWatts']+$p['fanWatts'])/1000;
        $p['estimatedHeatKW']=0.000335*$airflow*$p['airRise'];
        $p['estimatedSystemCOP']=$p['systemInputKW']>0 ? $p['estimatedHeatKW']/$p['systemInputKW'] : null;
        $mid=$first+($last-$first)/2;
        $a=array_values(array_filter($samples,static fn($s)=>$s['timestamp']<=$mid));
        $b=array_values(array_filter($samples,static fn($s)=>$s['timestamp']>$mid));
        $p['stability']=['coverageSeconds'=>$last-$first,'maximumSampleGapSeconds'=>$maxGap];
        $stable=$last-$first>=$window-30 && $maxGap<=30 && count($a)>=3 && count($b)>=3;
        if ($a && $b) {
            $rise=static fn($s)=>$s['outgoing']-$s['incoming'];
            $p['stability']['airRiseHalfWindowChange']=abs(array_sum(array_map($rise,$b))/count($b)-array_sum(array_map($rise,$a))/count($a));
            $p['stability']['coilHalfWindowChange']=abs($mean('coil',$b)-$mean('coil',$a));
            $p['stability']['powerHalfWindowFraction']=abs($mean('hpWatts',$b)-$mean('hpWatts',$a))/max(100,$p['hpWatts']);
            $stable=$stable && $p['stability']['airRiseHalfWindowChange']<=0.5 && $p['stability']['coilHalfWindowChange']<=1.0 && $p['stability']['powerHalfWindowFraction']<=0.1;
        }
        $p['stable']=$stable;
        $p['usable']=$stable && $p['airRise']>0 && $p['heatPumpKW']>0.05
            && $p['estimatedSystemCOP']!==null && $p['estimatedSystemCOP']>=0.5 && $p['estimatedSystemCOP']<=10;
        return $p;
    }

    public static function relationships(array $points): array
    {
        $p=array_values(array_filter($points,static fn($p)=>($p['usable']??false)===true));
        usort($p,static fn($a,$b)=>$a['powerPercent']<=>$b['powerPercent']); $segments=[];
        for ($i=1;$i<count($p);$i++) {
            $a=$p[$i-1];$b=$p[$i];$d=$b['powerPercent']-$a['powerPercent'];if($d<=0)continue;
            $s=['fromPower'=>$a['powerPercent'],'toPower'=>$b['powerPercent']];
            foreach(['heatPumpKW','airRise','coilLift','estimatedSystemCOP'] as $key) $s[$key.'Per10Percent']=10*($b[$key]-$a[$key])/$d;
            $segments[]=$s;
        }
        return ['usablePointCount'=>count($p),'segments'=>$segments,
            'note'=>'Local measured power/temperature relationships at recorded weather, intake, fan and flap conditions; no weather-independent power assumption or extrapolated COP.'];
    }

    public static function choose(array $points,float $outside,float $incoming,float $coilMinimum,float $target,float $supplyMargin,float $minimumRise): ?array
    {
        $candidates=[];
        foreach($points as $p) {
            if(($p['usable']??false)!==true || abs($p['outside']-$outside)>3 || abs($p['incoming']-$incoming)>3)continue;
            // Temperature lifts permit only a small local intake correction.
            $p['predictedCoil']=$incoming+$p['coilLift'];$p['predictedOutgoing']=$incoming+$p['airRise'];
            if($p['predictedCoil']<$coilMinimum || $p['predictedOutgoing']<$target+$supplyMargin || $p['airRise']<$minimumRise)continue;
            $candidates[]=$p;
        }
        usort($candidates,static fn($a,$b)=>$a['powerPercent']<=>$b['powerPercent']);
        return $candidates[0]??null;
    }
}
