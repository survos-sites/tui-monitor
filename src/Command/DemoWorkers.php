<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DemoWorkers
{
    private const HEALTH_URLS = [
        'https://www.debian.org/',
        'https://symfony.com/',
        'https://packagist.org/',
        'https://github.com/',
    ];

    private const DEBIAN_ISO_INDEX = 'https://cdimage.debian.org/debian-cd/current/amd64/iso-cd/';
    private const DEBIAN_SAMPLE_BYTES = 33554432;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    #[AsCommand(name: 'demo:ticker', description: 'Emit one timestamped line per second')]
    public function ticker(OutputInterface $output): int
    {
        $i = 0;
        while (true) {
            $output->writeln(\sprintf('tick %d %s', $i++, date('H:i:s')));
            sleep(1);
        }
    }

    #[AsCommand(name: 'demo:packagist-updates', description: 'Poll Packagist metadata changes and print updated packages')]
    public function packagistUpdates(OutputInterface $output): int
    {
        $cursor = null;

        while (true) {
            $url = 'https://packagist.org/metadata/changes.json'.(null === $cursor ? '?since=0' : '?since='.$cursor);
            $data = $this->fetchJson($url);
            $cursor = (string) ($data['timestamp'] ?? $cursor ?? '');
            $actions = $data['actions'] ?? [];

            if (!$actions) {
                $output->writeln(\sprintf('packagist %s no new updates cursor:%s', date('H:i:s'), $cursor));
            }

            foreach ($actions as $action) {
                $output->writeln(\sprintf(
                    'packagist %-7s %-45s at:%s cursor:%s',
                    $action['type'] ?? '?',
                    $action['package'] ?? '?',
                    isset($action['time']) ? date('H:i:s', (int) $action['time']) : '?',
                    $cursor,
                ));
            }

            sleep(5);
        }
    }

    #[AsCommand(name: 'demo:http-head', description: 'Continuously issue HEAD requests against real project URLs')]
    public function httpHead(OutputInterface $output): int
    {
        $round = 1;
        while (true) {
            $output->writeln(\sprintf('probe round %d at %s', $round++, date('H:i:s')));
            foreach (self::HEALTH_URLS as $url) {
                $started = microtime(true);
                $host = parse_url($url, PHP_URL_HOST) ?: $url;

                try {
                    $response = $this->httpClient->request('HEAD', $url, ['max_duration' => 10]);
                    $status = $response->getStatusCode();
                    $headers = $response->getHeaders(false);
                    $elapsedMs = (microtime(true) - $started) * 1000;
                    $length = $headers['content-length'][0] ?? '?';

                    $output->writeln(\sprintf('%-18s %3d %7.1fms bytes:%s', $host, $status, $elapsedMs, $length));
                } catch (\Throwable $e) {
                    $elapsedMs = (microtime(true) - $started) * 1000;
                    $output->writeln(\sprintf('%-18s ERR %7.1fms %s', $host, $elapsedMs, $e->getMessage()));
                }
            }
            $output->writeln('');
            sleep(10);
        }
    }

    #[AsCommand(name: 'demo:debian-download', description: 'Discover and stream the first 32 MiB of the current Debian netinst ISO')]
    public function debianDownload(OutputInterface $output): int
    {
        ProgressBar::setFormatDefinition('download', ' %current:9s%/%max:9s% [%bar%] %percent:3s%% %message%');

        $round = 1;
        while (true) {
            $url = $this->resolveDebianNetinstUrl();
            $file = basename($url);
            $headers = $this->httpClient->request('HEAD', $url, ['max_duration' => 15])->getHeaders(false);
            $size = (int) ($headers['content-length'][0] ?? 0);
            $sampleBytes = min(self::DEBIAN_SAMPLE_BYTES, $size > 0 ? $size : self::DEBIAN_SAMPLE_BYTES);

            $output->writeln(\sprintf('download round %d: %s total:%s sample:%s', $round++, $file, $this->formatBytes($size), $this->formatBytes($sampleBytes)));

            $bar = new ProgressBar($output, $sampleBytes);
            $bar->setFormat('download');
            $bar->setMessage($file);
            $bar->start();

            $downloaded = 0;
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Range' => \sprintf('bytes=0-%d', $sampleBytes - 1)],
                'max_duration' => 120,
            ]);

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }
                if (null !== $chunk->getError()) {
                    throw new \RuntimeException($chunk->getError());
                }

                $bytes = \strlen($chunk->getContent());
                if (0 === $bytes) {
                    continue;
                }

                $downloaded += $bytes;
                $bar->setProgress(min($downloaded, $sampleBytes));

                if ($downloaded >= $sampleBytes) {
                    break;
                }
            }

            $bar->finish();
            $output->writeln('');
            $output->writeln(\sprintf('sample complete: %s read', $this->formatBytes($downloaded)));
            sleep(3);
        }
    }

    #[AsCommand(name: 'demo:burst', description: 'Emit short bursts of high-rate output')]
    public function burst(OutputInterface $output): int
    {
        $cycle = 0;
        while (true) {
            for ($i = 0; $i < 100; ++$i) {
                $output->writeln(\sprintf('burst %d.%03d', $cycle, $i));
            }
            ++$cycle;
            usleep(750_000);
        }
    }

    #[AsCommand(name: 'demo:noisy', description: 'Alternate stdout and stderr output')]
    public function noisy(OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $i = 0;
        while (true) {
            $output->writeln(\sprintf('stdout %d', $i));
            $errorOutput->writeln(\sprintf('stderr %d', $i));
            ++$i;
            usleep(500_000);
        }
    }

    #[AsCommand(name: 'demo:progress', description: 'Render a real Symfony ProgressBar repeatedly')]
    public function progress(OutputInterface $output): int
    {
        ProgressBar::setFormatDefinition('demo', ' %current%/%max% [%bar%] %percent:3s%% %message%');

        $round = 1;
        while (true) {
            $bar = new ProgressBar($output, 100);
            $bar->setFormat('demo');
            $bar->setMessage(\sprintf('round %d', $round++));
            $bar->start();

            for ($i = 0; $i < 100; ++$i) {
                usleep(35_000);
                $bar->advance();
            }

            $bar->finish();
            $output->writeln('');
            $output->writeln('done');
            usleep(500_000);
        }
    }

    #[AsCommand(name: 'demo:flaky', description: 'Process fake queue messages until a random one throws an exception')]
    public function flaky(OutputInterface $output): int
    {
        $message = 1;
        while (true) {
            $id = random_int(100000, 999999);
            $output->writeln(\sprintf('handling message %d id:%d', $message++, $id));
            usleep(650_000);

            if (1 === random_int(1, 7)) {
                throw new \RuntimeException(\sprintf('Image resize failed for message id:%d: simulated corrupt upload', $id));
            }

            $output->writeln(\sprintf('ack message id:%d', $id));
        }
    }

    #[AsCommand(name: 'demo:oneshot', description: 'Exit successfully after printing a small directory listing')]
    public function oneshot(OutputInterface $output): int
    {
        $output->writeln('sample project files:');
        foreach (array_slice(scandir(getcwd() ?: '.') ?: [], 0, 20) as $file) {
            $output->writeln(' - '.$file);
        }

        return 0;
    }

    #[AsCommand(name: 'demo:long-lines', description: 'Emit long log lines for wrap/truncate testing')]
    public function longLines(OutputInterface $output): int
    {
        $i = 0;
        while (true) {
            $payload = base64_encode(random_bytes(150));
            $output->writeln(\sprintf('long-%d {"payload":"%s","status":"generated"}', $i++, $payload));
            usleep(1_500_000);
        }
    }

    #[AsCommand(name: 'demo:state-machine', description: 'Cycle fake jobs through state transitions')]
    public function stateMachine(OutputInterface $output): int
    {
        $states = ['queued', 'validating', 'rendering', 'uploading', 'published'];
        $job = 1000;

        while (true) {
            foreach ($states as $state) {
                $output->writeln(\sprintf('job:%d state:%s at:%s', $job, $state, date('H:i:s')));
                usleep(450_000);
            }
            ++$job;
        }
    }

    private function fetchJson(string $url): array
    {
        $payload = $this->httpClient->request('GET', $url, ['max_duration' => 15])->getContent(false);
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \RuntimeException(\sprintf('Expected JSON object from %s.', $url));
        }

        return $data;
    }

    private function resolveDebianNetinstUrl(): string
    {
        $index = $this->httpClient
            ->request('GET', self::DEBIAN_ISO_INDEX.'SHA256SUMS', ['max_duration' => 15])
            ->getContent(false)
        ;

        foreach (explode("\n", $index) as $line) {
            if (preg_match('/\s+(debian-[0-9.]+-amd64-netinst\.iso)$/', $line, $matches)) {
                return self::DEBIAN_ISO_INDEX.$matches[1];
            }
        }

        throw new \RuntimeException('Unable to discover the current Debian amd64 netinst ISO from SHA256SUMS.');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '?';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return \sprintf('%.1f %s', $value, $units[$unit]);
    }
}
