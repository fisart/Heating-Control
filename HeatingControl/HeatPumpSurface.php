<?php
declare(strict_types=1);

/** Frozen fitted COP coefficients; modeled candidates are never measurement rows. */
final class HeatPumpSurface
{
    public static function definition(): array
    {
        static $model=null;
        if($model===null) {
            $model=json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-cop-surface.json'),true,64,JSON_THROW_ON_ERROR);
            if(($model['schema']??0)!==1)throw new RuntimeException('Invalid bundled COP surface');
        }
        return $model;
    }
    public static function observations(): array
    {
        static $points=null;
        if($points===null) {
            $file=__DIR__.'/../docs/data/heatpump-cop-surface-observations.json';$raw=file_get_contents($file);
            if(hash('sha256',$raw)!==self::definition()['source_sha256'])throw new RuntimeException('COP surface observation provenance mismatch');
            $rows=json_decode($raw,true,64,JSON_THROW_ON_ERROR)['points'];
            $points=array_values(array_filter($rows,static fn($p)=>$p['stable']&&$p['usable']));
            if(count($points)!==self::definition()['metrics']['accepted_points'])throw new RuntimeException('COP surface observation count mismatch');
        }
        return $points;
    }
    public static function cop(float $outside,int $power): float
    {
        $m=self::definition();$d=$m['domain'];
        if(!is_finite($outside)||$outside<$d['outside_min_C']||$outside>$d['outside_max_C']||$power<$d['power_min_percent']||$power>$d['power_max_percent'])
            throw new InvalidArgumentException('COP surface supports 5–16°C outside and 30–100% power only');
        $knots=$m['baseline_knot_power_percent'];$index=0;
        while($index<count($knots)-2&&$power>=$knots[$index+1])$index++;
        $dx=$power-$knots[$index];$c=$m['baseline_spline_coefficients'];
        $base=(($c[0][$index]*$dx+$c[1][$index])*$dx+$c[2][$index])*$dx+$c[3][$index];
        [$b0,$b1,$b2]=$m['temperature_bernstein_coefficients_per_10C'];$x=($power-30)/70;
        $cop=$base+($outside-12)/10*($b0*(1-$x)**2+2*$b1*$x*(1-$x)+$b2*$x*$x);
        if(!is_finite($cop)||$cop<0.5||$cop>10)throw new RuntimeException('Invalid COP surface evaluation');
        return $cop;
    }
    /** Capacity remains based on observed air rise; interpolate power within
     * each run, then outside temperature between those runs. Outside the
     * available capacity bracket, only the existing temperature tolerance
     * permits using the nearest endpoint. COP never extends beyond 5–16°C.
     */
    private static function capacity(int $power,float $outside,float $incoming,float $tolerance): ?array
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
        if(abs($incoming-$reference)>$tolerance)return null;
        return ['airRise'=>$before['airRise']+$f*($after['airRise']-$before['airRise']),
            'referenceIncoming'=>$reference,'thermalSupport'=>$support,
            'sourcePointIDs'=>array_values(array_unique(array_merge($before['sourcePointIDs'],$after['sourcePointIDs'])))];
    }
    public static function choose(float $outside,float $incoming,float $target,float $margin,float $minRise,int $fan,float $airflow,float $tolerance,int $excludedFlap): ?array
    {
        $m=self::definition();$d=$m['domain'];$basis=$m['reference_basis'];
        foreach([$outside,$incoming,$target,$margin,$minRise,$airflow,$tolerance] as $v)if(!is_finite($v))throw new InvalidArgumentException('Nonfinite surface input');
        if($outside<$d['outside_min_C']||$outside>$d['outside_max_C']||$fan!==$basis['fan_command']||abs($airflow-$basis['airflow_m3h'])>1||$excludedFlap!==$m['excluded_flap_id']||$tolerance<0||$tolerance>3)return null;
        $best=null;
        for($power=30;$power<=100;$power++) {
            $capacity=self::capacity($power,$outside,$incoming,$tolerance);
            if(!$capacity||$capacity['airRise']<$minRise||$incoming+$capacity['airRise']<$target+$margin)continue;
            $cop=self::cop($outside,$power);
            if($best!==null&&$cop<=$best['estimatedSystemCOP'])continue;
            $heat=0.000335*$airflow*$capacity['airRise'];
            $best=['powerPercent'=>$power,'outside'=>$outside,'incoming'=>$incoming,'predictedOutgoing'=>$incoming+$capacity['airRise'],
                'airflowM3h'=>$airflow,'fanSpeed'=>$fan,'estimatedSystemCOP'=>$cop,'estimatedHeatKW'=>$heat,'systemInputKW'=>$heat/$cop,
                'modeled'=>true,'copMethod'=>'surface','surfaceVersion'=>$m['version'],'electricalMethod'=>$basis['electrical_method'],
                'meterScope'=>'frozen fit of historical household increments; not the new dedicated meter',
                'modelValidationRMSECOP'=>$m['metrics']['leave_one_run_out_rmse_cop']]+$capacity;
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
        return sprintf('No supported surface candidate meets intake/capacity tolerance and air-supply requirements at %.1f°C intake, target %.1f°C + %.1f°C; no cold-intake holiday performance or high-power cold capacity is invented',$incoming,$target,$margin);
    }
}
