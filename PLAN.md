# SurvosSupervisorBundle — Design Spec

## Goal

A Symfony bundle that supervises a configured set of long-running processes and presents them in a multi-pane TUI. One terminal, one keystroke to switch between processes, restart-in-place, pause via SIGSTOP, scroll back through ring-buffered output.

**Two motivations, in priority order:**

1. **Stress-test `symfony/tui` while it's still in dev** (announced 2026-03-25, not yet released) and feed real-world feedback / PRs back upstream. Fabien is responsive to ideas pre-release; this is the window. We deliberately pick widgets and patterns that exercise TUI surface area and keep `docs/tui-feedback.md` as a running log of API gaps.
2. **Replace the `screen -S consume_xxx` workflow** for monitoring Symfony Messenger consumers and other long-running workers.

The bundle is generic (processes, not consumers). A Messenger preset is a thin sugar on top, layered in once the engine and TUI are solid.

## Stack

- PHP 8.4+
- Symfony 8.1 dev (for `symfony/tui`)
- `symfony/process` (sync, polled from the TUI tick loop) — **not** `amphp/process`. Decided against Amp to keep the dependency story simple and the engine debuggable. The TUI's Revolt loop calls `Supervisor::tick()` between renders and that's enough; we lose true backpressure on a runaway producer but gain a simpler mental model. Revisit if we hit problems.
- `symfony/tui` 8.1.x-dev for the TUI layer

```bash
composer config minimum-stability dev
composer require symfony/tui:8.1.x-dev
```

## Bundle layout (local-first, extraction-ready)

```
tui-monitor/
├── lib/
│   └── SurvosSupervisorBundle/
│       ├── composer.json                       # standalone, ready for git mv to survos/supervisor-bundle
│       └── src/
│           ├── SurvosSupervisorBundle.php      # extends AbstractBundle — config tree, extension, service wiring all here
│           ├── Process/
│           │   ├── ProcessConfig.php           # value object per entry
│           │   ├── ManagedProcess.php          # wraps Symfony\Component\Process\Process + ring buffer + restart state
│           │   ├── RestartPolicy.php           # never|on-failure|always + backoff
│           │   └── Supervisor.php              # tick-driven engine, no TUI dep
│           ├── Config/
│           │   └── ConfigLoader.php            # --config flag → cwd supervisor.yaml → bundle config
│           ├── Command/
│           │   └── SupervisorCommand.php       # invokable, --config, --no-tui
│           └── Tui/
│               ├── DashboardWidget.php         # owns layout + global keys (q,r,p,s,Tab)
│               └── LogViewerWidget.php         # custom scrollable read-only log pane
└── config/bundles.php                          # registers Survos\SupervisorBundle\SurvosSupervisorBundle
```

**One-class bundle.** `SurvosSupervisorBundle` extends `AbstractBundle` (Symfony 6.1+) and collapses what used to be three classes into one:

- `configure(DefinitionConfigurator $definition)` — the YAML config tree (replaces `Configuration.php`)
- `loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder)` — service wiring done inline with `$container->services()->set(...)` (replaces `Extension.php` *and* `services.yaml`/`services.php`)

No separate `DependencyInjection/` directory, no service config files. Everything the bundle needs is in one file.

**App `composer.json` autoload addition:**

```json
"autoload": {
    "psr-4": {
        "App\\": "src/",
        "Survos\\SupervisorBundle\\": "lib/SurvosSupervisorBundle/src/"
    }
}
```

When the bundle is ready to extract: `git mv lib/SurvosSupervisorBundle <new-repo>/`, switch the app to `composer require survos/supervisor-bundle`, drop the PSR-4 entry. Nothing else changes.

## Architecture

