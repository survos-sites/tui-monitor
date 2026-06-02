# TUI Monitor

Symfony 8.1 demo app for a local process supervisor TUI. It includes a local
`SurvosSupervisorBundle`, realistic long-running demo commands, and a small
Messenger traffic producer so queue workers are worth watching.

## What It Demos

- `survos:supervisor`: a terminal dashboard for configured processes.
- A real `messenger:consume async -v` worker shown in the dashboard.
- `app:demo:flood`: dispatches mixed fast/slow Messenger messages.
- Realistic background commands:
  - Packagist update polling.
  - URL health checks.
  - Debian ISO range download with a Symfony progress bar.
  - A fake state-machine stream.

The app defaults to sqlite and Doctrine Messenger, so it needs no Redis,
RabbitMQ, or Postgres.

## Setup

```bash
composer install
```

The committed `.env` uses:

```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"
MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=async
```

Doctrine Messenger will create its transport table automatically on first use.

## Run The Supervisor

Start the TUI:

```bash
php bin/console survos:supervisor
```

Or run the same process set as prefixed terminal output:

```bash
php bin/console survos:supervisor --no-tui
```

The process list is defined in [supervisor.yaml](supervisor.yaml). It currently
starts:

- `packagist_updates`
- `url_health`
- `debian_download`
- `queue_worker`
- `state_machine`
- `oneshot`
- `ticker`

Useful TUI keys:

- `q`: quit
- `Tab`: switch focus between process list and log pane
- `r`: restart selected process
- `p`: pause/resume selected process
- `s`: stop selected process
- `S`: start selected process
- `c`: clear selected process log
- `f`: toggle follow mode in the log pane
- `w`: toggle wrap mode in the log pane

Failed processes are shown red in the sidebar so you can select them and inspect
the exception output.

## Generate Queue Traffic

In one terminal, run the supervisor:

```bash
php bin/console survos:supervisor
```

In another terminal, dispatch a normal batch:

```bash
php bin/console app:demo:flood 100 --delay=25
```

Dispatch a burst:

```bash
php bin/console app:demo:flood 250 --delay=0
```

Dispatch a failure-heavy batch:

```bash
php bin/console app:demo:flood 100 --delay=0 --bad-batch
```

The supervisor's `queue_worker` process runs:

```bash
symfony console messenger:consume async -v --time-limit=3600 --memory-limit=512M
```

You should see thumbnail jobs finish quickly, OCR jobs take longer, and some OCR
jobs throw exceptions and get retried.

Check queue depth:

```bash
php bin/console messenger:stats --format=json
```

Consume manually without the supervisor:

```bash
php bin/console messenger:consume async -v
```

## Messenger Demo Shape

The queue producer lives in [src/MessengerDemo/TrafficDemo.php](src/MessengerDemo/TrafficDemo.php).
It uses Symfony 8.1 method-level attributes:

- `#[AsCommand('app:demo:flood', ...)]`
- `#[AsMessageHandler]` on handler methods

Messages are immutable `readonly` DTOs implementing `Stringable`:

- [GenerateThumbnail](src/Message/GenerateThumbnail.php): fast and reliable.
- [OcrPage](src/Message/OcrPage.php): slower and failure-prone.

Both message types route to the `async` Doctrine transport in
[config/packages/messenger.yaml](config/packages/messenger.yaml).

## Demo Commands

List all demo commands:

```bash
php bin/console list demo
```

Examples:

```bash
php bin/console demo:packagist-updates -v
php bin/console demo:http-head -v
php bin/console demo:debian-download -v
php bin/console demo:state-machine -v
php bin/console demo:flaky -v
```

The `demo:flaky` command is intentionally not the main queue demo anymore. It is
kept as a simple command-failure fixture for the supervisor.

## Symfony Local Server

If `.symfony.local.yaml` workers are configured later and you want only the web
server, start Symfony CLI with workers disabled:

```bash
symfony server:start --no-workers
```

## Bundle Extraction Notes

The supervisor bundle is local-first under:

```text
lib/SurvosSupervisorBundle
```

The app autoloads it directly:

```json
"Survos\\SupervisorBundle\\": "lib/SurvosSupervisorBundle/src/"
```

When it is ready to split, move that directory into its own package and replace
the local PSR-4 autoload with a normal Composer dependency.
