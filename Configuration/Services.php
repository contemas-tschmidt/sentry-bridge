<?php

declare(strict_types=1);

use AUS\SentryAsync\Queue\FileQueue;
use AUS\SentryBridge\EventListener\ConsoleErrorEventListener;
use Sentry\ClientInterface;
use Sentry\Client;
use AUS\SentryAsync\Command\FlushCommand;
use AUS\SentryAsync\Entry\Entry;
use AUS\SentryAsync\Factory\EntryFactory;
use AUS\SentryAsync\Queue\QueueInterface;
use AUS\SentryBridge\Factory\FileQueueFactory;
use AUS\SentryBridge\Factory\SentryClientFactory;
use AUS\SentryBridge\Handler\ContentObjectProductionExceptionHandler;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Frontend\ContentObject\Exception\ProductionExceptionHandler;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator, ContainerBuilder $builder): void {
    $services = $configurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    // Factories
    $services->set(EntryFactory::class)
        ->arg('$entryClass', Entry::class)
        ->public();
    $services->set(SentryClientFactory::class)->public();
    $services->set(FileQueueFactory::class)->public();

    $services->set(FileQueue::class)
        ->public()
        ->factory([service(FileQueueFactory::class), '__invoke'])
        ->args([
            service(ExtensionConfiguration::class),
            service(EntryFactory::class),
        ])
        ->autowire(false)
        ->autoconfigure(false);

    $services->alias(ClientInterface::class, Client::class);
    $services->set(ClientInterface::class)
        ->factory([service(SentryClientFactory::class), '__invoke']);
    $services->alias(QueueInterface::class, FileQueue::class);

    $original = 'andersundsehr.sentry-bridge.original.contentObject.productionExceptionHandler';
    $builder->register($original, ProductionExceptionHandler::class)
        ->setPublic(true)
        ->setShared(false)
        ->setAutowired(true)
        ->setAutoconfigured(true);
    $builder->register(ProductionExceptionHandler::class, ContentObjectProductionExceptionHandler::class)
        ->setPublic(true)
        ->setShared(false)
        ->setArguments([
            '$productionExceptionHandler' => new Reference($original),
        ]);

    $services->set(FlushCommand::class)
        ->tag('console.command', [
            'command' => 'andersundsehr:sentry-async:flush',
            'description' => 'Transports potentially queued sentry events'
        ]);

    $services->set(ConsoleErrorEventListener::class)
        ->tag('event.listener', [
            'identifier' => 'andersundsehr/sentry-bridge/console-error-event-listener',
            'event' => ConsoleErrorEvent::class
        ]);
};
