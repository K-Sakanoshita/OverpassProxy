<?php

declare(strict_types=1);

final class ProxyBboxGrid
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     * @return array{
     *   bbox: array{0: float, 1: float, 2: float, 3: float},
     *   step: float
     * }
     */
    public static function align(array $bbox, array $config): array
    {
        [$south, $west, $north, $east] = $bbox;
        $step = self::selectStep($bbox, $config);

        if ($step <= 0 || !is_finite($step)) {
            throw new InvalidArgumentException('raw bbox grid step must be a finite number greater than zero');
        }

        $aligned = [
            self::roundDown($south, $step),
            self::roundDown($west, $step),
            self::roundUp($north, $step),
            self::roundUp($east, $step),
        ];

        $aligned[0] = max(-90.0, $aligned[0]);
        $aligned[1] = max(-180.0, $aligned[1]);
        $aligned[2] = min(90.0, $aligned[2]);
        $aligned[3] = min(180.0, $aligned[3]);

        return [
            'bbox' => array_map(
                static fn(float $value): float => round($value, 6),
                $aligned
            ),
            'step' => $step,
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    public static function selectStep(array $bbox, array $config): float
    {
        $span = max($bbox[2] - $bbox[0], $bbox[3] - $bbox[1]);
        $tiers = $config['raw_bbox_grid_steps'] ?? self::defaultTiers();

        if (!is_array($tiers) || $tiers === []) {
            throw new InvalidArgumentException('raw_bbox_grid_steps must not be empty');
        }

        $lastStep = null;
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                throw new InvalidArgumentException('raw_bbox_grid_steps contains an invalid tier');
            }

            $maxSpan = (float)($tier['max_span'] ?? 0);
            $step = (float)($tier['step'] ?? 0);
            if ($maxSpan <= 0 || $step <= 0 || !is_finite($maxSpan) || !is_finite($step)) {
                throw new InvalidArgumentException('raw_bbox_grid_steps values must be finite numbers greater than zero');
            }

            $lastStep = $step;
            if ($span <= $maxSpan + 1e-12) {
                return $step;
            }
        }

        if ($lastStep === null) {
            throw new InvalidArgumentException('raw_bbox_grid_steps did not provide a usable step');
        }

        return $lastStep;
    }

    /** @return array<int, array{max_span: float, step: float}> */
    private static function defaultTiers(): array
    {
        return [
            ['max_span' => 0.02, 'step' => 0.002],
            ['max_span' => 0.05, 'step' => 0.005],
            ['max_span' => 0.10, 'step' => 0.01],
            ['max_span' => 0.25, 'step' => 0.02],
            ['max_span' => 0.50, 'step' => 0.05],
            ['max_span' => 1.00, 'step' => 0.10],
            ['max_span' => 2.50, 'step' => 0.25],
            ['max_span' => 5.00, 'step' => 0.50],
            ['max_span' => 10.0, 'step' => 1.00],
        ];
    }

    private static function roundDown(float $value, float $step): float
    {
        return floor(($value / $step) + 1e-12) * $step;
    }

    private static function roundUp(float $value, float $step): float
    {
        return ceil(($value / $step) - 1e-12) * $step;
    }
}
