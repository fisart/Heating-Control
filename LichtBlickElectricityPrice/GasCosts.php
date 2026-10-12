<?php
declare(strict_types=1);

/** Independent observed-volume valuation; never allocates invoice residuals or aid. */
final class LichtBlickGasCosts
{
    public static function tariffs(string $json): array
    {
        if (strlen($json)>65536) throw new InvalidArgumentException('Gas tariff history is too large');
        $rows=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>100) throw new InvalidArgumentException('Use a list of at most 100 gas tariff periods');
        $result=[]; $previousEnd=0;
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['from']??null)
                || !array_key_exists('through',$row) || ($row['through']!==null && !is_string($row['through']))
                || !is_bool($row['conversionEstimated']??null)) throw new InvalidArgumentException('Invalid gas tariff period');
            [$a]=LichtBlickPriceStore::dates($row['from'],$row['from']);
            $b=$row['through']===null ? PHP_INT_MAX : LichtBlickPriceStore::dates($row['from'],$row['through'],2192)[1];
            if ($a<$previousEnd || $b<=$a) throw new InvalidArgumentException('Gas tariff periods overlap or are not ordered');
            foreach (['unitGrossCt'=>[0,1000],'basicAnnualGrossEuro'=>[0,10000],'basicDayDivisor'=>[365,366],'kwhPerM3'=>[1,20]] as $key=>$bounds) {
                $value=$row[$key]??null;
                if (!is_numeric($value) || !is_finite((float)$value) || $value<$bounds[0] || $value>$bounds[1]) throw new InvalidArgumentException('Invalid gas tariff value: '.$key);
                $row[$key]=(float)$value;
            }
            if (!in_array($row['basicDayDivisor'],[365.,366.],true)) throw new InvalidArgumentException('Select a 365- or 366-day basic-charge convention');
            foreach (['source','conversionSource'] as $key) if (!is_string($row[$key]??null) || trim($row[$key])==='' || strlen($row[$key])>256) throw new InvalidArgumentException('Gas tariff and conversion provenance are required');
            $row['start']=$a; $row['end']=$b; $result[]=$row; $previousEnd=$b;
        }
        return $result;
    }

    public static function apply(array $points, array $tariffs): array
    {
        $zone=new DateTimeZone('Europe/Berlin'); $cursor=0; $n=count($tariffs);
        foreach ($points as &$point) {
            while ($cursor<$n && $tariffs[$cursor]['end']<=$point['start']) $cursor++;
            $rate=$tariffs[$cursor]??null;
            $valid=$rate && $rate['start']<=$point['start'] && $rate['end']>=$point['end'];
            $point['unitGrossCt']=null; $point['convertedKWh']=null; $point['variableCostEuro']=null;
            $point['fixedCostEuro']=null; $point['knownCalculatedCostEuro']=null; $point['calculatedCostEuro']=null;
            $point['conversionEstimated']=false; $point['tariffMissing']=!$valid;
            if (!$valid) { $point['qualityFlags']|=2048; continue; }
            $day=(new DateTimeImmutable('@'.$point['start']))->setTimezone($zone)->setTime(0,0);
            $daySeconds=$day->modify('+1 day')->getTimestamp()-$day->getTimestamp();
            $fraction=($point['end']-$point['start'])/$daySeconds;
            $point['unitGrossCt']=$rate['unitGrossCt'];
            $point['fixedCostEuro']=$rate['basicAnnualGrossEuro']/$rate['basicDayDivisor']*$fraction;
            $point['conversionEstimated']=$rate['conversionEstimated'] && !($point['confirmedNoConsumption']??false);
            if ($point['conversionEstimated']) $point['qualityFlags']|=4096;
            if ($point['observedM3']!==null) {
                $point['convertedKWh']=$point['observedM3']*$rate['kwhPerM3'];
                $point['variableCostEuro']=$point['convertedKWh']*$rate['unitGrossCt']/100;
            }
            $point['knownCalculatedCostEuro']=$point['fixedCostEuro']+($point['variableCostEuro']??0.0);
            if ($point['observedM3']!==null && (($point['confirmedNoConsumption']??false) || !($point['qualityFlags']&(1|8|256|512)))) $point['calculatedCostEuro']=$point['knownCalculatedCostEuro'];
        }
        unset($point); return $points;
    }
}
