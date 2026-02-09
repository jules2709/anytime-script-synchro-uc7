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

    /**
     * Fetch multiple tickets in bulk (optimized vs individual requests)
     * @return array{tickets: array<int, array>, users: array}
     */
    public function showMany(array $ticketIds): array;

    public function saveLastSync(int $timestamp): void;

    public function loadLastSync(): int;
}
