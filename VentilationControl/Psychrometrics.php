<?php
declare(strict_types=1);

/** Dependency-free water-vapour calculations. All temperatures are Celsius. */
final class VentilationPsychrometrics
{
    public static function saturation(float $temperature): float
    {
        if (!is_finite($temperature) || $temperature < -50 || $temperature > 80) throw new InvalidArgumentException('Temperature outside -50 to 80 C.');
        // Magnus over liquid water, including supercooled liquid water below zero.
        return 6.112 * exp(17.62 * $temperature / (243.12 + $temperature));
    }
    public static function air(float $temperature, float $humidity): array
    {
        if (!is_finite($humidity) || $humidity <= 0 || $humidity > 100) throw new InvalidArgumentException('Humidity must be above 0 and at most 100 percent.');
        $pressure = self::saturation($temperature) * $humidity / 100;
        $gamma = log($pressure / 6.112);
        return ['temperature'=>$temperature,'humidity'=>$humidity,'vapourPressure'=>$pressure,
            'dewPoint'=>243.12 * $gamma / (17.62 - $gamma),
            'absoluteHumidity'=>216.7 * $pressure / ($temperature + 273.15)];
    }
    public static function relativeAt(float $vapourPressure, float $temperature): float
    {
        return 100 * $vapourPressure / self::saturation($temperature);
    }
    public static function temperatureAt(float $vapourPressure, float $relativeHumidity): float
    {
        if ($relativeHumidity <= 0 || $relativeHumidity > 100 || $vapourPressure <= 0) throw new InvalidArgumentException('Invalid moisture threshold.');
        $gamma = log(($vapourPressure * 100 / $relativeHumidity) / 6.112);
        return 243.12 * $gamma / (17.62 - $gamma);
    }
    public static function exchange(array $inside, array $outside, float $coldTemperature): array
    {
        // Compare vapour mass per volume at the SAME temperature; an outdoor cubic
        // metre changes volume when warmed. Display the ordinary g/m3 excess too.
        return ['moistureExcess'=>$inside['absoluteHumidity']-$outside['absoluteHumidity'],
            'dryingPotential'=>216.7*($inside['vapourPressure']-$outside['vapourPressure'])/($inside['temperature']+273.15),
            'ventilatedRelativeHumidity'=>self::relativeAt($outside['vapourPressure'],$inside['temperature']),
            'ventilatedColdHumidity'=>self::relativeAt($outside['vapourPressure'],$coldTemperature)];
    }
}
