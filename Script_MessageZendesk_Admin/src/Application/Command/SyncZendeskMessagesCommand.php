<?php
declare(strict_types=1);

namespace ZendeskSync\Application\Command;

class SyncZendeskMessagesCommand
{
    public function __construct(
        public readonly ?int $startTime = null
    ) {
    }
}
