<?php
declare(strict_types=1);

namespace ZendeskSync\Domain\Repository;

interface ZendeskRepositoryInterface
{
    /**
     * @return array{events: array, endTime: ?int, nextPage: ?string, endOfStream: bool}
     */
    public function fetchTicketEvents(int $startTime): array;

    /**
     * @return array{ticket: array, users: array}
     */
    public function fetchTicketDetails(int $ticketId): array;

    public function getAgentExternalId(string $agentName): ?string;

    public function saveLastSync(int $timestamp): void;

    public function loadLastSync(): int;
}