```
┌──────────────────────────────────────────────────────────┐
│ Header: keybinding hints                                 │
├────────────┬─────────────────────────────────────────────┤
│ Sidebar    │ Log pane (focused process)                  │
│ (SelectList│ (custom LogViewerWidget)                    │
│            │                                             │
│ slow_chat  │ tick 0 10:23:11                             │
│   running  │ tick 1 10:23:12                             │
│ fast_chat  │ tick 2 10:23:13                             │
│   running  │ ...                                         │
│ flaky      │                                             │
│   restart  │                                             │
│ oneshot    │                                             │
│   exited 0 │                                             │
│            │                                             │
└────────────┴─────────────────────────────────────────────┘
```

**Data flow:** each entry → one `Symfony\Component\Process\Process`. The `Tui::onTick` callback calls `Supervisor::tick()`, which:

1. Drains incremental output from each child via `Process::getIncrementalOutput()` / `getIncrementalErrorOutput()` and appends to per-process ring buffers.
2. Checks `Process::isRunning()` and applies restart policy on exit (with exponential backoff).
3. Returns whether anything changed; the dashboard invalidates accordingly.

The supervisor exposes a small event API (`onAppend(name)`, `onStateChange(name)`) so the TUI layer can react without polling the buffer.

## Configuration

Config is generic — processes are arbitrary commands. Optional Messenger sugar comes later.

```yaml
# supervisor.yaml at project root (auto-detected) or pointed to by --config
processes:
  slow_chatter:
    cmd: ['sh', '-c', 'i=0; while true; do echo "tick $i $(date +%T)"; i=$((i+1)); sleep 1; done']
    restart: always

  fast_chatter:
    cmd: ['sh', '-c', 'i=0; while true; do echo "fast $i"; i=$((i+1)); sleep 0.05; done']
    restart: always                   # high-rate test for TUI repaint behavior

  oneshot:
    cmd: ['ls', '-la', '/etc']
    restart: never                    # exercises "exited cleanly" sidebar state

  flaky:
    cmd: ['sh', '-c', 'echo starting; sleep 2; exit 1']
    restart: on-failure
    backoff: { initial: 1.0, max: 30.0, multiplier: 2.0 }

  noisy_stderr:
    cmd: ['sh', '-c', 'while true; do echo "stdout"; echo "stderr" >&2; sleep 0.5; done']
    restart: always

ring_buffer_lines: 5000
follow_by_default: true
```

**Per-process options:**

| Key | Type | Default | Notes |
|---|---|---|---|
| `cmd` | string[] | required | argv-style array |
| `cwd` | string | project_dir | working directory |
| `env` | map | inherit | extra env vars |
| `restart` | enum | `never` | `never` \| `on-failure` \| `always` |
| `backoff` | object | initial 1s, max 30s, ×2 | exponential, reset on manual restart |
| `autostart` | bool | `true` | if false, present in sidebar but not running |

**Bundle config equivalent** (under `survos_supervisor:` in `config/packages/survos_supervisor.yaml`) for the "installed in a real app" case. `--config` overrides; bundle config is the fallback.

**Messenger preset (later):** a `messenger:` shortcut that expands to `['bin/console', 'messenger:consume', '<transport>']` plus the standard `--limit` / `-vv` flags. Same engine, just sugar.

## Command

```bash
bin/console survos:supervisor                          # auto-detect supervisor.yaml in cwd, then bundle config
bin/console survos:supervisor --config=other.yaml      # explicit file
bin/console survos:supervisor --no-tui                 # plain stdout: "[name] line" — debug harness
```

Invokable command (no `extends Command`), `#[AsCommand(name: 'survos:supervisor')]`.

## Engine (TUI-free)

`Supervisor` is the engine. It has no `symfony/tui` dependency and is unit-testable against the dummy commands without spinning up a terminal.

```php
final class Supervisor
{
    public function __construct(
        private string $projectDir,
        /** @var array<string, ProcessConfig> */ private array $configs,
        private int $ringBufferLines,
    ) {}

    public function startAll(): void;
    public function tick(): bool;                  // true if any state/output changed
    public function get(string $name): ?ManagedProcess;
    public function names(): array;
    public function restart(string $name): void;   // resets backoff, ignores policy
    public function stop(string $name): void;      // disables auto-restart until manually started
    public function start(string $name): void;
    public function togglePause(string $name): void; // SIGSTOP/SIGCONT
    public function clearBuffer(string $name): void;
    public function stopAll(): void;               // SIGTERM all, SIGKILL holdouts after 3s

    public function onAppend(callable $listener): void;
    public function onStateChange(callable $listener): void;
}
```

