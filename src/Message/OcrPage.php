<?php

declare(strict_types=1);

namespace App\Message;

final readonly class OcrPage implements \Stringable
{
    public function __construct(
        public string $documentId,
        public int $page,
        public int $failureBias = 1,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('OCR %s page %d bias %d', $this->documentId, $this->page, $this->failureBias);
    }
}
