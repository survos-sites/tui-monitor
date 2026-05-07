# Feature request: mouse event support (incl. scroll wheel)

## Context

Building a multi-pane process supervisor on top of `symfony/tui` (an early consumer of the experimental component, exercising it deliberately to surface gaps). The most-missed interaction is the **scroll wheel** in a tail-style log viewer — keyboard-only scrolling works, but is the wrong instinct for a long-running log pane.

Looking at the source, mouse support feels half-built:

- `Input/StdinBuffer.php` already parses both legacy X10 (`ESC [ M + 3 bytes`) and modern SGR (`ESC [ < B;X;Y M/m`) mouse sequences.
- `Render/PositionTracker.php` exists, suggesting hit-testing-by-coordinates was anticipated.
- But: no DECSET to actually *enable* mouse reporting in the terminal, no `MouseEvent` class, no widget hook, no router.

So today, mouse input is silently parsed and discarded.

## Use cases this would unlock

Concrete, in priority order:

1. **Scroll wheel inside a viewport widget** (LogViewerWidget / EditorWidget / SelectListWidget) — the highest-impact one. Keyboard scrolling is fine but mouse is muscle memory.
2. **Click to focus** — clicking a `SelectListWidget` item or another focusable widget gives it focus + selects.
3. **Click to act** — clickable footer hints, clickable tab headers (when `TabsWidget` lands).
4. **Drag-resize split panes** — later, but the same plumbing supports it.

## Suggested minimal scope

1. **Opt-in terminal toggle.** `Terminal::enableMouseTracking()` / `disableMouseTracking()` emit/clear `\e[?1000h\e[?1006h` (and reverse on stop). Off by default; consumers opt in via something like `new Tui(mouseTracking: true)` or `$tui->enableMouseTracking()`.
2. **`MouseEvent` value object.** `x`, `y` (terminal cells, 0-indexed), `button` enum (Left, Right, Middle, WheelUp, WheelDown, None), modifier flags (Shift/Alt/Ctrl), kind (Press, Release, Drag, Move).
3. **Bridge.** When the StdinBuffer yields a mouse sequence, dispatch a `MouseEvent` instead of `InputEvent`. Two distinct dispatch paths so consumers don't have to peek inside `InputEvent::getData()` to detect mouse.
4. **Routing via `PositionTracker`.** A new `MouseRouter` (or extend `FocusManager`) queries the tracker for the widget covering `(x, y)` and dispatches there. For wheel events on a non-focusable widget, dispatch to the nearest scrollable ancestor.
5. **Widget hook.** `MouseAwareInterface::handleMouse(MouseEvent): void` + trait, mirroring the `FocusableInterface` / `FocusableTrait` pattern. Reasonable default: clicking a focusable widget focuses it.

## Open questions

- Should clicking a focusable widget also focus it implicitly, or require the widget to opt in?
- Wheel events: route to the widget under cursor, or to the focused widget? (X11/Wayland convention is under-cursor; preference for terminals seems mixed.)
- Should `Tui` expose a high-level `addListener(MouseEvent::class, ...)` for global handlers, similar to `InputEvent`?

## Happy to contribute

If this shape sounds right, I'd be glad to draft the PR. Most of the structural work (parsing, position tracking) appears already done — what's missing is the wiring and a thin `MouseEvent` + dispatch layer.
