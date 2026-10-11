<?php
declare(strict_types=1);

/** Pure calculations: no network, Symcon writes or equipment commands. */
final class LichtBlickEconomics
{
    public static function parsePrices(string $json, float $correction): array
    {
        $body = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (($body['status'] ?? '') !== 'ok' || !is_array($body['dataPoints'] ?? null)
            || count($body['dataPoints']) < 1 || count($body['dataPoints']) > 1000) {
            throw new RuntimeException('Invalid LichtBlick price response');
        }
        $slots = [];
        foreach ($body['dataPoints'] as $point) {
            $date = $point['time'] ?? null;
            $price = $point['valueInCents'] ?? null;
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $date)
                || (!is_int($price) && !is_float($price)) || !is_finite((float)$price)
                || abs($price) > 1000) {
                throw new RuntimeException('Invalid timestamp or price');
            }
            $parsed = new DateTimeImmutable($date);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new RuntimeException('Invalid calendar timestamp');
            }
            $start = $parsed->getTimestamp();
            if ($start % 900 !== 0 || isset($slots[$start])) {
                throw new RuntimeException('Duplicate or non-quarter-hour price');
            }
            $slots[$start] = ['start' => $start, 'end' => $start + 900, 'price' => $price + $correction];
        }
        ksort($slots, SORT_NUMERIC);
        return array_values($slots);
    }

    public static function slotAt(array $slots, int $now): ?array
    {
        foreach ($slots as $slot) {
            if ($now >= $slot['start'] && $now < $slot['end']) return $slot;
        }
        return null; // Missing intervals are never interpolated.
    }

    public static function source(float $price, float $threshold, float $margin, float $band, int $previous): int
    {
        $ceiling = $threshold * (1 - $margin / 100);
        // Equality belongs to gas. Hysteresis is entirely below the economic ceiling.
        return $price < ($previous === 1 ? $ceiling : $ceiling - $band) ? 1 : 2;
    }

    public static function previousDay(int $now): array
    {
        $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'))->setTime(0, 0);
        $yesterday = $today->modify('-1 day');
        return [$yesterday->format('Y-m-d'), $yesterday->getTimestamp(), $today->getTimestamp()];
    }

    /** Matched consumption comparison, not a claimed measurement of delivered heat/COP. */
    public static function candidate(array $hp, array $gas, float $gasCt, float $kWhPerM3, float $baseKW): ?float
    {
        if ($hp['source'] !== 1 || $gas['source'] !== 2 || $hp['profile'] !== $gas['profile']
            || abs($hp['outside'] - $gas['outside']) > 1.0 || count($hp['inside']) !== count($gas['inside'])) return null;
        foreach ($hp['inside'] as $i => $temp) {
            if (abs($temp - $gas['inside'][$i]) > 0.3) return null;
        }
        $scale = $hp['degreeHours'] / $gas['degreeHours'];
        if ($scale < 0.8 || $scale > 1.25) return null;
        $backgroundGasRate = $hp['gasM3'] / $hp['hours'];
        $savedM3 = ($gas['gasM3'] - $backgroundGasRate * $gas['hours']) * $scale;
        $expectedGasElectricity = ($gas['electricityKWh'] - $baseKW * $gas['hours']) * $scale + $baseKW * $hp['hours'];
        $extraKWh = $hp['electricityKWh'] - $expectedGasElectricity;
        if ($savedM3 <= 0 || $extraKWh < 3) return null;
        $value = $savedM3 * $kWhPerM3 * $gasCt / $extraKWh;
        return is_finite($value) && $value >= 5 && $value <= 100 ? $value : null;
    }

    public static function smooth(float $current, float $candidate): float
    {
        // Gradual updates; the configurable safety margin is applied later, exactly once.
        $step = max(-0.1 * $current, min(0.1 * $current, 0.2 * ($candidate - $current)));
        return round($current + $step, 4);
    }
}
