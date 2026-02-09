<?php
declare(strict_types=1);

namespace HistorySync\Infrastructure\Persistence;

use PDO;
use HistorySync\Domain\Entity\HistoricalMessage;
use HistorySync\Domain\Repository\MessageRepositoryInterface;

class MariaDBMessageRepository implements MessageRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getAllUserIds(string $cutoffDate): array
    {
        $query = "
            SELECT DISTINCT m_uid
            FROM messages
            WHERE m_date < :cutoff AND m_uid IS NOT NULL
            ORDER BY m_uid ASC
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':cutoff' => $cutoffDate]);

        $userIds = [];
        while ($row = $stmt->fetch()) {
            $userIds[] = (string)$row['m_uid'];
        }

        echo count($userIds) . " utilisateur(s) trouvé(s) avec des messages avant {$cutoffDate}.\n";

        return $userIds;
    }

    public function loadMessages(string $externalId, string $cutoffDate): array
    {
        $query = "
            SELECT m_content, m_reply_uid, m_date, m_done
            FROM messages
            WHERE m_uid = :uid AND m_date < :cutoff
            ORDER BY m_date ASC
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':uid' => $externalId, ':cutoff' => $cutoffDate]);

        $messages = [];
        while ($row = $stmt->fetch()) {
            $replyUid = $row['m_reply_uid'];
            $authorType = ($replyUid === null || (int)$replyUid === 0) ? 'user' : 'business';
            $done = $row['m_done'] !== null ? (int)$row['m_done'] : 0;

            $messages[] = new HistoricalMessage(
                authorType: $authorType,
                text: $row['m_content'],
                done: $done
            );
        }

        echo count($messages) . " message(s) récupéré(s) depuis MariaDB (avant {$cutoffDate}).\n";

        return $messages;
    }
}
