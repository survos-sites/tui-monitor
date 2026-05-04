<?php

declare(strict_types=1);

namespace Survos\SupervisorBundle\Process;

enum RestartMode: string
{
    case Never = 'never';
    case OnFailure = 'on-failure';
    case Always = 'always';

    public function shouldRestart(int $exitCode): bool
    {
        return match ($this) {
            self::Never => false,
            self::OnFailure => 0 !== $exitCode,
            self::Always => true,
        };
    }
}
