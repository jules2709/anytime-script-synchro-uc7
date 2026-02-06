<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ZendeskSync\Utils;

use ZendeskSync\Application\Command\SyncZendeskMessagesCommand;
use ZendeskSync\Infrastructure\Config\ContainerFactory;

// Chargement des variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Config Zendesk
$zSubdomain = $_ENV['ZENDESK_SUBDOMAIN'] ?? '';
$zEmail = $_ENV['ZENDESK_EMAIL'] ?? '';
$zToken = $_ENV['ZENDESK_TOKEN'] ?? '';

// Config MariaDB
$dbConfig = [
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_NAME'] ?? '',
    'user' => $_ENV['DB_USER'] ?? '',
    'password' => $_ENV['DB_PASSWORD'] ?? ''
];

/**
 * Fonction principale utilisant l'architecture hexagonale
 */
function runSync(array $dbConfig, string $zSubdomain, string $zEmail, string $zToken): void
{
    try {
        $container = ContainerFactory::createContainer($dbConfig, $zSubdomain, $zEmail, $zToken);
        $bus = $container->get('messenger.bus');

        echo "Lancement de la synchronisation...\n";
        $bus->dispatch(new SyncZendeskMessagesCommand());
        echo "Synchronisation terminée avec succès.\n";

    } catch (Exception $e) {
        echo "Erreur lors de la synchronisation : " . $e->getMessage() . "\n";
    }
}

// Point d'entrée du script
if (php_sapi_name() === 'cli') {
    runSync($dbConfig, $zSubdomain, $zEmail, $zToken);
}
