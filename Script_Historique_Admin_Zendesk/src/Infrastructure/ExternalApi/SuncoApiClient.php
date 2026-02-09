<?php
declare(strict_types=1);

namespace HistorySync\Infrastructure\ExternalApi;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HistorySync\Domain\Entity\HistoricalMessage;
use HistorySync\Domain\Repository\SuncoRepositoryInterface;

class SuncoApiClient implements SuncoRepositoryInterface
{
    private readonly Client $client;
    private readonly string $baseUrl;
    private const REQUEST_TIMEOUT = 10;

    public function __construct(
        string $appId,
        string $keyId,
        string $secret
    ) {
        $this->baseUrl = "https://api.smooch.io/v2/apps/{$appId}";
        $this->client = new Client([
            'auth' => [$keyId, $secret],
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'timeout' => self::REQUEST_TIMEOUT
        ]);
    }

    public function deleteUser(string $externalId): bool
    {
        $url = "{$this->baseUrl}/users/{$externalId}";

        echo "Suppression de l'utilisateur SunCo : {$externalId}...\n";

        try {
            $response = $this->client->delete($url);

            if ($response->getStatusCode() === 200) {
                echo "Utilisateur {$externalId} supprimé avec succès.\n";
                return true;
            }
        } catch (GuzzleException $e) {
            $statusCode = $e->getCode();
            if ($statusCode === 404) {
                echo "Utilisateur {$externalId} introuvable (déjà supprimé).\n";
                return true;
            }
            echo "❌ Erreur suppression utilisateur : {$statusCode}\n";
            echo "   Détails : " . $e->getMessage() . "\n";
            return false;
        }

        return false;
    }

    public function ensureUser(string $externalId, string $name): bool
    {
        $url = "{$this->baseUrl}/users";

        $payload = [
            'externalId' => $externalId,
            'profile' => ['givenName' => $name]
        ];

        try {
            $response = $this->client->post($url, ['json' => $payload]);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 201) {
                echo "Utilisateur {$externalId} ({$name}) prêt dans SunCo.\n";
                return true;
            }
        } catch (GuzzleException $e) {
            $statusCode = $e->getCode();
            if ($statusCode === 409) {
                echo "Utilisateur {$externalId} ({$name}) prêt dans SunCo.\n";
                return true;
            }
            echo "❌ Erreur création/vérification utilisateur : " . $e->getMessage() . "\n";
            return false;
        }

        return false;
    }

    public function createConversation(string $externalId, array $messages, string $displayName): ?string
    {
        $url = "{$this->baseUrl}/conversations";

        $payload = [
            'type' => 'personal',
            'displayName' => $displayName,
            'participants' => [['userExternalId' => $externalId]]
        ];

        try {
            echo "Création de la conversation SunCo : '{$displayName}'...\n";
            $response = $this->client->post($url, ['json' => $payload]);

            if ($response->getStatusCode() !== 201) {
                echo "❌ Erreur création conversation : code " . $response->getStatusCode() . "\n";
                return null;
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $conversationId = $data['conversation']['id'] ?? null;

            if ($conversationId === null) {
                echo "❌ ID de conversation introuvable dans la réponse.\n";
                return null;
            }

            echo "Conversation créée : {$conversationId}\n\n";

            // Injecter les messages
            echo "Injection de " . count($messages) . " message(s)...\n";
            foreach ($messages as $message) {
                $this->postHistoricalMessage($conversationId, $message, $externalId);
            }
            echo "\n";

            return $conversationId;
        } catch (GuzzleException $e) {
            echo "❌ Erreur : " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function postHistoricalMessage(string $conversationId, HistoricalMessage $message, string $externalId): bool
    {
        $url = "{$this->baseUrl}/conversations/{$conversationId}/messages";

        $author = ['type' => $message->authorType];
        if ($message->authorType === 'user') {
            $author['userExternalId'] = $externalId;
        }

        $payload = [
            'author' => $author,
            'content' => [
                'type' => 'text',
                'text' => $message->text
            ]
        ];

        try {
            $response = $this->client->post($url, ['json' => $payload]);

            if ($response->getStatusCode() === 201) {
                // echo "   ✓ Message de {$message->authorType} ajouté.\n";
                return true;
            }

            echo "   ✗ Erreur ajout message : code " . $response->getStatusCode() . "\n";
            return false;
        } catch (GuzzleException $e) {
            echo "   ✗ Erreur ajout message : " . $e->getMessage() . "\n";
            return false;
        }
    }
}
