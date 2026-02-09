<?php
declare(strict_types=1);

namespace HistorySync\Application\Command;

class SyncHistoryToZendeskCommand
{
    /**
     * @param string[] $ticketTags
     */
    public function __construct(
        public readonly ?string $externalId,
        public readonly string $cutoffDate,
        public readonly string $conversationTitle,
        public readonly string $ticketTitle,
        public readonly array $ticketTags,
        public readonly string $progressFile = 'sync_progress.log'
    ) {
    }
}
