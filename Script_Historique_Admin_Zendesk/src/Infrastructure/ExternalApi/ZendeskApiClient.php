<?php
declare(strict_types=1);

namespace HistorySync\Infrastructure\ExternalApi;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HistorySync\Domain\Repository\ZendeskRepositoryInterface;

class ZendeskApiClient implements ZendeskRepositoryInterface
{
    private readonly Client $client;
    private readonly string $baseUrl;
    private const REQUEST_TIMEOUT = 10;
    private const MAX_RETRIES = 15;
    private const RETRY_DELAY = 1;

    public function __construct(
        string $subdomain,
        string $email,
        string $token
    ) {
        $this->baseUrl = "https://{$subdomain}.zendesk.com/api/v2";
        $this->client = new Client([
            'auth' => ["{$email}/token", $token],
            'headers' => ['Accept' => 'application/json'],
            'timeout' => self::REQUEST_TIMEOUT
        ]);
    }

    public function findLatestTicketForUser(string $externalId, ?\DateTimeImmutable $createdAfter = null): ?int
    {
        echo "Recherche du ticket le plus récent pour l'utilisateur {$externalId}...\n";
        if ($createdAfter !== null) {
            echo "   (créé après " . $createdAfter->format('c') . ")\n";
        }
        echo "   (max " . self::MAX_RETRIES . " tentatives, délai de " . self::RETRY_DELAY . "s entre chaque)\n\n";

        // Trouver le zendesk_user_id via external_id
        $zendeskUserId = $this->searchUserByExternalId($externalId);
        if ($zendeskUserId === null) {
            return null;
        }

        echo "   ✓ Utilisateur Zendesk trouvé : {$zendeskUserId}\n\n";

        // Récupérer les tickets avec retry
        $url = "{$this->baseUrl}/users/{$zendeskUserId}/tickets/requested.json";

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->client->get($url, [
                    'query' => [
                        'sort_by' => 'created_at',
                        'sort_order' => 'desc'
                    ]
                ]);

                if ($response->getStatusCode() !== 200) {
                    echo "❌ Erreur récupération tickets (tentative {$attempt}) : code " . $response->getStatusCode() . "\n";
                    return null;
                }

                $data = json_decode($response->getBody()->getContents(), true);
                $tickets = $data['tickets'] ?? [];

                // Filtrer par created_after si spécifié
                if ($createdAfter !== null) {
                    $createdAfterTimestamp = $createdAfter->getTimestamp();
                    $tickets = array_filter($tickets, static function (array $ticket) use ($createdAfterTimestamp): bool {
                        $ticketCreatedAt = $ticket['created_at'] ?? null;
                        if ($ticketCreatedAt === null) {
                            return false;
                        }
                        $ticketTimestamp = (new \DateTimeImmutable($ticketCreatedAt))->getTimestamp();
                        return $ticketTimestamp >= $createdAfterTimestamp;
                    });
                }

                if (!empty($tickets)) {
                    $ticket = reset($tickets);
                    $ticketId = (int)$ticket['id'];
                    echo "Ticket trouvé au bout de {$attempt} tentative(s) : #{$ticketId}\n\n";
                    return $ticketId;
                }

                // Pas encore de ticket, réessayer
                if ($attempt < self::MAX_RETRIES) {
                    echo "   Tentative {$attempt}/" . self::MAX_RETRIES . " : aucun ticket trouvé, nouvelle tentative dans " . self::RETRY_DELAY . "s...\n";
                    sleep(self::RETRY_DELAY);
                } else {
                    echo "Aucun ticket trouvé après " . self::MAX_RETRIES . " tentatives pour l'utilisateur {$externalId}.\n";
                    return null;
                }
            } catch (GuzzleException $e) {
                echo "❌ Erreur (tentative {$attempt}) : " . $e->getMessage() . "\n";
                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY);
                }
            }
        }

        return null;
    }

    public function updateTicket(int $ticketId, string $title, string $status, array $tags = []): bool
    {
        $url = "{$this->baseUrl}/tickets/{$ticketId}.json";

        $payload = [
            'ticket' => [
                'subject' => $title,
                'status' => $status,
            ]
        ];

        if (!empty($tags)) {
            $payload['ticket']['tags'] = $tags;
        }

        try {
            echo "Mise à jour du ticket Zendesk #{$ticketId}...\n";
            $response = $this->client->put($url, ['json' => $payload]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                $ticket = $data['ticket'] ?? [];
                echo "Ticket mis à jour : #{$ticketId}\n";
                echo "   Titre : " . ($ticket['subject'] ?? '') . "\n";
                echo "   Statut : " . ($ticket['status'] ?? '') . "\n";
                $ticketTags = $ticket['tags'] ?? [];
                if (!empty($ticketTags)) {
                    echo "   Tags : " . implode(', ', $ticketTags) . "\n";
                }
                echo "\n";
                return true;
            }

            echo "❌ Erreur mise à jour ticket : code " . $response->getStatusCode() . "\n";
            return false;
        } catch (GuzzleException $e) {
            echo "❌ Erreur : " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function searchUserByExternalId(string $externalId): ?int
    {
        $url = "{$this->baseUrl}/users/search.json";

        try {
            $response = $this->client->get($url, [
                'query' => ['external_id' => $externalId]
            ]);

            if ($response->getStatusCode() !== 200) {
                echo "❌ Erreur recherche utilisateur : code " . $response->getStatusCode() . "\n";
                return null;
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $users = $data['users'] ?? [];

            if (empty($users)) {
                echo "Aucun utilisateur Zendesk trouvé avec l'external_id {$externalId}\n";
                return null;
            }

            return (int)$users[0]['id'];
        } catch (GuzzleException $e) {
            echo "❌ Erreur recherche utilisateur : " . $e->getMessage() . "\n";
            return null;
        }
    }
}
