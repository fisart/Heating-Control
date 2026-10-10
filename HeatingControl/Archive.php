<?php
declare(strict_types=1);

/** Read-only archive access, restricted to this instance's temperature sensors. */
trait HeatingControlArchive
{
    private const ARCHIVE_MODULE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const HISTORY_LIMIT = 800;

    private function webSensorName(int $id): string
    {
        $name = IPS_GetName($id);
        $labels = ['OutsideTempID'=>'Outside temperature', 'IncomingAirTempID'=>'Incoming air',
            'OutgoingAirTempID'=>'Outgoing air', 'HeatExchangerTempID'=>'Heat exchanger', 'HeatPumpTempID'=>'Heat pump temperature'];
        foreach ($labels as $key=>$label) {
            if ($this->id($key) === $id) return $label === $name ? $name : $label . ' · ' . $name;
        }
        foreach ($this->rooms() as $room) {
            if (in_array($id, $room['sensors'], true)) return $room['name'] === $name ? $name : $room['name'] . ' · ' . $name;
        }
        return $name;
    }

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
        if (!is_string($range) || !in_array($range, ['1h', '6h', '24h', '7d', '30d'], true)) {
            throw new InvalidArgumentException('Select 1 hour, 6 hours, 24 hours, 7 days or 30 days.');
        }
        $resolution = $query['resolution'] ?? 'auto';
        if (!is_string($resolution) || !in_array($resolution, ['auto', 'recorded', 'hourly', 'daily'], true)) {
            throw new InvalidArgumentException('Select automatic, recorded, hourly or daily resolution.');
        }
        $aggregation = $resolution === 'auto' ? ($range === '1h' ? 'recorded' : ($range === '30d' ? 'daily' : 'hourly')) : $resolution;
        $archive = $this->webArchiveForSensor($id);
        if ($archive === 0) throw new InvalidArgumentException('This sensor is not recorded in an available standard Symcon archive.');
        $hours = ['1h'=>1, '6h'=>6, '24h'=>24, '7d'=>168, '30d'=>720][$range];
        $end = time();
        $raw = $aggregation === 'recorded';
        $daily = $aggregation === 'daily';
        $start = $end - $hours * 3600;
        $queryStart = $raw ? $start : ($daily ? strtotime('midnight', $start) : (int)(floor($start / 3600) * 3600));
        // Every read has a fixed time window and record limit; hourly aggregation fits the full 30 days.
        if ($raw && !function_exists('AC_GetLoggedValues')) throw new RuntimeException('Recorded readings are unavailable.');
        $values = $raw ? AC_GetLoggedValues($archive, $id, $queryStart, $end, self::HISTORY_LIMIT + 1)
            : AC_GetAggregatedValues($archive, $id, $daily ? 1 : 0, $queryStart, $end, self::HISTORY_LIMIT + 1);
        if (!is_array($values)) throw new RuntimeException('Archive returned an invalid history response.');
        $truncated = count($values) > self::HISTORY_LIMIT;
        $points = [];
        foreach (array_slice($values, 0, self::HISTORY_LIMIT) as $row) {
            if ($raw && is_array($row)) {
                $row['Avg'] = $row['Value'] ?? null;
                $row['Min'] = $row['Max'] = $row['Avg'];
            }
            if (!is_array($row) || !is_int($row['TimeStamp'] ?? null)
                || $row['TimeStamp'] < $queryStart || $row['TimeStamp'] > $end
                || (!is_float($row['Avg'] ?? null) && !is_int($row['Avg'] ?? null)) || !is_finite((float)$row['Avg'])) continue;
            $avg = (float)$row['Avg'];
            $min = $row['Min'] ?? $avg;
            $max = $row['Max'] ?? $avg;
            if ((!is_float($min) && !is_int($min)) || !is_finite((float)$min)) $min = $avg;
            if ((!is_float($max) && !is_int($max)) || !is_finite((float)$max)) $max = $avg;
            $points[] = ['time'=>$row['TimeStamp'], 'value'=>$avg, 'min'=>(float)$min, 'max'=>(float)$max,
                'duration'=>max(1, min(172800, (int)($row['Duration'] ?? ($raw ? 1 : ($daily ? 86400 : 3600)))))];
        }
        usort($points, static fn($a, $b)=>$a['time'] <=> $b['time']);
        return ['id'=>$id, 'name'=>$this->webSensorName($id), 'unit'=>'°C', 'range'=>$range,
            'from'=>$start, 'to'=>$end, 'aggregation'=>$aggregation, 'resolution'=>$resolution,
            'points'=>$points, 'truncated'=>$truncated, 'limit'=>self::HISTORY_LIMIT];
    }
}
