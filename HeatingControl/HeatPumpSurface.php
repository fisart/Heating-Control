<?php
declare(strict_types=1);
require_once __DIR__.'/HeatPumpMatrix.php';

/** Reviewed intake-aware smooth model; predictions never become measured rows. */
final class HeatPumpSurface
{
    public static function definition(): array
    {
        static $model=null;
        if($model===null) {
            $model=json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-cop-surface.json'),true,64,JSON_THROW_ON_ERROR);
            if(($model['schema']??0)!==3||($model['feature_exponents']??[])!==[[0,0,0],[1,0,0],[0,1,0],[0,2,0],[0,3,0],[0,0,1],[1,1,0],[0,1,1]]
                ||count($model['coefficients']??[])!==8||count($model['support_hull_equations_normalized']??[])<4)
                throw new RuntimeException('Invalid bundled COP surface');
            foreach($model['coefficients'] as $row)if(count($row)!==2)throw new RuntimeException('Invalid surface response columns');
            foreach($model['support_hull_equations_normalized'] as $row)if(count($row)!==4)throw new RuntimeException('Invalid surface support plane');
            foreach(array_merge($model['coefficients'],$model['support_hull_equations_normalized']) as $row)
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
            $points=array_values(array_filter($rows,static fn($p)=>$p['stable']&&$p['usable']&&!isset($p['supersededBy'])));
            if(count($points)!==self::definition()['metrics']['accepted_points'])throw new RuntimeException('COP surface observation count mismatch');
        }
        return $points;
    }
    /** The optional inlet is only a documented reference for offline chart callers.
     * Runtime selection always supplies the actual measured intake temperature. */
    public static function evaluate(float $outside,int $power,?float $incoming=null): array
    {
        $m=self::definition();$d=$m['domain'];$norm=$m['normalization'];
        if(!is_finite($outside)||$outside<$d['outside_min_C']||$outside>$d['outside_max_C']||$power<$d['power_min_percent']||$power>$d['power_max_percent'])
            throw new InvalidArgumentException('COP surface supports 3.25–16°C outside and 30–100% power only');
        $incoming??=$m['reference_basis']['chart_incoming_C'];
        if(!is_finite($incoming)||$incoming<$d['incoming_min_C']||$incoming>$d['incoming_max_C'])
            throw new InvalidArgumentException('COP surface supports 9.5–25.25°C intake only');
        self::observations();
        $x=($outside-$norm['outside_center_C'])/$norm['outside_scale_C'];
        $y=($power-$norm['power_center_percent'])/$norm['power_scale_percent'];
        $z=($incoming-$norm['incoming_center_C'])/$norm['incoming_scale_C'];$values=[0.0,0.0];
        foreach($m['feature_exponents'] as $i=>[$a,$b,$c]) {
            $basis=$x**$a*$y**$b*$z**$c;
            for($j=0;$j<2;$j++)$values[$j]+=$basis*$m['coefficients'][$i][$j];
        }
        [$cop,$electric]=array_map('exp',$values);$heat=$cop*$electric;
        $rise=$heat/(0.000335*$m['reference_basis']['airflow_m3h']);
        foreach([$rise,$electric,$cop] as $v)if(!is_finite($v))throw new RuntimeException('Nonfinite surface evaluation');
        if($rise<=0||$electric<=0.05||$cop<0.5||$cop>10)throw new RuntimeException('Nonphysical COP surface evaluation');
        $inside=true;
        foreach($m['support_hull_equations_normalized'] as [$a,$b,$c,$offset]) {
            if($a*$x+$b*$y+$c*$z+$offset>1e-9){$inside=false;break;}
        }
        return ['airRise'=>$rise,'systemInputKW'=>$electric,'estimatedHeatKW'=>$heat,'estimatedSystemCOP'=>$cop,
            'referenceIncoming'=>$incoming,'surfaceSupport'=>$inside?'interpolation':'extrapolation'];
    }
    public static function cop(float $outside,int $power,?float $incoming=null): float
    {
        return self::evaluate($outside,$power,$incoming)['estimatedSystemCOP'];
    }
    /** Bounded read-only chart payload, requested only when its tab opens. */
    public static function chartData(): array
    {
        $m=self::definition();$temperatures=[];$powers=range(30,100,5);$slices=[];
        for($t=$m['domain']['outside_min_C'];$t<$m['domain']['outside_max_C'];$t+=0.5)$temperatures[]=$t;
        $temperatures[]=$m['domain']['outside_max_C'];
        foreach([10.0,15.0,20.0,25.0] as $incoming) {
            $grid=[];foreach($temperatures as $outside) {
                $row=[];foreach($powers as $power)$row[]=self::evaluate($outside,$power,$incoming);
                $grid[]=$row;
            }
            $slices[]=['incoming'=>$incoming,'grid'=>$grid];
        }
        $points=[];foreach(self::observations() as $p)$points[]=[
            'pointID'=>$p['pointID'],'runID'=>$p['runID'],'outside'=>$p['outside'],'powerPercent'=>$p['powerPercent'],
            'incoming'=>$p['incoming'],'airRise'=>$p['outgoing']-$p['incoming'],'estimatedHeatKW'=>$p['estimatedHeatKW'],
            'systemInputKW'=>$p['systemInputKW'],'estimatedSystemCOP'=>$p['estimatedSystemCOP'],
            'originalStable'=>$p['originalStable']??$p['stable'],'excludedSeconds'=>$p['excludedSeconds']??0];
        return ['version'=>$m['version'],'metrics'=>$m['metrics'],'temperatures'=>$temperatures,'powers'=>$powers,
            'grid'=>$slices[1]['grid'],'slices'=>$slices,'selectedIncoming'=>$m['reference_basis']['chart_incoming_C'],
            'points'=>$points,'latestRunID'=>'20261111-163534-83da3a7115b8',
            'assumptions'=>'Select the intake temperature for the mesh. Measurement markers retain their own recorded intake and do not all lie on that slice. Bundled reviewed fit; custom matrices affect measured-point mode. Airflow is assumed 2,000 m³/h; fan command 90% (full speed). Mixed electrical scopes. Recorded interruption/recovery intervals remain excluded; no generic defrost or cycling correction is applied. Absolute COP is provisional. Faded cells are outside the three-input observation hull.'];
    }
    /** Qualify air rise from observed within-run power brackets, then weather.
     * Absolute observed inlet is descriptive; current inlet determines required rise.
     * A nearest weather endpoint requires the existing temperature tolerance. */
    private static function capacity(int $power,float $outside,float $tolerance): ?array
    {
        $thermal=json_decode(file_get_contents(__DIR__.'/../docs/data/heatpump-cop-surface-observations.json'),true,64,JSON_THROW_ON_ERROR)['thermalOnlyObservations']??[];
        foreach($thermal as $p) {
            foreach(['outside','incoming','outgoing','powerPercent'] as $key)if(!is_numeric($p[$key]??null)||!is_finite((float)$p[$key]))throw new RuntimeException('Invalid thermal-only observation');
            if(($p['electricalQualified']??true)!==false||($p['estimatedSystemCOP']??null)!==null)throw new RuntimeException('Thermal-only observation must not invent COP');
        }
        $runs=[];foreach(array_merge(self::observations(),$thermal) as $p)$runs[$p['runID']][]=$p;
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
        if($outside<$d['outside_min_C']||$outside>$d['outside_max_C']||$incoming<$d['incoming_min_C']||$incoming>$d['incoming_max_C']||$fan!==$basis['fan_command']
            ||$tolerance<0||$tolerance>3)return null;
        $observations=self::observations();$best=null;
        for($power=30;$power<=100;$power++) {
            $surface=self::evaluate($outside,$power,$incoming);
            if($surface['airRise']<$minRise||$incoming+$surface['airRise']<$target+$margin)continue;
            $capacity=self::capacity($power,$outside,$tolerance);
            if(!$capacity||$capacity['airRise']<$minRise||$incoming+$capacity['airRise']<$target+$margin)continue;
            if($best!==null&&$surface['estimatedSystemCOP']<=$best['estimatedSystemCOP'])continue;
            $best=$surface+['powerPercent'=>$power,'outside'=>$outside,'incoming'=>$incoming,'predictedOutgoing'=>$incoming+$surface['airRise'],
                'requiredAirRise'=>max($minRise,$target+$margin-$incoming),'airflowM3h'=>$basis['airflow_m3h'],'configuredAirflowM3h'=>$airflow,'fanSpeed'=>$fan,'modeled'=>true,'copMethod'=>'surface','surfaceVersion'=>$m['version'],
                'electricalMethod'=>$basis['electrical_method'],'electricalSourceMethods'=>$basis['electrical_source_methods'],
                'meterScope'=>sprintf('reviewed fit: %d historical house-baseline points and %d dedicated heat-pump import points; modeled input, not a meter reading',$m['metrics']['legacy_house_meter_points'],$m['metrics']['dedicated_meter_points']),
                'modelValidationRMSECOP'=>$m['metrics']['leave_one_run_out_rmse_COP'],
                'sourcePointIDs'=>array_column($observations,'pointID'),'capacitySourcePointIDs'=>$capacity['sourcePointIDs'],
                'capacityReferenceIncoming'=>$capacity['referenceIncoming'],'capacityAirRise'=>$capacity['airRise'],'thermalSupport'=>$capacity['thermalSupport']];
        }
        return $best;
    }
    public static function unavailableReason(float $outside,float $incoming,float $target,float $margin,int $fan,float $airflow,int $flap): string
    {
        $m=self::definition();
        if($outside<$m['domain']['outside_min_C']||$outside>$m['domain']['outside_max_C'])return sprintf('COP surface supports 3.25–16°C outside; current outside air %.1f°C',$outside);
        if($incoming<$m['domain']['incoming_min_C']||$incoming>$m['domain']['incoming_max_C'])return sprintf('COP surface supports 9.5–25.25°C intake; current intake %.1f°C',$incoming);
        if($fan!==90)return 'COP surface requires tested fan command 90% (full physical speed)';
        return sprintf('No supported surface candidate meets modeled and observed air-rise requirements at %.1f°C intake, target %.1f°C + %.1f°C supply margin',$incoming,$target,$margin);
    }
}


