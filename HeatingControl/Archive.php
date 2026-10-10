<?php
declare(strict_types=1);

/** Read-only archive access, restricted to this instance's temperature sensors. */
trait HeatingControlArchive
{
    private const ARCHIVE_MODULE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    private function webArchiveForSensor(int $id): int
    {
        if ($id <= 1 || !IPS_VariableExists($id) || !in_array(IPS_GetVariable($id)['VariableType'], [1, 2], true)
            || !function_exists('AC_GetLoggingStatus') || !function_exists('AC_GetAggregationType')
            || !function_exists('AC_GetAggregatedValues')) return 0;
        $eligible = [];
        foreach (IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE) as $archive) {
            try {
                // Counter aggregation has different units and cannot represent a temperature.
                if (AC_GetLoggingStatus((int)$archive, $id) === true && AC_GetAggregationType((int)$archive, $id) === 0) {
                    $eligible[] = (int)$archive;
                }
            } catch (Throwable $e) { return 0; } // Unreadable archive configuration fails closed.
        }
        return count($eligible) === 1 ? $eligible[0] : 0;
    }

    private function webHistory(array $query): array
    {
        if (!is_string($query['id'] ?? null) || !preg_match('/^[1-9][0-9]{0,9}$/D', $query['id'])) {
            throw new InvalidArgumentException('Select a configured, archived temperature sensor.');
        }
        $id = (int)$query['id'];
        $allowed = [];
        foreach (['OutsideTempID', 'IncomingAirTempID', 'OutgoingAirTempID', 'HeatExchangerTempID', 'HeatPumpTempID'] as $key) {
            $allowed[] = $this->id($key);
        }
        foreach ($this->rooms() as $room) $allowed = array_merge($allowed, $room['sensors']);
        if (!in_array($id, $allowed, true)) throw new InvalidArgumentException('This variable is not a configured heating sensor.');
        $range = $query['range'] ?? '24h';
        if (!is_string($range) || !in_array($range, ['6h', '24h', '7d', '30d'], true)) {
            throw new InvalidArgumentException('Select 6 hours, 24 hours, 7 days or 30 days.');
        }
        $archive = $this->webArchiveForSensor($id);
        if ($archive === 0) throw new InvalidArgumentException('This sensor is not recorded in an available standard Symcon archive.');
        $hours = ['6h'=>6, '24h'=>24, '7d'=>168, '30d'=>720][$range];
        $end = time();
        $daily = $range === '30d';
        $start = $daily ? strtotime('midnight', $end - $hours * 3600) : (int)(floor(($end - $hours * 3600) / 3600) * 3600);
        // Hourly/daily pre-aggregation bounds the work; no unbounded raw-history scan.
        $values = AC_GetAggregatedValues($archive, $id, $daily ? 1 : 0, $start, $end, 513);
        if (!is_array($values)) throw new RuntimeException('Archive returned an invalid history response.');
        $truncated = count($values) > 512;
        $points = [];
        foreach (array_slice($values, 0, 512) as $row) {
            if (!is_array($row) || !is_int($row['TimeStamp'] ?? null)
                || $row['TimeStamp'] < $start || $row['TimeStamp'] > $end
                || (!is_float($row['Avg'] ?? null) && !is_int($row['Avg'] ?? null)) || !is_finite((float)$row['Avg'])) continue;
            $avg = (float)$row['Avg'];
            $min = $row['Min'] ?? $avg;
            $max = $row['Max'] ?? $avg;
            if ((!is_float($min) && !is_int($min)) || !is_finite((float)$min)) $min = $avg;
            if ((!is_float($max) && !is_int($max)) || !is_finite((float)$max)) $max = $avg;
            $points[] = ['time'=>$row['TimeStamp'], 'value'=>$avg, 'min'=>(float)$min, 'max'=>(float)$max,
                'duration'=>max(1, min(172800, (int)($row['Duration'] ?? ($daily ? 86400 : 3600))))];
        }
        usort($points, static fn($a, $b)=>$a['time'] <=> $b['time']);
        return ['id'=>$id, 'name'=>IPS_GetName($id), 'unit'=>'°C', 'range'=>$range,
            'from'=>$start, 'to'=>$end, 'aggregation'=>$daily ? 'daily' : 'hourly',
            'points'=>$points, 'truncated'=>$truncated];
    }
}
