<?php
declare(strict_types=1);

namespace HistorySync\Infrastructure\Config;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use HistorySync\Application\Command\SyncHistoryToZendeskCommand;
use HistorySync\Application\CommandHandler\SyncHistoryToZendeskHandler;
use HistorySync\Infrastructure\ExternalApi\SuncoApiClient;
use HistorySync\Infrastructure\ExternalApi\ZendeskApiClient;
use HistorySync\Infrastructure\Persistence\MariaDBMessageRepository;
use HistorySync\Utils;

class ContainerFactory
{
    public static function createContainer(
        array $dbConfig,
        string $zSubdomain,
        string $zEmail,
        string $zToken,
        string $suncoAppId,
        string $suncoKeyId,
        string $suncoSecret
    ): ContainerBuilder {
        $containerBuilder = new ContainerBuilder();

        // Instanciation des dépendances
        $pdo = Utils::getDbConnection($dbConfig);
        $messageRepository = new MariaDBMessageRepository($pdo);
        $suncoClient = new SuncoApiClient($suncoAppId, $suncoKeyId, $suncoSecret);
        $zendeskClient = new ZendeskApiClient($zSubdomain, $zEmail, $zToken);
        $handler = new SyncHistoryToZendeskHandler($messageRepository, $suncoClient, $zendeskClient);

        // Messenger Bus
        $containerBuilder->register('messenger.bus', MessageBus::class)
            ->setArguments([
                [
                    new HandleMessageMiddleware(new HandlersLocator([
                        SyncHistoryToZendeskCommand::class => [new HandlerDescriptor($handler)]
                    ]))
                ]
            ]);

        return $containerBuilder;
    }
}
