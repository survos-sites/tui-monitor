<?php

declare(strict_types=1);

namespace Survos\SupervisorBundle\Process;

final readonly class ProcessConfig
{
    /**
     * @param list<string>           $cmd
     * @param array<string, string>  $env
     */
    public function __construct(
        public string $name,
        public array $cmd,
        public ?string $cwd,
        public array $env,
        public RestartMode $restart,
        public BackoffConfig $backoff,
        public bool $autostart,
    ) {
    }

    /**
     * @param array{
     *     cmd: list<string>,
     *     cwd?: ?string,
     *     env?: array<string, string>,
     *     restart?: string,
     *     backoff?: array{initial?: float, max?: float, multiplier?: float},
     *     autostart?: bool,
     * } $entry
     */
    public static function fromArray(string $name, array $entry): self
    {
        $backoff = $entry['backoff'] ?? [];

        return new self(
            name: $name,
            cmd: $entry['cmd'],
            cwd: $entry['cwd'] ?? null,
            env: $entry['env'] ?? [],
            restart: RestartMode::from($entry['restart'] ?? 'never'),
            backoff: new BackoffConfig(
                initial: (float) ($backoff['initial'] ?? 1.0),
                max: (float) ($backoff['max'] ?? 30.0),
                multiplier: (float) ($backoff['multiplier'] ?? 2.0),
            ),
            autostart: $entry['autostart'] ?? true,
        );
    }
}
