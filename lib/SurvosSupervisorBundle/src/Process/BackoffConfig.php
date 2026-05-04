<?php

declare(strict_types=1);

namespace Survos\SupervisorBundle\Process;

final readonly class BackoffConfig
{
    public function __construct(
        public float $initial = 1.0,
        public float $max = 30.0,
        public float $multiplier = 2.0,
    ) {
    }

    public function next(float $current): float
    {
        return min($this->max, max($this->initial, $current * $this->multiplier));
    }
}
