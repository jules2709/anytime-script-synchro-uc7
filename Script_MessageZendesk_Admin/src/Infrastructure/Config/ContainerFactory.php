<?php
declare(strict_types=1);

namespace ZendeskSync\Infrastructure\Config;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use ZendeskSync\Application\Command\SyncZendeskMessagesCommand;
use ZendeskSync\Application\CommandHandler\SyncZendeskMessagesHandler;
use ZendeskSync\Infrastructure\ExternalApi\ZendeskApiClient;
use ZendeskSync\Infrastructure\Persistence\MariaDBMessageRepository;
use ZendeskSync\Utils;
use PDO;

class ContainerFactory
{
    public static function createContainer(array $dbConfig, string $zSubdomain, string $zEmail, string $zToken): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();

        // Database
        $containerBuilder->register('pdo', PDO::class)
            ->setFactory([Utils::class, 'getDbConnection'])
            ->setArguments([$dbConfig]);

        // Repositories
        $containerBuilder->register(MariaDBMessageRepository::class)
            ->setArguments([new Reference('pdo')]);

        $containerBuilder->register(ZendeskApiClient::class)
            ->setArguments([$zSubdomain, $zEmail, $zToken]);

        // Handlers - Créer l'instance directement pour HandlerDescriptor
        $pdo = Utils::getDbConnection($dbConfig);
        $messageRepository = new MariaDBMessageRepository($pdo);
        $zendeskClient = new ZendeskApiClient($zSubdomain, $zEmail, $zToken);
        $handler = new SyncZendeskMessagesHandler($messageRepository, $zendeskClient);

        // Messenger Bus
        $containerBuilder->register('messenger.bus', MessageBus::class)
            ->setArguments([
                [
                    new HandleMessageMiddleware(new HandlersLocator([
                        SyncZendeskMessagesCommand::class => [new HandlerDescriptor($handler)]
                    ]))
                ]
            ]);

        return $containerBuilder;
    }
}
