<?php

declare(strict_types=1);

namespace Survos\SupervisorBundle\Tui;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Read-only scrolling log viewer. Built because EditorWidget has no read-only
 * mode — its handleInput is hardcoded to edit. Listed in docs/tui-feedback.md
 * as an upstream gap.
 */
final class LogViewerWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /** @var list<string> */
    private array $lines = [];
    private int $scrollOffset = 0;
    private bool $follow = true;
    private bool $wrap = false;
    private bool $verticallyExpanded = true;
    private int $lastViewportRows = 20;

    public function __construct(?Keybindings $keybindings = null)
    {
        if (null !== $keybindings) {
            $this->setKeybindings($keybindings);
        }
    }

    public function isFollowing(): bool
    {
        return $this->follow;
    }

    public function isWrapping(): bool
    {
        return $this->wrap;
    }

    public function toggleWrap(): void
    {
        $this->wrap = !$this->wrap;
        $this->invalidate();
    }

    public function setFollow(bool $follow): void
    {
        if ($this->follow === $follow) {
            return;
        }
        $this->follow = $follow;
        if ($follow) {
            $this->scrollOffset = 0;
        }
        $this->invalidate();
    }

    public function toggleFollow(): void
    {
        $this->setFollow(!$this->follow);
    }

    /**
     * Replace the buffer wholesale. Used when switching focused process.
     *
     * @param list<string> $lines
     */
    public function setLines(array $lines): void
    {
        $this->lines = $lines;
        $this->scrollOffset = 0;
        $this->invalidate();
    }

    /**
     * Append the given lines. If we're not following, bump scrollOffset by the
     * number of new lines so the user's view stays anchored to the same content
     * instead of drifting as the buffer grows.
     *
     * @param list<string> $newLines
     */
    public function appendLines(array $newLines): void
    {
        if (!$newLines) {
            return;
        }
        $this->lines = array_merge($this->lines, $newLines);
        if (!$this->follow) {
            $this->scrollOffset += \count($newLines);
            $this->clampScroll();
        }
        $this->invalidate();
    }

    public function clearLines(): void
    {
        $this->lines = [];
        $this->scrollOffset = 0;
        $this->invalidate();
    }

    public function expandVertically(bool $fill): static
    {
        if ($this->verticallyExpanded !== $fill) {
            $this->verticallyExpanded = $fill;
            $this->invalidate();
        }

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->verticallyExpanded;
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        $page = max(1, $this->lastViewportRows - 1);

        if ($kb->matches($data, 'log_scroll_up')) {
            $this->scrollBy(1);

            return;
        }
        if ($kb->matches($data, 'log_scroll_down')) {
            $this->scrollBy(-1);

            return;
        }
        if ($kb->matches($data, 'log_page_up')) {
            $this->scrollBy($page);

            return;
        }
        if ($kb->matches($data, 'log_page_down')) {
            $this->scrollBy(-$page);

            return;
        }
        if ($kb->matches($data, 'log_top')) {
            $this->scrollOffset = max(0, \count($this->lines) - 1);
            $this->setFollow(false);
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'log_tail')) {
            $this->scrollOffset = 0;
            $this->setFollow(true);
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'log_toggle_follow')) {
            $this->toggleFollow();

            return;
        }
        if ($kb->matches($data, 'log_toggle_wrap')) {
            $this->toggleWrap();

            return;
        }
    }

    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $rows = max(1, $context->getRows());
        $this->lastViewportRows = $rows;

        $out = [];
        if (!$this->lines) {
            $out[] = AnsiUtils::truncateToWidth(' (no output yet)', $cols, '');
        } elseif ($this->wrap) {
            $count = \count($this->lines);
            $end = max(1, min($count, $count - $this->scrollOffset));
            $idx = $end - 1;
            while ($idx >= 0 && \count($out) < $rows) {
                $wrapped = $this->wrapLine($this->lines[$idx], $cols);
                array_splice($out, 0, 0, $wrapped);
                --$idx;
            }
            if (\count($out) > $rows) {
                $out = \array_slice($out, \count($out) - $rows);
            }
        } else {
            $count = \count($this->lines);
            $start = max(0, $count - $rows - $this->scrollOffset);
            $end = min($count, $start + $rows);
            foreach (\array_slice($this->lines, $start, $end - $start) as $line) {
                $out[] = AnsiUtils::truncateToWidth($line, $cols, '');
            }
        }

        if ($this->verticallyExpanded) {
            while (\count($out) < $rows) {
                $out[] = '';
            }
        }

        return $out;
    }

    /**
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'log_scroll_up' => [Key::UP, 'k'],
            'log_scroll_down' => [Key::DOWN, 'j'],
            'log_page_up' => [Key::PAGE_UP, 'ctrl+b'],
            'log_page_down' => [Key::PAGE_DOWN, 'ctrl+f'],
            'log_top' => [Key::HOME, 'g'],
            'log_tail' => [Key::END, 'G'],
            'log_toggle_follow' => ['f'],
            'log_toggle_wrap' => ['w'],
        ];
    }

    /**
     * @return list<string>
     */
    private function wrapLine(string $line, int $cols): array
    {
        $width = AnsiUtils::visibleWidth($line);
        if ($width <= $cols || $cols <= 0) {
            return [$line];
        }
        $parts = [];
        for ($start = 0; $start < $width; $start += $cols) {
            $parts[] = AnsiUtils::sliceByColumn($line, $start, $cols);
        }

        return $parts;
    }

    private function scrollBy(int $delta): void
    {
        if (0 === $delta) {
            return;
        }
        if ($delta > 0) {
            $this->setFollow(false);
        }
        $this->scrollOffset += $delta;
        $this->clampScroll();
        if (0 === $this->scrollOffset && $delta < 0) {
            $this->setFollow(true);
        }
        $this->invalidate();
    }

    private function clampScroll(): void
    {
        $maxOffset = max(0, \count($this->lines) - 1);
        $this->scrollOffset = max(0, min($maxOffset, $this->scrollOffset));
    }
}
