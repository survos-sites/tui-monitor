<?php

declare(strict_types=1);

namespace App\Message;

final readonly class GenerateThumbnail implements \Stringable
{
    public function __construct(
        public string $assetId,
        public int $width,
        public int $height,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('Thumbnail %s %dx%d', $this->assetId, $this->width, $this->height);
    }
}
