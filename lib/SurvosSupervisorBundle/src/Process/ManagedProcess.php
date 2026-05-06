<?php

declare(strict_types=1);

namespace Survos\SupervisorBundle\Process;

use Symfony\Component\Process\Process;

final class ManagedProcess
{
    private ?Process $process = null;
    private bool $paused = false;
    private bool $manuallyStopped = false;
    private ?int $lastExitCode = null;
    private ?float $lastExitedAt = null;
    private ?float $nextRestartAt = null;
    private float $currentBackoff;

    /** @var \SplDoublyLinkedList<string> */
    private \SplDoublyLinkedList $lines;
    private string $stdoutPartial = '';
    private string $stderrPartial = '';
    private int $unread = 0;

    /** @var (callable(string): void)|null */
    private $lineListener = null;

    public function __construct(
        public readonly ProcessConfig $config,
        private readonly string $projectDir,
        private readonly int $maxLines,
    ) {
        $this->lines = new \SplDoublyLinkedList();
        $this->currentBackoff = $config->backoff->initial;
        $this->manuallyStopped = !$config->autostart;
    }

    public function name(): string
    {
        return $this->config->name;
    }

    public function isRunning(): bool
    {
        return null !== $this->process && $this->process->isRunning();
    }

    public function isPaused(): bool
    {
        return $this->isRunning() && $this->paused;
    }

    public function isManuallyStopped(): bool
    {
        return $this->manuallyStopped && !$this->isRunning();
    }

    public function isWaitingForRestart(): bool
    {
        return null === $this->process && null !== $this->nextRestartAt;
    }

    public function lastExitCode(): ?int
    {
        return $this->lastExitCode;
    }

    public function nextRestartAt(): ?float
    {
        return $this->nextRestartAt;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return iterator_to_array($this->lines, false);
    }

    public function unreadCount(): int
    {
        return $this->unread;
    }

    public function markRead(): void
    {
        $this->unread = 0;
    }

    public function clearBuffer(): void
    {
        $this->lines = new \SplDoublyLinkedList();
        $this->stdoutPartial = '';
        $this->stderrPartial = '';
        $this->unread = 0;
    }

    /**
     * Register a callback fired for every newly-flushed line. Push semantics
     * for streaming consumers (--no-tui mode); the TUI reads `lines()` instead.
     */
    public function onLine(callable $listener): void
    {
        $this->lineListener = $listener;
    }

