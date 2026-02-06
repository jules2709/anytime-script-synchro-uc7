<?php
declare(strict_types=1);

namespace ZendeskSync\Infrastructure\Persistence;

use PDO;
use ZendeskSync\Domain\Entity\Message;
use ZendeskSync\Domain\Repository\MessageRepositoryInterface;
use ZendeskSync\Utils;

class MariaDBMessageRepository implements MessageRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(Message $message): void
    {
        Utils::saveToMariadb($this->pdo, $message->toArray());
    }
}
