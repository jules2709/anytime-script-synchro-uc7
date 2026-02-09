<?php
declare(strict_types=1);

namespace HistorySync\Domain\Repository;

use HistorySync\Domain\Entity\HistoricalMessage;

interface MessageRepositoryInterface
{
    /**
     * Récupère tous les m_uid distincts ayant des messages avant la date limite.
     *
     * @return string[]
     */
    public function getAllUserIds(string $cutoffDate): array;

    /**
     * Charge les messages d'un utilisateur antérieurs à la date limite.
     *
     * @return HistoricalMessage[]
     */
    public function loadMessages(string $externalId, string $cutoffDate): array;
}
