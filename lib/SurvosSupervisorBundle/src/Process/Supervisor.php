<?php

declare(strict_types=1);

namespace Survos\SupervisorBundle\Process;

final class Supervisor
{
    /** @var array<string, ManagedProcess> */
    private array $processes = [];

    /**
     * @param list<ProcessConfig> $configs
     */
    public function __construct(
        string $projectDir,
        array $configs,
        int $ringBufferLines,
    ) {
        foreach ($configs as $config) {
            $this->processes[$config->name] = new ManagedProcess($config, $projectDir, $ringBufferLines);
        }
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->processes);
    }

    public function get(string $name): ?ManagedProcess
    {
        return $this->processes[$name] ?? null;
    }

    /**
     * @return array<string, ManagedProcess>
     */
    public function all(): array
    {
        return $this->processes;
    }

    /**
     * Start every process whose config has autostart=true.
     */
    public function startAll(): void
    {
        foreach ($this->processes as $managed) {
            if ($managed->config->autostart) {
                $managed->start();
            }
        }
    }

    /**
     * One tick across all processes. Returns the names of those whose state
     * (output or lifecycle) changed during this tick.
     *
     * @return list<string>
     */
    public function tick(): array
    {
        $now = microtime(true);
        $changed = [];
        foreach ($this->processes as $name => $managed) {
            if ($managed->tick($now)) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    public function start(string $name): void
    {
        $this->processes[$name]?->start();
    }

    public function stop(string $name): void
    {
        $this->processes[$name]?->stop();
    }

    public function restart(string $name): void
    {
        $this->processes[$name]?->restart();
    }

    public function togglePause(string $name): void
    {
        $this->processes[$name]?->togglePause();
    }

    public function clearBuffer(string $name): void
    {
        $this->processes[$name]?->clearBuffer();
    }

    /**
     * Register a per-line streaming callback against every managed process.
     * The callback receives (processName, line). Used by --no-tui mode.
     */
    public function onLine(callable $listener): void
    {
        foreach ($this->processes as $name => $managed) {
            $managed->onLine(static fn (string $line) => $listener($name, $line));
        }
    }

    /**
     * SIGTERM every running process, wait up to $timeout seconds for them to
     * exit cleanly, then SIGKILL holdouts. Synchronous — call at shutdown.
     */
    public function stopAll(float $timeout = 3.0): void
    {
        foreach ($this->processes as $managed) {
            $managed->stop();
        }

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $allDone = true;
            foreach ($this->processes as $managed) {
                $managed->tick(microtime(true));
                if ($managed->isRunning()) {
                    $allDone = false;
                }
            }
            if ($allDone) {
                return;
            }
            usleep(100_000);
        }

        foreach ($this->processes as $managed) {
            if ($managed->isRunning()) {
                $managed->stop();
            }
        }
    }
}
