<?php
declare(strict_types=1);

/** Persistent monthly files. UTC interval keys preserve both hours at a DST change. */
final class LichtBlickPriceStore
{
    public function __construct(private string $directory) {}

    public static function identity(string $postcode, float $correction): string
    {
        return hash('sha256', $postcode . '|' . $correction);
    }

    public static function dates(string $from, string $to, int $maximumDays = 366): array
    {
        $zone = new DateTimeZone('Europe/Berlin');
        $parse = static function (string $value) use ($zone): DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
            if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Use valid dates in YYYY-MM-DD format');
            return $date;
        };
        $a = $parse($from); $b = $parse($to)->modify('+1 day');
        if ($a >= $b || $a->diff($b)->days > $maximumDays) throw new InvalidArgumentException('Invalid period or period exceeds ' . $maximumDays . ' calendar days');
        return [$a->getTimestamp(), $b->getTimestamp()];
    }

    public static function tariffs(string $json): array
    {
        $rows = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) throw new InvalidArgumentException('Invalid historical tariff table');
        $last = '';
        foreach ($rows as $row) {
            self::dates((string)($row['from'] ?? ''), (string)($row['from'] ?? ''));
            if ($row['from'] <= $last || !preg_match('/^\d{5}$/D', (string)($row['postcode'] ?? ''))
                || !is_bool($row['verified'] ?? null)) throw new InvalidArgumentException('Tariffs need ascending unique dates, postcode and a verified flag');
            foreach (['adderNetCt' => [-100, 200], 'vatPercent' => [0, 100]] as $key => $bounds) {
                $v = $row[$key] ?? null;
                if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || $v < $bounds[0] || $v > $bounds[1]) {
                    throw new InvalidArgumentException('Invalid historical tariff ' . $key);
                }
            }
            $last = $row['from'];
        }
        return $rows;
    }

    /** SMARD DE-LU day-ahead prices in EUR/MWh; no gap interpolation. */
    public static function market(string $json, int $from, int $to): array
    {
        $body = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $series = $body['series'] ?? null;
        if (!is_array($series) || !array_is_list($series) || count($series) < 2 || count($series) > 1000) throw new RuntimeException('Invalid SMARD series');
        $points = [];
        foreach ($series as $row) {
            if (!is_array($row) || count($row) !== 2 || !is_int($row[0]) || $row[0] % 900000 !== 0
                || ($row[1] !== null && ((!is_int($row[1]) && !is_float($row[1])) || !is_finite((float)$row[1]) || abs($row[1]) > 20000))) {
                throw new RuntimeException('Invalid SMARD timestamp or price');
            }
            $time = intdiv($row[0], 1000);
            if (isset($points[$time])) throw new RuntimeException('Duplicate SMARD timestamp');
            $points[$time] = $row[1];
        }
        ksort($points, SORT_NUMERIC); $times = array_keys($points); $result = [];
        $steps = [];
        for ($i = 1; $i < count($times); $i++) $steps[] = $times[$i] - $times[$i - 1];
        $native = in_array(900, $steps, true) ? 900 : (count(array_unique($steps)) === 1 && $steps[0] === 3600 ? 3600 : 0);
        if (!$native) throw new RuntimeException('Unsupported SMARD interval resolution');
        foreach ($times as $i => $start) {
            $step = $native;
            // A missing following sample is a gap, not evidence of an hourly price.
            if ((isset($times[$i + 1]) && $times[$i + 1] - $start !== $native) || $points[$start] === null) continue;
            for ($t = $start; $t < $start + $step; $t += 900) {
                if ($t >= $from && $t + 900 <= $to) $result[] = ['start' => $t, 'end' => $t + 900,
                    'marketCt' => (float)$points[$start] / 10, 'resolutionSeconds' => $step];
            }
        }
        return $result;
    }

    public static function reconstruct(array $slots, array $tariffs, string $postcode, int $fetchedAt): array
    {
        $zone = new DateTimeZone('Europe/Berlin'); $result = [];
        foreach ($slots as $slot) {
            $day = (new DateTimeImmutable('@' . $slot['start']))->setTimezone($zone)->format('Y-m-d');
            $tariff = null;
            foreach ($tariffs as $row) if ($row['from'] <= $day) $tariff = $row;
            $usable = $tariff !== null && $tariff['postcode'] === $postcode && $tariff['verified'];
            $result[] = $slot + ['price' => $usable ? ($slot['marketCt'] + $tariff['adderNetCt']) * (1 + $tariff['vatPercent'] / 100) : null,
                'source' => $usable ? 'smard-reconstructed' : 'smard-wholesale', 'verified' => $usable,
                'postcode' => $postcode, 'fetchedAt' => $fetchedAt, 'tariff' => $tariff];
        }
        return $result;
    }

    private function read(string $month): array
    {
        $path = $this->directory . '/' . $month . '.json';
        if (!is_file($path)) return [];
        $size = filesize($path);
        if ($size === false || $size > 16777216) throw new RuntimeException('History file too large');
        $file = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (($file['schema'] ?? 0) !== 1 || !is_array($file['records'] ?? null)) throw new RuntimeException('Invalid history file: ' . $month);
        return $file['records'];
    }

    /** Caller serializes writes with the instance's history semaphore. */
    public function merge(string $identity, array $slots): int
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new RuntimeException('Cannot create persistent price-history directory');
        $months = [];
        foreach ($slots as $slot) {
            if (!is_int($slot['start'] ?? null) || $slot['start'] % 900 !== 0 || ($slot['end'] ?? 0) !== $slot['start'] + 900
                || (!is_null($slot['price'] ?? null) && (!is_numeric($slot['price']) || !is_finite((float)$slot['price'])))) throw new RuntimeException('Invalid history interval');
            $months[gmdate('Y-m', $slot['start'])][] = $slot;
        }
        $changed = 0;
        foreach ($months as $month => $incoming) {
            $records = $this->read($month); $dirty = false;
            foreach ($incoming as $slot) {
                $key = $identity . ':' . $slot['start']; $old = $records[$key] ?? null;
                // Direct quotes take precedence over reconstructions; unconfirmed quotes never downgrade confirmed ones.
                if ($old && ($old['source'] ?? '') === 'lichtblick-quote'
                    && ($slot['source'] !== 'lichtblick-quote' || (($old['verified'] ?? false) && !$slot['verified']))) continue;
                if ($old) {
                    $a = $old; $b = $slot; unset($a['fetchedAt'], $b['fetchedAt']);
                    if ($a == $b) continue;
                }
                $records[$key] = $slot; $dirty = true; $changed++;
            }
            if (!$dirty) continue;
            ksort($records);
            $path = $this->directory . '/' . $month . '.json';
            $temp = tempnam($this->directory, '.price-');
            if ($temp === false) throw new RuntimeException('Cannot create history temporary file');
            try {
                $json = json_encode(['schema' => 1, 'records' => $records], JSON_THROW_ON_ERROR);
                if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $path)) throw new RuntimeException('Cannot save price history');
            } finally { if (is_file($temp)) unlink($temp); }
        }
        return $changed;
    }

    public function query(string $identity, int $from, int $to): array
    {
        if ($from <= 0 || $from >= $to || $to - $from > 367 * 86400) throw new InvalidArgumentException('Invalid history timestamps (maximum 366 calendar days)');
        $month = (new DateTimeImmutable('@' . $from))->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->setTime(0, 0);
        $rows = [];
        while ($month->getTimestamp() < $to) {
            foreach ($this->read($month->format('Y-m')) as $key => $row) {
                if (str_starts_with((string)$key, $identity . ':') && $row['start'] < $to && $row['end'] > $from) $rows[] = $row;
            }
            $month = $month->modify('+1 month');
        }
        usort($rows, static fn($a, $b) => $a['start'] <=> $b['start']);
        return $rows;
    }

    public static function chart(array $rows, int $from, int $to): array
    {
        $covered = 0; $retail = 0; $confirmed = 0;
        foreach ($rows as $row) {
            $seconds = max(0, min($to, $row['end']) - max($from, $row['start']));
            $covered += $seconds;
            if ($row['price'] !== null) { $retail += $seconds; if ($row['verified']) $confirmed += $seconds; }
        }
        $intervals = ($to - $from) / 900;
        $daily = $to - $from > 7 * 86400 + 3600;
        $points = [];
        if (!$daily) {
            foreach ($rows as $row) $points[] = array_intersect_key($row, array_flip(['start','end','price','marketCt','source','verified','resolutionSeconds']));
        } else {
            $zone = new DateTimeZone('Europe/Berlin'); $buckets = [];
            foreach ($rows as $row) {
                $day = (new DateTimeImmutable('@' . $row['start']))->setTimezone($zone)->setTime(0, 0);
                $key = $day->format('Y-m-d');
                if (!isset($buckets[$key])) $buckets[$key] = ['start' => $day->getTimestamp(), 'end' => $day->modify('+1 day')->getTimestamp(), 'sum' => 0.0, 'n' => 0, 'marketSum' => 0.0, 'marketN' => 0, 'sources' => [], 'verified' => true];
                $b = &$buckets[$key];
                if ($row['price'] !== null) { $b['sum'] += $row['price']; $b['n']++; $b['verified'] = $b['verified'] && $row['verified']; }
                if (isset($row['marketCt'])) { $b['marketSum'] += $row['marketCt']; $b['marketN']++; }
                $b['sources'][$row['source']] = true; unset($b);
            }
            foreach ($buckets as $b) $points[] = ['start' => $b['start'], 'end' => $b['end'], 'price' => $b['n'] ? $b['sum'] / $b['n'] : null,
                'marketCt' => $b['marketN'] ? $b['marketSum'] / $b['marketN'] : null, 'source' => implode(', ', array_keys($b['sources'])),
                'verified' => $b['verified'] && $b['n'] > 0, 'retailIntervals' => $b['n'], 'expectedIntervals' => ($b['end'] - $b['start']) / 900];
        }
        return ['from' => $from, 'to' => $to, 'resolution' => $daily ? 'daily mean' : '15-minute intervals', 'expectedIntervals' => $intervals,
            'storedIntervals' => $covered / 900, 'retailIntervals' => $retail / 900, 'confirmedIntervals' => $confirmed / 900,
            'missingIntervals' => ($to - $from - $covered) / 900, 'points' => $points];
    }
}
