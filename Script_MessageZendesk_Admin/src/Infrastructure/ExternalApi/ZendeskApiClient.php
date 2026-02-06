<?php
declare(strict_types=1);

namespace ZendeskSync\Infrastructure\ExternalApi;

use GuzzleHttp\Client;
use ZendeskSync\Domain\Repository\ZendeskRepositoryInterface;
use ZendeskSync\Utils;

class ZendeskApiClient implements ZendeskRepositoryInterface
{
    private readonly Client $client;
    private ?string $nextPageUrl = null;
    private ?array $agentCache = null;

    public function __construct(
        private readonly string $subdomain,
        string $email,
        string $token
    ) {
        $this->client = new Client([
            'auth' => ["{$email}/token", $token],
            'headers' => ['Accept' => 'application/json']
        ]);
    }

    public function fetchTicketEvents(int $startTime): array
    {
        $url = $this->nextPageUrl ?? "https://{$this->subdomain}.zendesk.com/api/v2/incremental/ticket_events.json?start_time={$startTime}&include=comment_events";

        $response = $this->client->get($url);
        $data = json_decode($response->getBody()->getContents(), true);

        $this->nextPageUrl = $data['next_page'] ?? null;

        return [
            'events' => $data['ticket_events'] ?? [],
            'endTime' => $data['end_time'] ?? null,
            'nextPage' => $this->nextPageUrl,
            'endOfStream' => $data['end_of_stream'] ?? false
        ];
    }

    public function fetchTicketDetails(int $ticketId): array
    {
        $url = "https://{$this->subdomain}.zendesk.com/api/v2/tickets/{$ticketId}.json?include=users";
        $response = $this->client->get($url);
        $data = json_decode($response->getBody()->getContents(), true);

        return [
            'ticket' => $data['ticket'] ?? [],
            'users' => $data['users'] ?? []
        ];
    }

    public function getAgentExternalId(string $agentName): ?string
    {
        if ($this->agentCache === null) {
            $this->agentCache = $this->loadAgentCache();
        }

        $cleanName = trim($agentName);
        $externalId = $this->agentCache[$cleanName] ?? null;

        if (!$externalId) {
            echo "⚠️ Agent '{$cleanName}' introuvable dans la liste des agents actifs.\n";
        }

        return $externalId;
    }

    private function loadAgentCache(): array
    {
        echo "🔄 Initialisation du cache des agents Zendesk...\n";
        $mapping = [];
        $roles_to_fetch = ['agent', 'admin'];

        foreach ($roles_to_fetch as $role) {
            $url = "https://{$this->subdomain}.zendesk.com/api/v2/users.json?role={$role}";

            while ($url) {
                try {
                    $response = $this->client->get($url);
                    $data = json_decode($response->getBody()->getContents(), true);

                    foreach ($data['users'] ?? [] as $user) {
                        if (!($user['active'] ?? false) || ($user['suspended'] ?? false)) {
                            continue;
                        }

                        $extId = $user['external_id'] ?? null;
                        if (isset($user['name'])) {
                            $mapping[$user['name']] = $extId;
                        }
                        if (isset($user['alias'])) {
                            $mapping[$user['alias']] = $extId;
                        }
                    }

                    $url = $data['next_page'] ?? null;
                } catch (\Exception $e) {
                    echo "❌ Erreur lors du chargement des agents ({$role}): " . $e->getMessage() . "\n";
                    break;
                }
            }
        }

        echo "✅ Cache chargé : " . count($mapping) . " correspondances trouvées.\n";
        return $mapping;
    }

    public function saveLastSync(int $timestamp): void
    {
        Utils::saveLastSync($timestamp);
    }

    public function loadLastSync(): int
    {
        return Utils::loadLastSync();
    }
}
