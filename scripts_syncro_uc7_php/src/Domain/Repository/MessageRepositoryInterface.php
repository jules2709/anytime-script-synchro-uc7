<?php
declare(strict_types=1);

namespace ZendeskSync\Domain\Repository;

use ZendeskSync\Domain\Entity\Message;

interface MessageRepositoryInterface
{
    public function save(Message $message): void;
}
