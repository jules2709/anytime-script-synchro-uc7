<?php
declare(strict_types=1);

namespace HistorySync\Domain\Repository;

interface ZendeskRepositoryInterface
{
    /**
     * Recherche le ticket Zendesk le plus récent créé après un timestamp donné
     * pour un utilisateur identifié par son external_id.
     *
     * @return int|null ID du ticket si trouvé, null sinon
     */
    public function findLatestTicketForUser(string $externalId, ?\DateTimeImmutable $createdAfter = null): ?int;

    /**
     * Met à jour un ticket Zendesk (titre, statut, tags).
     *
     * @param string[] $tags
     */
    public function updateTicket(int $ticketId, string $title, string $status, array $tags = []): bool;
}
