<?php

namespace App\Services\Analytics;

/**
 * Small, explainable statistics used by anomaly detection and forecasts.
 */
final class Statistics
{
    /** @param  array<int, float>  $values */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** Median absolute deviation. */
    public static function mad(array $values): ?float
    {
        $median = self::median($values);

        return $median === null ? null : self::median(array_map(fn ($v) => abs($v - $median), $values));
    }

    /**
     * Robust z-score: 0.6745 * (x - median) / MAD. $floor prevents
     * division by (near) zero for very stable metrics.
     */
    public static function robustZ(float $value, float $median, float $mad, float $floor): float
    {
        return 0.6745 * ($value - $median) / max($mad, $floor);
    }

    /**
     * Ordinary least squares y = intercept + slope * x.
     *
     * @param  array<int, array{0: float, 1: float}>  $points  [x, y]
     * @return ?array{slope: float, intercept: float, r_squared: float, slope_se: float, n: int}
     */
    public static function linearRegression(array $points): ?array
    {
        $n = count($points);

        if ($n < 3) {
            return null;
        }

        $meanX = array_sum(array_column($points, 0)) / $n;
        $meanY = array_sum(array_column($points, 1)) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        $syy = 0.0;

        foreach ($points as [$x, $y]) {
            $sxx += ($x - $meanX) ** 2;
            $sxy += ($x - $meanX) * ($y - $meanY);
            $syy += ($y - $meanY) ** 2;
        }

        if ($sxx <= 0.0) {
            return null;
        }

        $slope = $sxy / $sxx;
        $intercept = $meanY - $slope * $meanX;
        $ssr = 0.0;

        foreach ($points as [$x, $y]) {
            $ssr += ($y - ($intercept + $slope * $x)) ** 2;
        }

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'r_squared' => $syy > 0 ? max(0.0, 1 - $ssr / $syy) : 1.0,
            'slope_se' => sqrt(($ssr / max(1, $n - 2)) / $sxx),
            'n' => $n,
        ];
    }
}
