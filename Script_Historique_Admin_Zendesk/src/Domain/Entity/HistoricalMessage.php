<?php
declare(strict_types=1);

namespace HistorySync\Domain\Entity;

class HistoricalMessage
{
    public function __construct(
        public readonly string $authorType,
        public readonly string $text,
        public readonly int $done
    ) {
    }
}
