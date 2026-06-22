<?php

declare(strict_types=1);

namespace Survos\SupervisorBundle\Command;

use Survos\SupervisorBundle\Process\ManagedProcess;
use Survos\SupervisorBundle\Process\ProcessConfig;
use Survos\SupervisorBundle\Process\Supervisor;
use Survos\SupervisorBundle\SurvosSupervisorBundle;
use Survos\SupervisorBundle\Tui\Dashboard;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ServiceProviderInterface;

#[AsCommand(name: 'survos:supervisor', description: 'Multi-process supervisor with TUI dashboard')]
final class SupervisorCommand
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%survos_supervisor.config%')]
        private array $bundleConfig,
        // The messenger transport registry — used only by --workflow to discover queues. Wired
        // null-on-invalid in the bundle extension, so the supervisor stays usable as a generic
        // process runner without symfony/messenger.
        private readonly ?ServiceProviderInterface $receivers = null,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option(description: 'Path to a supervisor YAML file (defaults to ./supervisor.yaml, then bundle config)', shortcut: 'c')]
        ?string $config = null,
        #[Option(description: 'Disable TUI; stream prefixed output lines to stdout')]
        bool $noTui = false,
        #[Option(description: 'Supervise one messenger:consume worker per transport matching this code (e.g. "dataset" → dataset.raw, dataset.normalize, …)', shortcut: 'w')]
        ?string $workflow = null,
    ): int {
        $io = new SymfonyStyle($input, $output);
        [$resolved, $source] = null !== $workflow
            ? $this->transportConfig($io, $workflow)
            : $this->loadConfig($config, getcwd() ?: $this->projectDir);

        if (!$resolved['processes']) {
            $io->error(null !== $workflow
                ? sprintf('No messenger transports match "%s".', $workflow)
                : 'No processes configured. Add a `processes:` section to your supervisor config.');

            return Command::FAILURE;
        }

        $processConfigs = [];
        foreach ($resolved['processes'] as $name => $entry) {
            $processConfigs[] = ProcessConfig::fromArray($name, $entry);
        }

        $supervisor = new Supervisor($this->projectDir, $processConfigs, $resolved['ring_buffer_lines']);

        if ($noTui) {
            return $this->runStreaming($io, $supervisor, $source);
        }

        return (new Dashboard($supervisor, $resolved['follow_by_default']))->run();
    }

    /**
     * Build a config on the fly: one `messenger:consume <transport>` per messenger transport whose
     * name matches $code (exact, or prefix "<code>."). Discovered from the messenger transport
     * registry, so it needs no knowledge of HOW the queues were defined (workflows, etc.).
     *
     * @return array{0: array, 1: ?string}
     */
    private function transportConfig(SymfonyStyle $io, string $code): array
    {
        $names = null !== $this->receivers ? array_keys($this->receivers->getProvidedServices()) : [];
        if ([] === $names) {
            $io->warning('No messenger transport registry available (is symfony/messenger installed?).');
        }

        $matched = array_values(array_filter(
            $names,
            static fn (string $n): bool => $n === $code || str_starts_with($n, $code . '.'),
        ));
        sort($matched);

        $processes = [];
        foreach ($matched as $transport) {
            $processes[$transport] = [
                'cmd' => ['php', 'bin/console', 'messenger:consume', $transport, '-v', '--time-limit=3600', '--memory-limit=512M'],
                'restart' => 'always',
            ];
        }

        return [
            ['processes' => $processes, 'ring_buffer_lines' => 5000, 'follow_by_default' => true],
            'workflow:' . $code,
        ];
    }

    /**
     * Resolution order: --config=PATH → ./supervisor.yaml → bundle config.
     *
     * @return array{0: array, 1: ?string}
     */
    private function loadConfig(?string $configPath, string $cwd): array
    {
        if (null !== $configPath) {
            if (!is_file($configPath)) {
                throw new \RuntimeException(\sprintf('Config file not found: %s', $configPath));
            }
            $raw = Yaml::parseFile($configPath);
            $source = realpath($configPath) ?: $configPath;
        } elseif (is_file($cwdYaml = $cwd.'/supervisor.yaml')) {
            $raw = Yaml::parseFile($cwdYaml);
            $source = $cwdYaml;
        } else {
            $raw = $this->bundleConfig;
            $source = null;
        }

        $tb = new TreeBuilder('supervisor');
        SurvosSupervisorBundle::applyConfigTree($tb->getRootNode());

        return [(new Processor())->process($tb->buildTree(), [$raw]), $source];
    }

    private function runStreaming(SymfonyStyle $io, Supervisor $supervisor, ?string $source): int
    {
        $io->title('survos:supervisor (--no-tui)');
        $io->writeln(\sprintf(' source: <comment>%s</comment>', $source ?? '(bundle config)'));
        $io->writeln(\sprintf(' processes: <info>%s</info>', implode(', ', $supervisor->names())));
        $io->writeln(' Ctrl-C to quit');
        $io->newLine();

        $stop = false;
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $handler = static function () use (&$stop): void { $stop = true; };
            pcntl_signal(\SIGINT, $handler);
            pcntl_signal(\SIGTERM, $handler);
        }

        $widths = array_map(strlen(...), $supervisor->names());
        $nameCol = max([8, ...$widths]);

        $supervisor->onLine(function (string $name, string $line) use ($io, $nameCol): void {
            $io->writeln(\sprintf('<info>%s</info> │ %s', str_pad($name, $nameCol), $line));
        });

        $previousState = array_fill_keys($supervisor->names(), '');
        $supervisor->startAll();

        while (!$stop) {
            foreach ($supervisor->tick() as $name) {
                $managed = $supervisor->get($name);
                if (null === $managed) {
                    continue;
                }
                $state = $this->describeState($managed);
                if ($state !== $previousState[$name]) {
                    $io->writeln(\sprintf('<comment>%s</comment> ┊ <fg=yellow>%s</>', str_pad($name, $nameCol), $state));
                    $previousState[$name] = $state;
                }
            }
            usleep(50_000);
        }

        $io->newLine();
        $io->writeln('<comment>shutting down…</comment>');
        $supervisor->stopAll();
        $io->success('all processes stopped');

        return Command::SUCCESS;
    }

    private function describeState(ManagedProcess $managed): string
    {
        if ($managed->isPaused()) {
            return 'paused';
        }
        if ($managed->isRunning()) {
            return 'running';
        }
        if ($managed->isWaitingForRestart()) {
            $secs = max(0.0, ($managed->nextRestartAt() ?? 0.0) - microtime(true));

            return \sprintf('restart in %.1fs (last exit %s)', $secs, $managed->lastExitCode() ?? '?');
        }
        if ($managed->isManuallyStopped()) {
            return 'stopped';
        }

        return \sprintf('exited (%s)', $managed->lastExitCode() ?? '?');
    }
}
