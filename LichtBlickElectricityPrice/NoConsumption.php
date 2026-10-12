<?php
declare(strict_types=1);

/** User-confirmed no-use periods affect presentation, never the original archives. */
final class LichtBlickNoConsumption
{
    public static function periods(string $json): array
    {
        if (strlen($json)>65536) throw new InvalidArgumentException('Confirmed no-use periods are too large');
        $rows=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>50) throw new InvalidArgumentException('Use a list of confirmed no-use periods');
        foreach ($rows as &$row) {
            if (!is_string($row['from']??null) || !is_string($row['through']??null)
                || !is_array($row['utilities']??null) || !array_is_list($row['utilities']) || !$row['utilities']
                || array_diff($row['utilities'],['electricity','gas','water'])
                || !is_string($row['source']??null) || trim($row['source'])==='' || strlen($row['source'])>256) {
                throw new InvalidArgumentException('Confirmed no-use dates, utilities and source are required');
            }
            [$row['start'],$row['end']]=LichtBlickPriceStore::dates($row['from'],$row['through'],366);
        }
        unset($row); return $rows;
    }

    public static function apply(array $rows, array $periods, string $utility): array
    {
        foreach ($rows as &$row) foreach ($periods as $period) {
            if (!in_array($utility,$period['utilities'],true) || $row['start']<$period['start'] || $row['end']>$period['end']) continue;
            $row['confirmedNoConsumption']=true; $row['noConsumptionSource']=$period['source'];
            $row['qualityFlags']|=8192;
            if (in_array($utility,['gas','water'],true)) {
                $row['originalObservedM3']=$row['observedM3'];
                if (($row['observedM3']??0)>0) $row['qualityFlags']|=16384;
                $row['observedM3']=0.0;
            } elseif ($utility==='electricity') {
                $row['originalCleanedKWh']=$row['cleanedKWh']; $row['originalReferenceKWh']=$row['referenceKWh'];
                if (($row['cleanedKWh']??0)>0 || ($row['referenceKWh']??0)>0) $row['qualityFlags']|=16384;
                $row['cleanedKWh']=$row['referenceKWh']=0.0; $row['estimatedResetKWh']=0.0;
                // Consumption charges are zero; a documented fixed charge is retained.
                $row['tariffCostEuro']=$row['fixedCostEuro']??0.0;
            }
            break;
        }
        unset($row); return $rows;
    }
}