    /**
     * Manual start: clears manuallyStopped, resets backoff, spawns the process.
     */
    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $this->manuallyStopped = false;
        $this->currentBackoff = $this->config->backoff->initial;
        $this->nextRestartAt = null;
        $this->spawn();
    }

    /**
     * Manual stop: SIGTERM, mark as manually stopped so the policy won't restart it.
     */
    public function stop(): void
    {
        $this->manuallyStopped = true;
        $this->nextRestartAt = null;
        if ($this->isRunning()) {
            $this->process?->signal(\SIGTERM);
        }
    }

    /**
     * Restart in place: stop the current process and reset backoff so the next
     * tick's restart-policy logic will respawn it cleanly.
     */
    public function restart(): void
    {
        $this->currentBackoff = $this->config->backoff->initial;
        $this->manuallyStopped = false;
        if ($this->isRunning()) {
            $this->process?->signal(\SIGTERM);
            $this->nextRestartAt = microtime(true);
        } else {
            $this->nextRestartAt = null;
            $this->spawn();
        }
    }

    /**
     * Toggle SIGSTOP/SIGCONT. No-op when not running.
     */
    public function togglePause(): void
    {
        if (!$this->isRunning()) {
            return;
        }
        if ($this->paused) {
            $this->process?->signal(\SIGCONT);
            $this->paused = false;
        } else {
            $this->process?->signal(\SIGSTOP);
            $this->paused = true;
        }
    }

    /**
     * One tick of work for this process. Returns true if any state changed
     * (output appended, started, exited, scheduled, etc.) so the supervisor
     * can decide whether to ask the TUI to re-render.
     */
    public function tick(float $now): bool
    {
        $changed = false;

        if (null === $this->process) {
            if (!$this->manuallyStopped && null !== $this->nextRestartAt && $now >= $this->nextRestartAt) {
                $this->spawn();
                $changed = true;
            }

            return $changed;
        }

        if ($this->drainOutput()) {
            $changed = true;
        }

        if (!$this->process->isRunning()) {
            if ($this->drainOutput()) {
                $changed = true;
            }
            $this->lastExitCode = $this->process->getExitCode();
            $this->lastExitedAt = $now;
            $this->process = null;
            $this->paused = false;
            $changed = true;

            if (!$this->manuallyStopped && $this->config->restart->shouldRestart($this->lastExitCode ?? 0)) {
                $this->nextRestartAt = $now + $this->currentBackoff;
                $this->currentBackoff = $this->config->backoff->next($this->currentBackoff);
            } else {
                $this->nextRestartAt = null;
            }
        }

        return $changed;
    }

    private function spawn(): void
    {
        $cwd = $this->config->cwd !== null
            ? (str_starts_with($this->config->cwd, '/') ? $this->config->cwd : $this->projectDir.'/'.$this->config->cwd)
            : $this->projectDir;

        $process = new Process(
            command: $this->config->cmd,
            cwd: $cwd,
            env: $this->config->env ?: null,
            timeout: null,
        );
        $process->start();

        $this->process = $process;
        $this->paused = false;
        $this->nextRestartAt = null;
    }

    private function drainOutput(): bool
    {
        if (null === $this->process) {
            return false;
        }

        $stdout = $this->process->getIncrementalOutput();
        $stderr = $this->process->getIncrementalErrorOutput();

        $changed = false;
        if ('' !== $stdout) {
            $this->stdoutPartial .= self::sanitize($stdout);
            $changed = $this->flushPartial($this->stdoutPartial, '') || $changed;
        }
        if ('' !== $stderr) {
            $this->stderrPartial .= self::sanitize($stderr);
            $changed = $this->flushPartial($this->stderrPartial, '[err] ') || $changed;
        }

        return $changed;
    }

    /**
     * Normalize line endings and strip terminal-control escape sequences that
     * would corrupt our line-buffer model, while preserving SGR (color/style)
     * codes. Specifically:
     *
     *   - \r\n   → \n  (CRLF normalization)
     *   - \r     → \n  (treat carriage return as a line break; progress-bar
     *                   style "rewrite the current line" becomes one line per
     *                   update — noisy but bounded)
     *   - CSI <…> [letter except 'm'] → stripped (cursor moves, erase line,
     *                   clear screen, save/restore cursor, etc.)
     *   - CSI <…>m → kept (SGR: colors and text attributes)
     *   - OSC … BEL/ST → stripped (terminal title, hyperlinks)
     */
    private static function sanitize(string $chunk): string
    {
        $chunk = strtr($chunk, ["\r\n" => "\n", "\r" => "\n"]);
        $chunk = preg_replace('/\x1b\[[0-9;?]*[A-La-ln-z]/', '', $chunk) ?? $chunk;
        $chunk = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', $chunk) ?? $chunk;

        return $chunk;
    }

    private function flushPartial(string &$buf, string $prefix): bool
    {
        $changed = false;
        while (false !== ($nl = strpos($buf, "\n"))) {
            $line = rtrim(substr($buf, 0, $nl), "\r");
            $buf = substr($buf, $nl + 1);
            $emitted = $prefix.$line;
            $this->lines->push($emitted);
            if ($this->lines->count() > $this->maxLines) {
                $this->lines->shift();
            }
            $this->unread++;
            $changed = true;
            if (null !== $this->lineListener) {
                ($this->lineListener)($emitted);
            }
        }

        return $changed;
    }
}
