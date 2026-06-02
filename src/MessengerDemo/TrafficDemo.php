<?php

declare(strict_types=1);

namespace App\MessengerDemo;

use App\Message\GenerateThumbnail;
use App\Message\OcrPage;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class TrafficDemo
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsCommand('app:demo:flood', 'Dispatch believable demo Messenger traffic')]
    public function flood(
        SymfonyStyle $io,
        #[Argument('How many messages to dispatch')]
        int $count = 100,
        #[Option('Delay between dispatches in milliseconds')]
        int $delay = 25,
        #[Option('Bias OCR messages toward failure so the failed transport fills faster')]
        bool $badBatch = false,
    ): int {
        $ocr = 0;
        $thumbs = 0;

        for ($i = 1; $i <= $count; ++$i) {
            if (random_int(1, 100) <= 25) {
                $message = new OcrPage(
                    documentId: \sprintf('DOC-%04d', random_int(1, 400)),
                    page: random_int(1, 80),
                    failureBias: $badBatch ? 5 : 1,
                );
                ++$ocr;
            } else {
                $size = random_int(0, 2);
                $message = new GenerateThumbnail(
                    assetId: \sprintf('IMG-%06d', random_int(1, 20000)),
                    width: [320, 640, 1280][$size],
                    height: [240, 480, 960][$size],
                );
                ++$thumbs;
            }

            $this->bus->dispatch($message);
            $io->writeln(\sprintf('dispatched %3d/%d %s', $i, $count, $message));

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        $io->success(\sprintf('Dispatched %d messages: %d thumbnails, %d OCR jobs.', $count, $thumbs, $ocr));

        return Command::SUCCESS;
    }

    #[AsMessageHandler]
    public function handleThumbnail(GenerateThumbnail $message): void
    {
        $started = microtime(true);
        usleep(random_int(50_000, 200_000));

        if (1 === random_int(1, 250)) {
            throw new \RuntimeException(\sprintf('Thumbnail encoder crashed for %s.', $message->assetId));
        }

        $this->logHandled($message, $started);
    }

    #[AsMessageHandler]
    public function handleOcr(OcrPage $message): void
    {
        $started = microtime(true);
        usleep(random_int(400_000, 2_500_000));

        $failureWindow = max(2, 7 - $message->failureBias);
        if (1 === random_int(1, $failureWindow)) {
            throw new \RuntimeException(\sprintf('OCR engine rejected %s page %d.', $message->documentId, $message->page));
        }

        $this->logHandled($message, $started);
    }

    private function logHandled(\Stringable $message, float $started): void
    {
        if (!\defined('STDOUT')) {
            return;
        }

        fwrite(STDOUT, \sprintf(
            "handled %-45s in %7.1fms\n",
            (string) $message,
            (microtime(true) - $started) * 1000,
        ));
    }
}
