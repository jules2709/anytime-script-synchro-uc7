<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use HistorySync\Application\Command\SyncHistoryToZendeskCommand;
use HistorySync\Infrastructure\Config\ContainerFactory;

// Chargement des variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Config Zendesk
$zSubdomain = $_ENV['ZENDESK_SUBDOMAIN'] ?? '';
$zEmail = $_ENV['ZENDESK_EMAIL'] ?? '';
$zToken = $_ENV['ZENDESK_TOKEN'] ?? '';

// Config SunCo
$suncoAppId = $_ENV['SUNCO_APP_ID'] ?? '';
$suncoKeyId = $_ENV['SUNCO_KEY_ID'] ?? '';
$suncoSecret = $_ENV['SUNCO_SECRET'] ?? '';

// Config MariaDB
$dbConfig = [
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_NAME'] ?? '',
    'user' => $_ENV['DB_USER'] ?? '',
    'password' => $_ENV['DB_PASSWORD'] ?? ''
];

// Config de synchronisation
$cutoffDate = '2026-01-22';
$conversationTitle = 'Historique de vos messages avant le 30/01/2026';
$ticketTitle = 'Historique des messages précédent la migration sur Zendesk';
$ticketTags = ['historique_admin'];

// Argument CLI optionnel : external_id d'un utilisateur unique
$externalId = $argv[1] ?? null;

/**
 * Fonction principale utilisant l'architecture hexagonale
 */
function runSync(
    array $dbConfig,
    string $zSubdomain,
    string $zEmail,
    string $zToken,
    string $suncoAppId,
    string $suncoKeyId,
    string $suncoSecret,
    ?string $externalId,
    string $cutoffDate,
    string $conversationTitle,
    string $ticketTitle,
    array $ticketTags
): void {
    try {
        $container = ContainerFactory::createContainer(
            $dbConfig,
            $zSubdomain,
            $zEmail,
            $zToken,
            $suncoAppId,
            $suncoKeyId,
            $suncoSecret
        );
        $bus = $container->get('messenger.bus');

        echo "Lancement de la synchronisation historique...\n";

        $command = new SyncHistoryToZendeskCommand(
            externalId: $externalId,
            cutoffDate: $cutoffDate,
            conversationTitle: $conversationTitle,
            ticketTitle: $ticketTitle,
            ticketTags: $ticketTags
        );

        $bus->dispatch($command);
        echo "Synchronisation terminée.\n";

    } catch (Exception $e) {
        echo "Erreur lors de la synchronisation : " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Point d'entrée du script
if (php_sapi_name() === 'cli') {
    runSync(
        $dbConfig,
        $zSubdomain,
        $zEmail,
        $zToken,
        $suncoAppId,
        $suncoKeyId,
        $suncoSecret,
        $externalId,
        $cutoffDate,
        $conversationTitle,
        $ticketTitle,
        $ticketTags
    );
}
