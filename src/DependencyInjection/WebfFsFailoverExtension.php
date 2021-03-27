<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Webf\FsFailoverBundle\Command\SyncCommand;
use Webf\FsFailoverBundle\Flysystem\FailoverAdapter;
use Webf\FsFailoverBundle\Flysystem\FailoverAdaptersLocator;
use Webf\FsFailoverBundle\Message\MessageInterface;
use Webf\FsFailoverBundle\MessageHandler\DeleteDirectoryHandler;
use Webf\FsFailoverBundle\MessageHandler\DeleteFileHandler;
use Webf\FsFailoverBundle\MessageHandler\ReplicateFileHandler;
use Webf\FsFailoverBundle\Messenger\AddRetryingDelayStampMiddleware;
use Webf\FsFailoverBundle\Service\SyncService;

/**
 * @psalm-type _Config=array{
 *     adapters: array<
 *         string,
 *         array{
 *             adapters: list<string>
 *         }
 *     >,
 *     bus_transport_dsn: string
 * }
 */
class WebfFsFailoverExtension extends Extension implements PrependExtensionInterface
{
    private const PREFIX = 'webf_fs_failover';

    public const FAILOVER_ADAPTER_SERVICE_ID_PREFIX = self::PREFIX . '.adapter';
    public const FAILOVER_ADAPTERS_LOCATOR_SERVICE_ID =
        self::PREFIX . '.adapters_locator';

    public const SYNC_SERVICE_ID = self::PREFIX . '.service.sync';

    public const MESSAGE_BUS_SERVICE_ID = self::PREFIX . '.message_bus';
    public const MESSAGE_BUS_TRANSPORT_NAME = self::PREFIX;

    public const ADD_RETRYING_DELAY_STAMP_MIDDLEWARE_SERVICE_ID =
        self::MESSAGE_BUS_SERVICE_ID . '.middleware.add_retrying_delay_stamp';

    public const DELETE_DIRECTORY_MESSAGE_HANDLER_SERVICE_ID =
        self::PREFIX . '.message_handler.delete_directory';
    public const DELETE_FILE_MESSAGE_HANDLER_SERVICE_ID =
        self::PREFIX . '.message_handler.delete_file';
    public const REPLICATE_FILE_MESSAGE_HANDLER_SERVICE_ID =
        self::PREFIX . '.message_handler.replicate_file';

    public const SCAN_COMMAND_SERVICE_ID = self::PREFIX . '.command.scan';

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var _Config $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (0 === count($config['adapters'])) {
            return;
        }

        $this->registerCommands($container);
        $this->registerFailoverAdapters($container, $config);
        $this->registerMessengerMiddlewares($container);
        $this->registerMessageHandlers($container, $config);
        $this->registerServices($container);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        /** @var array $configs */
        $configs = $container->getParameterBag()->resolveValue($configs);
        /** @var _Config $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (count($config['adapters']) > 0) {
            $this->prependMessengerConfig($container, $config);
        }
    }

    private function registerCommands(
        ContainerBuilder $container
    ): void {
        $container->setDefinition(
            self::SCAN_COMMAND_SERVICE_ID,
            (new Definition(SyncCommand::class))
                ->setArguments([
                    new Reference('event_dispatcher'),
                    new Reference(self::FAILOVER_ADAPTERS_LOCATOR_SERVICE_ID),
                    new Reference(self::SYNC_SERVICE_ID),
                ])
                ->addTag('console.command')
        );
    }

    /**
     * @param _Config $config
     */
    private function registerFailoverAdapters(
        ContainerBuilder $container,
        array $config
    ): void {
        $references = [];
        foreach ($config['adapters'] as $name => $failoverAdapter) {
            $serviceId = self::FAILOVER_ADAPTER_SERVICE_ID_PREFIX . '.' . $name;

            $container->setDefinition(
                $serviceId,
                (new Definition(FailoverAdapter::class))
                    ->setArguments([
                        $name,
                        array_map(
                            fn (string $adapter) => new Reference($adapter),
                            $failoverAdapter['adapters']
                        ),
                        new Reference(self::MESSAGE_BUS_SERVICE_ID),
                    ])
            );

            $references[] = new Reference($serviceId);
        }

        $container->setDefinition(
            self::FAILOVER_ADAPTERS_LOCATOR_SERVICE_ID,
            (new Definition(FailoverAdaptersLocator::class))
                ->setArguments([
                    new IteratorArgument($references),
                ])
        );
    }

    private function registerMessengerMiddlewares(
        ContainerBuilder $container
    ): void {
        $container->setDefinition(
            self::ADD_RETRYING_DELAY_STAMP_MIDDLEWARE_SERVICE_ID,
            new Definition(AddRetryingDelayStampMiddleware::class)
        );
    }

    /**
     * @param _Config $config
     */
    private function registerMessageHandlers(
        ContainerBuilder $container,
        array $config
    ): void {
        // Do not provide message bus to handlers if it's sync (to prevent infinite loops)
        $messageBusReference = 'sync://' !== $config['bus_transport_dsn']
            ? new Reference(self::MESSAGE_BUS_SERVICE_ID)
            : null;

        $container->setDefinition(
            self::DELETE_DIRECTORY_MESSAGE_HANDLER_SERVICE_ID,
            (new Definition(DeleteDirectoryHandler::class))
                ->setArguments([
                    new Reference(self::FAILOVER_ADAPTERS_LOCATOR_SERVICE_ID),
                    $messageBusReference,
                ])
                ->addTag('messenger.message_handler', [
                    'bus' => self::MESSAGE_BUS_SERVICE_ID,
                ])
        );

        $container->setDefinition(
            self::DELETE_FILE_MESSAGE_HANDLER_SERVICE_ID,
            (new Definition(DeleteFileHandler::class))
                ->setArguments([
                    new Reference(self::FAILOVER_ADAPTERS_LOCATOR_SERVICE_ID),
                    $messageBusReference,
                ])
                ->addTag('messenger.message_handler', [
                    'bus' => self::MESSAGE_BUS_SERVICE_ID,
                ])
        );

        $container->setDefinition(
            self::REPLICATE_FILE_MESSAGE_HANDLER_SERVICE_ID,
            (new Definition(ReplicateFileHandler::class))
                ->setArguments([
                    new Reference(self::FAILOVER_ADAPTERS_LOCATOR_SERVICE_ID),
                    $messageBusReference,
                ])
                ->addTag('messenger.message_handler', [
                    'bus' => self::MESSAGE_BUS_SERVICE_ID,
                ])
        );
    }

    private function registerServices(ContainerBuilder $container): void
    {
        $container->setDefinition(
            self::SYNC_SERVICE_ID,
            (new Definition(SyncService::class))
                ->setArguments([
                    new Reference('event_dispatcher'),
                    new Reference(self::FAILOVER_ADAPTERS_LOCATOR_SERVICE_ID),
                    new Reference(self::MESSAGE_BUS_SERVICE_ID),
                ])
        );
    }

    /**
     * @param _Config $config
     */
    private function prependMessengerConfig(
        ContainerBuilder $container,
        array $config
    ): void {
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'buses' => [
                    self::MESSAGE_BUS_SERVICE_ID => [
                        'middleware' => [
                            self::ADD_RETRYING_DELAY_STAMP_MIDDLEWARE_SERVICE_ID,
                        ],
                    ],
                ],
                'transports' => [
                    self::MESSAGE_BUS_TRANSPORT_NAME => $config['bus_transport_dsn'],
                ],
                'routing' => [
                    MessageInterface::class => self::MESSAGE_BUS_TRANSPORT_NAME,
                ],
            ],
        ]);
    }
}
