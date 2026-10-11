<?php
declare(strict_types=1);

/** Read-only archive adapter. Rejects incomplete/mixed days instead of inventing consumption. */
final class LichtBlickHistory
{
    private int $archive;
    private int $start;
    private int $end;
    private array $cache = [];

    public function __construct(int $archive, int $start, int $end)
    {
        $this->archive = $archive;
        $this->start = $start;
        $this->end = $end;
    }

    private function rows(int $id): array
    {
        if (isset($this->cache[$id])) return $this->cache[$id];
        if ($id <= 0 || !IPS_VariableExists($id) || !AC_GetLoggingStatus($this->archive, $id)) {
            throw new RuntimeException('Missing or unlogged variable ' . $id);
        }
        // Need a real boundary state, including when a switch has not changed for weeks.
        $before = AC_GetLoggedValues($this->archive, $id, 0, $this->start, 1);
        if (!is_array($before) || count($before) !== 1) throw new RuntimeException('No start baseline for ' . $id);
        $rows = $before;
        // Small chunks avoid the archive's hard 10,000 record limit; saturated chunks reject the day.
        for ($t = $this->start + 1; $t < $this->end; $t += 3600) {
            $part = AC_GetLoggedValues($this->archive, $id, $t, min($t + 3599, $this->end - 1), 10000);
            if (!is_array($part) || count($part) >= 10000) throw new RuntimeException('Archive incomplete/saturated for ' . $id);
            $rows = array_merge($rows, $part);
        }
        usort($rows, fn($a, $b) => $a['TimeStamp'] <=> $b['TimeStamp']);
        $unique = [];
        foreach ($rows as $row) {
            if (!isset($row['TimeStamp'], $row['Value']) || (!is_numeric($row['Value']) && !is_bool($row['Value'])) || !is_finite((float)$row['Value'])) {
                throw new RuntimeException('Invalid archive record ' . $id);
            }
            $time = (int)$row['TimeStamp'];
            if (isset($unique[$time]) && $unique[$time]['Value'] != $row['Value']) throw new RuntimeException('Ambiguous timestamp ' . $id);
            $unique[$time] = $row;
        }
        return $this->cache[$id] = array_values($unique);
    }

    private function stats(int $id, bool $temperature = false): array
    {
        $rows = $this->rows($id);
        $first = (float)$rows[0]['Value'];
        $sum = 0.0;
        $active = 0;
        $min = INF;
        $max = -INF;
        foreach ($rows as $i => $row) {
            $value = (float)$row['Value'];
            $a = max($this->start, (int)$row['TimeStamp']);
            $b = isset($rows[$i + 1]) ? (int)$rows[$i + 1]['TimeStamp'] : $this->end;
            if ($temperature && ($value < -30 || $value > 60 || $b - (int)$row['TimeStamp'] > 7200)) {
                throw new RuntimeException('Invalid or stale temperature ' . $id);
            }
            $seconds = max(0, $b - $a);
            $sum += $value * $seconds;
            if ($value != 0) $active += $seconds;
            $min = min($min, $value);
            $max = max($max, $value);
        }
        return ['mean' => $sum / ($this->end - $this->start), 'min' => $min, 'max' => $max,
            'first' => $first, 'last' => (float)$rows[count($rows) - 1]['Value'], 'active' => $active];
    }

    private function counter(int $id, float $scale): float
    {
        $rows = $this->rows($id);
        // Logged cumulative counters are held between changes. A quiet meter therefore
        // has zero consumption; a meter reset is rejected rather than treated as negative energy.
        $previous = (float)$rows[0]['Value'];
        foreach ($rows as $row) {
            $value = (float)$row['Value'];
            if ($value < $previous || $value < 0) throw new RuntimeException('Counter reset for ' . $id);
            $previous = $value;
        }
        return ($previous - (float)$rows[0]['Value']) * $scale;
    }

