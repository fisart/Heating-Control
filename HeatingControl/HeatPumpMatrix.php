<?php
declare(strict_types=1);

/** Multi-power measured rows. Electrical and heat energy must cover the SAME interval. */
final class HeatPumpMatrix
{
    public static function seed(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-calibration.json'),true,64,JSON_THROW_ON_ERROR)['points'];
    }
    public static function validate(array $rows): array
    {
        if(!array_is_list($rows)||count($rows)>512)throw new InvalidArgumentException('Measured matrix needs a list of at most 512 points');
        foreach($rows as $r) {
            foreach(['powerPercent'=>[1,100],'outside'=>[-20,24],'incoming'=>[-10,40],'airRise'=>[0,70],
                'estimatedHeatKW'=>[0.01,80],'systemInputKW'=>[0.05,25],'estimatedSystemCOP'=>[0.5,10],'fanSpeed'=>[20,100],'airflowM3h'=>[100,10000]] as $k=>$limits) {
                $v=$r[$k]??null;
                if(!is_numeric($v)||!is_finite((float)$v)||$v<$limits[0]||$v>$limits[1])throw new InvalidArgumentException('Invalid matrix '.$k);
            }
            if(!is_int($r['powerPercent'])||!is_int($r['fanSpeed']))throw new InvalidArgumentException('Matrix commands must be integer percentages');
            if(($r['electricalMethod']??'')!=='house-counter-minus-pretest-baseline'||!is_bool($r['usable']??null)||!is_bool($r['stable']??null))throw new InvalidArgumentException('Matrix requires house-counter provenance and stability flags');
            if(abs($r['estimatedHeatKW']-$r['estimatedSystemCOP']*$r['systemInputKW'])>0.01)throw new InvalidArgumentException('Matrix COP does not match heat and electrical input');
        }
        return $rows;
    }
    public static function choose(array $rows,float $outside,float $incoming,float $target,float $margin,float $minRise,int $fan,float $airflow,float $tolerance,int $excludedFlap=0): ?array
    {
        if($outside < -20||$outside>24)return null;
        $nearest=[];
        foreach(self::validate($rows) as $r) {
            if(!$r['usable']||!$r['stable']||($excludedFlap>0&&isset($r['excludedFlapID'])&&$r['excludedFlapID']!==$excludedFlap)||$r['fanSpeed']!==$fan||abs($r['airflowM3h']-$airflow)>1
                ||abs($r['outside']-$outside)>$tolerance||abs($r['incoming']-$incoming)>$tolerance)continue;
            $r['distance']=abs($r['outside']-$outside)+abs($r['incoming']-$incoming);
            $key=$r['powerPercent'];
            if(!isset($nearest[$key])||$r['distance']<$nearest[$key]['distance']
                ||($r['distance']===$nearest[$key]['distance']&&($r['measuredAt']??0)>($nearest[$key]['measuredAt']??0)))$nearest[$key]=$r;
        }
        $candidates=[];
        foreach($nearest as $r) {
            $r['predictedOutgoing']=$incoming+$r['airRise'];
            if($r['airRise']<$minRise||$r['predictedOutgoing']<$target+$margin)continue;
            $candidates[]=$r;
        }
        // Best estimated COP among points satisfying the supply/demand requirements.
        usort($candidates,static fn($a,$b)=>($b['estimatedSystemCOP']<=>$a['estimatedSystemCOP'])?:($a['powerPercent']<=>$b['powerPercent']));
        return $candidates[0]??null;
    }
    public static function economics(array $point,float $gasCt,float $gasEfficiency,float $safety,float $band,float $price,int $previous): array
    {
        foreach([$gasCt,$gasEfficiency,$safety,$band,$price] as $v)if(!is_finite($v))throw new InvalidArgumentException('Nonfinite economic input');
        if($gasCt<=0||$gasEfficiency<50||$gasEfficiency>100||$safety<0||$safety>90||$band<0||$band>20)throw new InvalidArgumentException('Invalid economic configuration');
        $breakEven=$gasCt*$point['estimatedSystemCOP']/($gasEfficiency/100);
        $exit=$breakEven*(1-$safety/100);$entry=$exit-$band;
        return ['breakEvenCt'=>$breakEven,'exitCt'=>$exit,'entryCt'=>$entry,'breakEvenEuro'=>$breakEven/100,'exitEuro'=>$exit/100,'entryEuro'=>$entry/100,
            'source'=>$price<($previous===1?$exit:$entry)?1:2,'gasPriceCt'=>$gasCt,'gasEfficiencyPercent'=>$gasEfficiency,
            'safetyMarginPercent'=>$safety,'hysteresisCt'=>$band,'currentPriceCt'=>$price,
            'assumptions'=>'Estimated airflow; gas auxiliary electricity receives no credit; changing other house loads affects baseline subtraction.'];
    }
    public static function merge(array $existing,array $new): array
    {
        $rows=[];foreach(array_merge($existing,$new) as $r)$rows[$r['pointID']]=$r;
        return array_slice(array_values($rows),-512);
    }
    /** Integrate held telemetry with its original timestamps; reject uncovered/gapped data. */
    public static function mean(array $samples,string $key,int $start,int $end): float
    {
        usort($samples,static fn($a,$b)=>$a['timestamp']<=>$b['timestamp']);
        $last=null;$at=$start;$sum=0.0;
        foreach($samples as $r) {
            $t=$r['timestamp'];if($t<=$start){$last=$r;continue;}if($t>$end)break;
            if($last===null||$t-$at>30)throw new RuntimeException('Incomplete heat measurement interval');
            $sum+=(float)$last[$key]*($t-$at);$at=$t;$last=$r;
        }
        if($last===null||$end-$at>30)throw new RuntimeException('Incomplete heat measurement boundary');
        return ($sum+(float)$last[$key]*($end-$at))/($end-$start);
    }
    public static function counterAt(array $rows,int $time): float
    {
        usort($rows,static fn($a,$b)=>$a['TimeStamp']<=>$b['TimeStamp']);$previous=null;
        foreach($rows as $r) {
            if($r['TimeStamp']===$time)return (float)$r['Value'];
            if($r['TimeStamp']>$time) {
                if(!$previous||$r['TimeStamp']-$previous['TimeStamp']>90||$r['Value']<$previous['Value'])break;
                return $previous['Value']+($r['Value']-$previous['Value'])*($time-$previous['TimeStamp'])/($r['TimeStamp']-$previous['TimeStamp']);
            }
            $previous=$r;
        }
        throw new RuntimeException('House counter boundary missing, reset or gap exceeds 90 seconds');
    }
}