`ManagedProcess` owns the `Process`, ring buffer (`\SplDoublyLinkedList` capped at `ringBufferLines`), restart state (next-attempt time, current backoff), and pause flag. `RestartPolicy` is a tiny value object; manual `restart()` resets backoff to `initial`.

## TUI layer

Two custom widgets plus stock `SelectListWidget` and `TextWidget`.

### `LogViewerWidget` (custom, must build)

`EditorWidget` has no read-only mode — its `handleInput` is hardcoded to edit. So we build:

```php
final class LogViewerWidget extends AbstractWidget
    implements FocusableInterface, VerticallyExpandableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /** @var list<string> */
    private array $lines = [];
    private int $scrollOffset = 0;       // lines from bottom; 0 = stuck to tail
    private bool $follow = true;

    public function setLines(array $lines): void;     // called by dashboard on append
    public function appendLines(array $lines): void;  // incremental
    public function clear(): void;
    public function setFollow(bool $follow): void;

    // KeybindingsTrait: pgup/pgdn/home/end/g/G to scroll, f to toggle follow
    public function handleInput(string $data): void;

    // Render: pick visible window from bottom + scrollOffset, AnsiUtils::truncateToWidth per line
    public function render(RenderContext $context): array;
}
```

Word-wrap vs truncate: start with truncate (one line in, one line out — simplest). Add wrap as a follow-up if it matters.

### Sidebar

Reuse `SelectListWidget`. Items are `[{value: name, label: name, description: '<status badge>'}]`.

Status updates: every tick, if any process state changed, rebuild the items array and call `setItems()`. **Caveat:** `setItems()` resets `selectedIndex` to 0, so we capture the current selected `value`, call `setItems()`, then `setSelectedIndex(<index of that value>)` to preserve selection. *(Filed in `tui-feedback.md` — should support per-item update or selection-by-value preservation.)*

### Layout

```php
$stylesheet = new StyleSheet([
    ':root' => new Style(direction: Direction::Vertical),
    '.body' => new Style(direction: Direction::Horizontal, gap: 1),
    SelectListWidget::class => new Style(maxColumns: 28, border: Border::from([1], BorderPattern::ROUNDED, 'cyan')),
    LogViewerWidget::class => new Style(border: Border::from([1], BorderPattern::ROUNDED, 'gray')),
    LogViewerWidget::class.':focus' => new Style(border: Border::from([1], BorderPattern::ROUNDED, 'emerald')),
]);

$header = new TextWidget('q quit · r restart · p pause · s stop · S start · Tab switch · f follow · c clear');
$body = new ContainerWidget();
$body->addStyleClass('body');
$body->add($sidebar);
$body->add($logViewer);

$tui = new Tui($stylesheet);
$tui->add($header);
$tui->add($body);
$tui->setFocus($sidebar);
```

### Global keybindings

Per-widget keybindings handle navigation when focused. Global commands (q/r/p/s/S/Tab) hook the input event:

```php
$tui->addListener(function (InputEvent $e) use ($tui, $supervisor, $sidebar, $logViewer) {
    $key = $e->getData();
    $selected = $sidebar->getSelectedItem()['value'] ?? null;
    match (true) {
        $key === 'q'         => $tui->stop(),
        $key === 'r' && $selected => $supervisor->restart($selected),
        $key === 'p' && $selected => $supervisor->togglePause($selected),
        $key === 's' && $selected => $supervisor->stop($selected),
        $key === 'S' && $selected => $supervisor->start($selected),
        $key === "\t"        => $tui->setFocus($tui->getFocus() === $sidebar ? $logViewer : $sidebar),
        default              => null,
    };
});
```

