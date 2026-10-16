<?php
declare(strict_types=1);
require_once __DIR__.'/HeatPumpMatrix.php';

/** Reviewed thin-plate surface; modeled candidates never become measurement rows. */
final class HeatPumpSurface
{
    public static function definition(): array
    {
        static $model=null;
        if($model===null) {
            $model=json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-cop-surface.json'),true,64,JSON_THROW_ON_ERROR);
            if(($model['schema']??0)!==2||count($model['centers_normalized']??[])!==($model['metrics']['accepted_points']??0)
                ||count($model['coefficients']??[])!==count($model['centers_normalized'])+3)
                throw new RuntimeException('Invalid bundled COP surface');
            foreach(array_merge($model['centers_normalized'],$model['coefficients']) as $row)
                foreach($row as $v)if(!is_numeric($v)||!is_finite((float)$v))throw new RuntimeException('Invalid surface coefficient');
        }
        return $model;
    }
    public static function observations(): array
    {
        static $points=null;
        if($points===null) {
            $raw=file_get_contents(__DIR__.'/../docs/data/heatpump-cop-surface-observations.json');
            if(hash('sha256',$raw)!==self::definition()['source_sha256'])throw new RuntimeException('COP surface observation provenance mismatch');
            $rows=HeatPumpMatrix::validate(json_decode($raw,true,64,JSON_THROW_ON_ERROR)['points']);
            $points=array_values(array_filter($rows,static fn($p)=>$p['stable']&&$p['usable']));
            if(count($points)!==self::definition()['metrics']['accepted_points'])throw new RuntimeException('COP surface observation count mismatch');
        }
        return $points;
    }
    /** Same basis and coefficients as the 45 x 71 analysis matrix. */
    public static function evaluate(float $outside,int $power): array
    {
        $m=self::definition();$d=$m['domain'];$norm=$m['normalization'];
        if(!is_finite($outside)||$outside<$d['outside_min_C']||$outside>$d['outside_max_C']||$power<$d['power_min_percent']||$power>$d['power_max_percent'])
            throw new InvalidArgumentException('COP surface supports 5–16°C outside and 30–100% power only');
        self::observations();
        $x=($outside-$norm['outside_center_C'])/$norm['outside_scale_C'];
        $y=($power-$norm['power_center_percent'])/$norm['power_scale_percent'];
        $n=count($m['centers_normalized']);$values=[0.0,0.0,0.0];
        foreach($m['centers_normalized'] as $i=>[$cx,$cy]) {
            $r2=($x-$cx)**2+($y-$cy)**2;
            $basis=$r2>0?0.5*$r2*log($r2):0.0;
            for($j=0;$j<3;$j++)$values[$j]+=$basis*$m['coefficients'][$i][$j];
        }
        foreach([1.0,$x,$y] as $i=>$basis)for($j=0;$j<3;$j++)$values[$j]+=$basis*$m['coefficients'][$n+$i][$j];
        [$rise,$electric,$reference]=$values;$heat=0.000335*$m['reference_basis']['airflow_m3h']*$rise;
        $cop=$electric>0?$heat/$electric:NAN;
        foreach(array_merge($values,[$cop]) as $v)if(!is_finite($v))throw new RuntimeException('Nonfinite surface evaluation');
        if($rise<=0||$electric<=0.05||$cop<0.5||$cop>10)throw new RuntimeException('Nonphysical COP surface evaluation');
        $inside=true;$hull=$m['support_hull_normalized'];
        foreach($hull as $i=>[$ax,$ay]) {
            [$bx,$by]=$hull[($i+1)%count($hull)];
            if(($bx-$ax)*($y-$ay)-($by-$ay)*($x-$ax)<-1e-10){$inside=false;break;}
        }
        return ['airRise'=>$rise,'systemInputKW'=>$electric,'estimatedHeatKW'=>$heat,'estimatedSystemCOP'=>$cop,
            'referenceIncoming'=>$reference,'surfaceSupport'=>$inside?'interpolation':'extrapolation'];
    }
    public static function cop(float $outside,int $power): float
    {
        return self::evaluate($outside,$power)['estimatedSystemCOP'];
    }
    /** Qualify air rise from observed within-run power brackets, then weather.
     * Absolute observed inlet is descriptive; current inlet determines required rise.
     * A nearest weather endpoint requires the existing temperature tolerance. */
    private static function capacity(int $power,float $outside,float $tolerance): ?array
    {
        $runs=[];foreach(self::observations() as $p)$runs[$p['runID']][]=$p;
        $slices=[];
        foreach($runs as $run=>$points) {
            usort($points,static fn($a,$b)=>$a['powerPercent']<=>$b['powerPercent']);
            $before=null;$after=null;
            foreach($points as $p) {
                if($p['powerPercent']<=$power)$before=$p;
                if($p['powerPercent']>=$power){$after=$p;break;}
            }
            if(!$before||!$after)continue; // Never invent an untested run's high-power capacity.
            $fraction=$after['powerPercent']===$before['powerPercent']?0:($power-$before['powerPercent'])/($after['powerPercent']-$before['powerPercent']);
            $row=['runID'=>$run];
            foreach(['outside','incoming'] as $key)$row[$key]=$before[$key]+$fraction*($after[$key]-$before[$key]);
            // Full-step temperature means correspond to the energy intervals.
            $riseA=$before['outgoing']-$before['incoming'];$riseB=$after['outgoing']-$after['incoming'];
            $row['airRise']=$riseA+$fraction*($riseB-$riseA);
            $row['sourcePointIDs']=array_values(array_unique([$before['pointID'],$after['pointID']]));$slices[]=$row;
        }
        usort($slices,static fn($a,$b)=>$a['outside']<=>$b['outside']);
        if(!$slices)return null;
        $before=null;$after=null;
        foreach($slices as $s){if($s['outside']<=$outside)$before=$s;if($s['outside']>=$outside){$after=$s;break;}}
        $support='interpolated air rise';
        if(!$before||!$after) {
            $nearest=$before??$after;
            if(abs($outside-$nearest['outside'])>$tolerance)return null;
            $before=$after=$nearest;$support='capacity boundary within configured temperature tolerance';
        }
        $f=$after['outside']===$before['outside']?0:($outside-$before['outside'])/($after['outside']-$before['outside']);
        $reference=$before['incoming']+$f*($after['incoming']-$before['incoming']);
        return ['airRise'=>$before['airRise']+$f*($after['airRise']-$before['airRise']),
            'referenceIncoming'=>$reference,'thermalSupport'=>$support,
            'sourcePointIDs'=>array_values(array_unique(array_merge($before['sourcePointIDs'],$after['sourcePointIDs'])))];
    }

