<?php
declare(strict_types=1);

namespace HistorySync\Domain\Repository;

use HistorySync\Domain\Entity\HistoricalMessage;

interface SuncoRepositoryInterface
{
    /**
     * Supprime un utilisateur SunCo par external_id.
     */
    public function deleteUser(string $externalId): bool;

    /**
     * Crée ou vérifie l'existence d'un utilisateur SunCo.
     */
    public function ensureUser(string $externalId, string $name): bool;

    /**
     * Crée une conversation SunCo et y injecte les messages.
     *
     * @param HistoricalMessage[] $messages
     * @return string|null ID de la conversation si succès, null sinon
     */
    public function createConversation(string $externalId, array $messages, string $displayName): ?string;
}
