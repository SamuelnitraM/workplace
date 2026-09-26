<?php

namespace App\Statistics;

/**
 * Data of a bar chart of the admin dashboard (templates/admin/_bar_chart.html.twig): one bar per point,
 * a value axis from 0 to a rounded maximum, a detail shown on hover and in the table view.
 */
final readonly class BarChart
{
    /** @var list<float> */
    public array $ticks;

    /**
     * @param list<array{label: string, value: ?float, detail: string}> $points a NULL value is drawn as « no data »
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public array $points,
        public string $unit = '',
        ?float $fixedMaximum = null,
    ) {
        $maximum = $fixedMaximum ?? self::niceMaximum(max([0.0, ...array_map(static fn (array $point): float => (float) $point['value'], $points)]));
        $this->ticks = [0.0, $maximum / 2, $maximum];
    }

    public function maximum(): float
    {
        return $this->ticks[2];
    }

    /** Height of a bar as a percentage of the plot. */
    public function heightOf(?float $value): float
    {
        return $value === null || $this->maximum() <= 0 ? 0.0 : min(100.0, $value * 100 / $this->maximum());
    }

    public function total(): float
    {
        return array_sum(array_map(static fn (array $point): float => (float) $point['value'], $this->points));
    }

    /** Smallest round even number (1, 2, 4, 6, 8 or 10 × 10^n, at least 2) above the value, so the three axis ticks stay whole. */
    public static function niceMaximum(float $value): float
    {
        if ($value <= 2) {
            return 2.0;
        }
        $magnitude = 10 ** floor(log10($value));
        foreach ([1, 2, 4, 6, 8, 10] as $step) {
            if ($value <= $step * $magnitude) {
                return $step * $magnitude;
            }
        }
        return 10 * $magnitude;
    }
}
