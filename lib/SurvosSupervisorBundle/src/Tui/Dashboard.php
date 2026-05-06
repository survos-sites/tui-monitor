<?php

declare(strict_types=1);

namespace Survos\SupervisorBundle\Tui;

use Survos\SupervisorBundle\Process\ManagedProcess;
use Survos\SupervisorBundle\Process\Supervisor;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\BorderPattern;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class Dashboard
{
    private Tui $tui;
    private SelectListWidget $sidebar;
    private LogViewerWidget $logViewer;
    private TextWidget $header;
    private TextWidget $footer;
    private string $focused = '';

    public function __construct(
        private readonly Supervisor $supervisor,
        bool $followByDefault,
    ) {
        $this->tui = new Tui($this->buildStyleSheet());
        $this->sidebar = new SelectListWidget($this->buildSidebarItems(), maxVisible: 30);
        $this->logViewer = new LogViewerWidget();
        $this->logViewer->setFollow($followByDefault);
        $this->header = new TextWidget('Survos Supervisor');
        $this->footer = new TextWidget($this->footerText());
    }

    public function run(): int
    {
        $body = new ContainerWidget();
        $body->addStyleClass('body');
        $body->add($this->sidebar);
        $body->add($this->logViewer);

        $this->tui->add($this->header);
        $this->tui->add($body);
        $this->tui->add($this->footer);
        $this->tui->setFocus($this->sidebar);

        $this->wireSidebar();
        $this->wireGlobalKeys();
        $this->wireSupervisorStream();
        $this->wireTickLoop();

        $this->supervisor->startAll();
        $first = $this->supervisor->names()[0] ?? '';
        if ('' !== $first) {
            $this->focusProcess($first);
        }

        try {
            $this->tui->run();
        } finally {
            $this->supervisor->stopAll();
        }

        return 0;
    }

    private function wireSidebar(): void
    {
        $this->sidebar->onSelectionChange(function (SelectionChangeEvent $event): void {
            $this->focusProcess($event->getValue());
        });
    }

    private function wireGlobalKeys(): void
    {
        $this->tui->addListener(function (InputEvent $event): void {
            $key = $event->getData();
            $selected = $this->sidebar->getSelectedItem()['value'] ?? null;

            $consumed = match (true) {
                $key === 'q', $key === Key::ctrl('c') => $this->doQuit(),
                $key === "\t" => $this->cycleFocus(),
                $key === 'r' && null !== $selected => $this->doRestart($selected),
                $key === 'p' && null !== $selected => $this->doTogglePause($selected),
                $key === 's' && null !== $selected => $this->doStop($selected),
                $key === 'S' && null !== $selected => $this->doStart($selected),
                $key === 'c' => $this->doClearLogs(),
                default => false,
            };

            if ($consumed) {
                $event->stopPropagation();
            }
        });
    }

    private function wireSupervisorStream(): void
    {
        $this->supervisor->onLine(function (string $name, string $line): void {
            if ($name === $this->focused) {
                $this->logViewer->appendLines([$line]);
            }
        });
    }

    private function wireTickLoop(): void
    {
        $this->tui->onTick(function (TickEvent $event): void {
            $changed = $this->supervisor->tick();
            if ($changed) {
                $this->refreshSidebar();
            }
            $this->refreshFooter();
            $event->setBusy();
        });
    }

    private function focusProcess(string $name): void
    {
        if ('' === $name || $name === $this->focused) {
            return;
        }
        $managed = $this->supervisor->get($name);
        if (null === $managed) {
            return;
        }
        $this->focused = $name;
        $this->logViewer->setLines($managed->lines());
        $managed->markRead();
        $this->refreshSidebar();
        $this->refreshFooter();
    }

    private function refreshSidebar(): void
    {
        $items = $this->buildSidebarItems();
        $current = $this->sidebar->getSelectedItem()['value'] ?? null;
        $this->sidebar->setItems($items);

        if (null !== $current) {
            foreach ($items as $i => $item) {
                if ($item['value'] === $current) {
                    $this->sidebar->setSelectedIndex($i);
                    break;
                }
            }
        }
    }

    private function refreshFooter(): void
    {
        $text = $this->footerText();
        if ($this->footer->getText() !== $text) {
            $this->footer->setText($text);
        }
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private function buildSidebarItems(): array
    {
        $items = [];
        foreach ($this->supervisor->all() as $name => $managed) {
            $items[] = [
                'value' => $name,
                'label' => $name,
                'description' => $this->describeState($managed),
            ];
        }

        return $items;
    }

    private function describeState(ManagedProcess $managed): string
    {
        if ($managed->isPaused()) {
            return '⏸ paused';
        }
        if ($managed->isRunning()) {
            $unread = $managed->unreadCount();

            return $unread > 0 && $managed->name() !== $this->focused
                ? \sprintf('● running (%d new)', $unread)
                : '● running';
        }
        if ($managed->isWaitingForRestart()) {
            $secs = max(0.0, ($managed->nextRestartAt() ?? 0.0) - microtime(true));

            return \sprintf('↻ %.1fs', $secs);
        }
        if ($managed->isManuallyStopped()) {
            return '■ stopped';
        }

        return \sprintf('✗ exit %s', $managed->lastExitCode() ?? '?');
    }

    private function footerText(): string
    {
        $follow = $this->logViewer->isFollowing() ? 'ON' : 'OFF';
        $wrap = $this->logViewer->isWrapping() ? 'ON' : 'OFF';

        return \sprintf(
            '↑↓ select · Tab focus · q quit · r restart · p pause · s stop · S start · c clear · f follow:%s · w wrap:%s',
            $follow,
            $wrap,
        );
    }

    private function doQuit(): bool
    {
        $this->tui->stop();

        return true;
    }

    private function cycleFocus(): bool
    {
        $current = $this->tui->getFocus();
        $this->tui->setFocus($current === $this->sidebar ? $this->logViewer : $this->sidebar);

        return true;
    }

    private function doRestart(string $name): bool
    {
        $this->supervisor->restart($name);
        $this->refreshSidebar();

        return true;
    }

    private function doTogglePause(string $name): bool
    {
        $this->supervisor->togglePause($name);
        $this->refreshSidebar();

        return true;
    }

    private function doStop(string $name): bool
    {
        $this->supervisor->stop($name);
        $this->refreshSidebar();

        return true;
    }

    private function doStart(string $name): bool
    {
        $this->supervisor->start($name);
        $this->refreshSidebar();

        return true;
    }

    private function doClearLogs(): bool
    {
        if ('' !== $this->focused) {
            $this->supervisor->clearBuffer($this->focused);
            $this->logViewer->clearLines();
            $this->refreshSidebar();
        }

        return true;
    }

    private function buildStyleSheet(): StyleSheet
    {
        return new StyleSheet([
            ':root' => new Style(direction: Direction::Vertical),
            '.body' => new Style(direction: Direction::Horizontal, gap: 1),
            SelectListWidget::class => new Style(
                maxColumns: 50,
                border: Border::from([1], BorderPattern::ROUNDED, 'gray'),
            ),
            SelectListWidget::class.':focus' => new Style(
                border: Border::from([1], BorderPattern::ROUNDED, 'cyan'),
            ),
            LogViewerWidget::class => new Style(
                flex: 1,
                border: Border::from([1], BorderPattern::ROUNDED, 'gray'),
            ),
            LogViewerWidget::class.':focus' => new Style(
                border: Border::from([1], BorderPattern::ROUNDED, '#10b981'),
            ),
            TextWidget::class => new Style(dim: true),
        ]);
    }
}