    public function day(array $c): array
    {
        $holiday = $this->stats($c['HolidayID']);
        if ($holiday['min'] != 1 || $holiday['max'] != 1) throw new RuntimeException('Initial learner accepts holiday days only');
        $mode = $this->stats($c['SourceModeID']);
        if ($mode['min'] != $mode['max'] || !in_array($mode['min'], [1.0, 2.0], true)) {
            throw new RuntimeException('Mixed source, cooling or unknown source');
        }
        if ($this->stats($c['RadiatorPumpID'])['active'] > 120 || $this->stats($c['HotWaterPumpID'])['active'] > 120
            || $this->stats($c['StorageTemperatureID'], true)['max'] > 20) {
            throw new RuntimeException('Radiator or hot-water load makes this day unsuitable');
        }
        $source = (int)$mode['min'];
        // A preserved manual mode must not label an automatic mixed-source day as pure gas/HP.
        if ($this->stats($source === 1 ? $c['GasAirPumpID'] : $c['HeatPumpOnID'])['active'] > 120) {
            throw new RuntimeException('Opposite air source active: source-mode history is mixed or incorrectly mapped');
        }
        $active = $this->stats($source === 1 ? $c['HeatPumpOnID'] : $c['GasAirPumpID'])['active'];
        if ($active < 3600) throw new RuntimeException('Less than one hour of heating');
        if ($source === 1) {
            $heatMode = $this->stats($c['HeatPumpHeatModeID']);
            if ($heatMode['min'] != 1 || $heatMode['max'] != 1) throw new RuntimeException('Heat pump not exclusively in heating mode');
            $on = $this->rows($c['HeatPumpOnID']);
            $powers = $this->rows($c['HeatPumpCapacityID']);
            // Merge both event streams so off-period power values do not masquerade as operating capacity.
            $events = [];
            foreach ($on as $r) $events[] = ['t' => max($this->start, (int)$r['TimeStamp']), 'on' => $r['Value']];
            foreach ($powers as $r) $events[] = ['t' => max($this->start, (int)$r['TimeStamp']), 'power' => $r['Value']];
            usort($events, fn($a, $b) => $a['t'] <=> $b['t']);
            $state = 0; $power = 0; $i = 0;
            while ($i < count($events)) {
                $t = $events[$i]['t'];
                while ($i < count($events) && $events[$i]['t'] === $t) {
                    if (isset($events[$i]['on'])) $state = (int)$events[$i]['on'];
                    if (isset($events[$i]['power'])) $power = (float)$events[$i]['power'];
                    $i++;
                }
                $next = $i < count($events) ? $events[$i]['t'] : $this->end;
                if ($state && $next > $t && abs($power - $c['ReferenceCapacityPercent']) > 3) {
                    throw new RuntimeException('Heat-pump capacity differs from configured reference');
                }
            }
        }
        $outside = $this->stats($c['OutsideTemperatureID'], true)['mean'];
        $rooms = json_decode($c['LearningRooms'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($rooms) || count($rooms) < 1) throw new RuntimeException('No learning rooms configured');
        $inside = []; $targets = [];
        foreach ($rooms as $room) {
            $temp = $this->stats((int)$room['temperatureID'], true);
            $target = $this->stats((int)$room['targetID']);
            if (abs($temp['last'] - $temp['first']) > 0.3 || $target['min'] != $target['max']
                || $target['min'] < 5 || $target['max'] > 15) throw new RuntimeException('Room drifting or target changed/outside holiday range');
            $inside[] = $temp['mean']; $targets[] = $target['mean'];
        }
        $hours = ($this->end - $this->start) / 3600;
        $degreeHours = (array_sum($inside) / count($inside) - $outside) * $hours;
        if ($degreeHours < 24) throw new RuntimeException('Too little weather-related heating demand');
        return ['source' => $source, 'hours' => $hours, 'outside' => $outside, 'inside' => $inside,
            'profile' => json_encode($targets), 'degreeHours' => $degreeHours,
            'gasM3' => $this->counter($c['GasCounterID'], $c['GasCounterScale']),
            'electricityKWh' => $this->counter($c['HeatPumpCounterID'], $c['HeatPumpCounterScale'])
                + $this->counter($c['AuxCounterID'], $c['AuxCounterScale'])];
    }
}
