<?php
declare(strict_types=1);

/** Provisional empirical-reference model; no claim of measured COP/optimal capacity. */
final class LichtBlickWeatherModel
{
    public const DEFAULT_CURVE = '[{"outsideC":-7,"powerPercent":65,"cop":2.7,"heatPumpKW":3.19},{"outsideC":2,"powerPercent":50,"cop":3.75,"heatPumpKW":2.12},{"outsideC":7,"powerPercent":35,"cop":5.78,"heatPumpKW":1.0},{"outsideC":12,"powerPercent":35,"cop":7.26,"heatPumpKW":1.0}]';

    public static function curve(string $json): array
    {
        $rows = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 2 || count($rows) > 30) {
            throw new InvalidArgumentException('Weather curve needs 2–30 rows');
        }
        foreach ($rows as $r) {
            if (!is_array($r) || array_diff(array_keys($r), ['outsideC','powerPercent','cop','heatPumpKW'])) {
                throw new InvalidArgumentException('Invalid weather curve columns');
            }
            foreach (['outsideC'=>[-30,30], 'powerPercent'=>[1,100], 'cop'=>[0.5,10], 'heatPumpKW'=>[0.05,15]] as $key=>$range) {
                $v = $r[$key] ?? null;
                if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || $v < $range[0] || $v > $range[1]) {
                    throw new InvalidArgumentException('Invalid weather curve ' . $key);
                }
            }
            if ((float)$r['powerPercent'] !== (float)(int)$r['powerPercent']) throw new InvalidArgumentException('Curve power must be a whole percentage');
        }
        usort($rows, fn($a,$b)=>$a['outsideC'] <=> $b['outsideC']);
        foreach ($rows as $i=>$row) if ($i && $row['outsideC'] <= $rows[$i-1]['outsideC']) {
            throw new InvalidArgumentException('Duplicate outside temperature in weather curve');
        }
        return $rows;
    }

    public static function point(array $rows, float $outside, int $step): array
    {
        if (!is_finite($outside) || $outside < $rows[0]['outsideC']) throw new RuntimeException('Outside temperature below weather curve; use gas');
        $last = $rows[count($rows)-1];
        $point = $last; // Hold the warmest tested point, never extrapolate a higher COP.
        foreach ($rows as $i=>$r) {
            if ($outside > $r['outsideC']) continue;
            if (!$i) { $point=$r; break; }
            $a=$rows[$i-1]; $fraction=($outside-$a['outsideC'])/($r['outsideC']-$a['outsideC']);
            $point=['outsideC'=>$outside];
            foreach (['powerPercent','cop','heatPumpKW'] as $key) $point[$key]=$a[$key]+$fraction*($r[$key]-$a[$key]);
            break;
        }
        // Quantize to useful commands; avoid one-percent writes on every sensor fluctuation.
        $point['powerPercent']=max(1,min(100,(int)(round($point['powerPercent']/$step)*$step)));
        return $point;
    }

    public static function factor(array $c, array $point): float
    {
        $rows=self::curve($c['WeatherCurve']);
        $reference=self::point($rows,(float)$c['WeatherReferenceTemperatureC'],(int)$c['WeatherPowerStepPercent']);
        $net=fn($p)=>$p['cop']*$p['heatPumpKW']/($p['heatPumpKW']+$c['WeatherFanKW']);
        return $c['GasPriceCt']/$c['WeatherReferenceGasPriceCt']*$net($point)/$net($reference);
    }

    public static function estimate(array $c, float $outside): array
    {
        $p=self::point(self::curve($c['WeatherCurve']),$outside,$c['WeatherPowerStepPercent']);
        $p['systemCOP']=$p['cop']*$p['heatPumpKW']/($p['heatPumpKW']+$c['WeatherFanKW']);
        $p['factor']=self::factor($c,$p);
        $p['thresholdCt']=$c['ThresholdCt']*$p['factor'];
        return $p;
    }
}