### Tick loop

```php
$tui->onTick(function (TickEvent $e) use ($supervisor, $dashboard) {
    if ($supervisor->tick()) {
        $dashboard->refresh();   // pulls focused process's lines into the LogViewerWidget, updates sidebar
    }
    $e->setBusy();              // keep ticking at high freq while supervisor is active
});
```

## TUI feedback log

`docs/tui-feedback.md` — running log of `symfony/tui` API gaps and rough edges to feed upstream. Initial entries (already known before writing a line of code):

1. **No read-only `EditorWidget` mode.** Built `LogViewerWidget` from scratch; a built-in `EditorWidget(readOnly: true)` or dedicated `LogViewerWidget` would help.
2. **`SelectListWidget::setItems()` resets `selectedIndex`.** When updating descriptions for live status, you have to capture/restore selection by value. A `setItems(items, preserveSelection: true)` flag — or per-item update API — would be ergonomic.
3. **No per-item update on `SelectListWidget`.** Updating a single item's description requires rebuilding the whole array.
4. **Layout via stylesheet only.** `Style(direction: Direction::Horizontal)` is clean for static layouts but awkward for "set this one container to horizontal" — inline `$container->setDirection(...)` would be useful for one-offs.

Add to this list as we hit issues.

## Keybinding cheatsheet

| Key   | Scope    | Action                               |
|-------|----------|--------------------------------------|
| ↑/↓   | sidebar  | move selection                       |
| Enter | sidebar  | focus that process's logs            |
| Tab   | global   | switch focus sidebar ↔ log pane      |
| f     | log pane | toggle follow-tail                   |
| PgUp/PgDn | log pane | scroll                            |
| g / G | log pane | scroll to top / tail                 |
| r     | global   | restart selected (resets backoff)    |
| p     | global   | pause/resume (SIGSTOP/SIGCONT)       |
| s     | global   | stop selected (no auto-restart)      |
| S     | global   | start selected                       |
| c     | global   | clear focused log buffer             |
| q     | global   | quit (SIGTERM all, SIGKILL after 3s) |

## Implementation order

1. **Bundle skeleton** — `lib/SurvosSupervisorBundle/` with one-file `SurvosSupervisorBundle extends AbstractBundle` (config tree + service wiring inline; no separate Configuration/Extension/services files), composer.json, register in `config/bundles.php`.
2. **Engine** — `Supervisor` + `ManagedProcess` + `RestartPolicy`. Plain `symfony/process`, no TUI. Unit test with dummy commands.
3. **`SupervisorCommand` with `--no-tui`** — drives engine in a tight loop, prints `[name] line` to stdout. End-to-end smoke before TUI lands.
4. **`supervisor.yaml`** at project root with the dummy commands.
5. **TUI layer** — `LogViewerWidget`, `DashboardWidget` wiring, global keybindings. Layered on top of the proven engine.
6. **`docs/tui-feedback.md`** — seed with the initial list above; grow as we hit issues.
7. **Messenger preset** — `messenger: <transport>` shortcut in config. Last; the engine doesn't need to know about Messenger.
8. **Extract to `survos/supervisor-bundle`** — once stable.

## Out of scope (for now)

- Persisting unread/message counts across restarts.
- Remote processes (SSH multiplex). Possible v2.
- Web/Mercure mirror. The supervisor is decoupled from the TUI, so this is *possible* later, but a separate product.
- Replay/redrive of failed Messenger messages — belongs in a separate `state:failed` command, not this dashboard.
- Per-process verbosity changes at runtime.

## Why this beats `screen`

- One keystroke to switch processes vs. `Ctrl-A "` then arrow + enter.
- Status badges in the sidebar surface which process is doing what without tabbing through.
- `r` restarts one process in place — no detach/kill/reattach.
- `p` SIGSTOP freezes a process mid-flight (helpful when it's hammering an external API).
- `q` shuts everything down cleanly. No orphan processes.
- Generic from day one — works for npm dev servers, log tails, Messenger consumers, anything.