    public static function choose(float $outside,float $incoming,float $target,float $margin,float $minRise,int $fan,float $airflow,float $tolerance,int $excludedFlap): ?array
    {
        $m=self::definition();$d=$m['domain'];$basis=$m['reference_basis'];
        foreach([$outside,$incoming,$target,$margin,$minRise,$airflow,$tolerance] as $v)if(!is_finite($v))throw new InvalidArgumentException('Nonfinite surface input');
        if($outside<$d['outside_min_C']||$outside>$d['outside_max_C']||$fan!==$basis['fan_command']
            ||abs($airflow-$basis['airflow_m3h'])>1||$excludedFlap!==$m['excluded_flap_id']||$tolerance<0||$tolerance>3)return null;
        $observations=self::observations();$best=null;
        for($power=30;$power<=100;$power++) {
            $surface=self::evaluate($outside,$power);
            if($surface['airRise']<$minRise||$incoming+$surface['airRise']<$target+$margin)continue;
            $capacity=self::capacity($power,$outside,$tolerance);
            if(!$capacity||$capacity['airRise']<$minRise||$incoming+$capacity['airRise']<$target+$margin)continue;
            if($best!==null&&$surface['estimatedSystemCOP']<=$best['estimatedSystemCOP'])continue;
            $best=$surface+['powerPercent'=>$power,'outside'=>$outside,'incoming'=>$incoming,'predictedOutgoing'=>$incoming+$surface['airRise'],
                'requiredAirRise'=>max($minRise,$target+$margin-$incoming),'airflowM3h'=>$airflow,'fanSpeed'=>$fan,'modeled'=>true,'copMethod'=>'surface','surfaceVersion'=>$m['version'],
                'electricalMethod'=>$basis['electrical_method'],'electricalSourceMethods'=>$basis['electrical_source_methods'],
                'meterScope'=>'reviewed fit: 20 historical house-baseline points and 7 dedicated heat-pump import points; modeled input, not a meter reading',
                'modelValidationRMSECOP'=>$m['metrics']['leave_one_run_out_rmse_COP'],
                'sourcePointIDs'=>array_column($observations,'pointID'),'capacitySourcePointIDs'=>$capacity['sourcePointIDs'],
                'capacityReferenceIncoming'=>$capacity['referenceIncoming'],'capacityAirRise'=>$capacity['airRise'],'thermalSupport'=>$capacity['thermalSupport']];
        }
        return $best;
    }
    public static function unavailableReason(float $outside,float $incoming,float $target,float $margin,int $fan,float $airflow,int $flap): string
    {
        $m=self::definition();
        if($outside<5||$outside>16)return sprintf('COP surface supports 5–16°C outside; current outside air %.1f°C',$outside);
        if($fan!==90)return 'COP surface requires tested fan command 90% (full physical speed)';
        if(abs($airflow-2000)>1)return 'COP surface requires its 2,000 m³/h airflow basis';
        if($flap!==$m['excluded_flap_id'])return 'COP surface requires the tested excluded master-bedroom flap';
        return sprintf('No supported surface candidate meets modeled and observed air-rise requirements at %.1f°C intake, target %.1f°C + %.1f°C supply margin',$incoming,$target,$margin);
    }
}
