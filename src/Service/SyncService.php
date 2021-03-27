<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Service;

use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webf\FsFailoverBundle\Event\SyncService\DeleteFileMessageDispatched;
use Webf\FsFailoverBundle\Event\SyncService\DeleteFileMessagePreDispatch;
use Webf\FsFailoverBundle\Event\SyncService\ListingContentFailed;
use Webf\FsFailoverBundle\Event\SyncService\ListingContentStarted;
use Webf\FsFailoverBundle\Event\SyncService\ListingContentSucceeded;
use Webf\FsFailoverBundle\Event\SyncService\ReplicateFileMessageDispatched;
use Webf\FsFailoverBundle\Event\SyncService\ReplicateFileMessagePreDispatch;
use Webf\FsFailoverBundle\Event\SyncService\SearchingFilesToReplicateStarted;
use Webf\FsFailoverBundle\Exception\FailoverAdapterNotFoundException;
use Webf\FsFailoverBundle\Exception\InvalidArgumentException;
use Webf\FsFailoverBundle\Flysystem\FailoverAdaptersLocatorInterface;
use Webf\FsFailoverBundle\Message\DeleteFile;
use Webf\FsFailoverBundle\Message\ReplicateFile;

class SyncService
{
    public const EXTRA_FILES_COPY = 'copy';
    public const EXTRA_FILES_DELETE = 'delete';
    public const EXTRA_FILES_IGNORE = 'ignore';

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private FailoverAdaptersLocatorInterface $failoverAdaptersLocator,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws FailoverAdapterNotFoundException if $adapterName is not found
     * @throws InvalidArgumentException         if $extraFilesStrategy is invalid
     */
    public function sync(
        string $adapterName,
        string $extraFilesStrategy = self::EXTRA_FILES_IGNORE
    ): void {
        $extraFilesStrategies = [
            self::EXTRA_FILES_COPY,
            self::EXTRA_FILES_DELETE,
            self::EXTRA_FILES_IGNORE,
        ];

        if (!in_array($extraFilesStrategy, $extraFilesStrategies)) {
            throw new InvalidArgumentException(sprintf('Argument $extraFilesStrategy must be one of "%s". "%s" given.', join('", "', $extraFilesStrategies), $extraFilesStrategy));
        }

        $adapter = $this->failoverAdaptersLocator->get($adapterName);

        $cache = new class() {
            /** @var array<int, array<string, int>> */
            private array $cache = [];

            public function adaptersCount(): int
            {
                return count($this->cache);
            }

            public function adapterItemsCount(int $adapter): int
            {
                return count($this->cache[$adapter] ?? []);
            }

            public function initializeAdapter(int $adapter): void
            {
                $this->cache[$adapter] = [];
            }

            public function clearAdapter(int $adapter): void
            {
                unset($this->cache[$adapter]);
            }

            public function addFile(StorageAttributes $file, int $adapter): void
            {
                $this->cache[$adapter][$file->path()] = $file->lastModified() ?: 0;
            }

            /**
             * Yield files that are present in $source adapter but not in other
             * ones.
             *
             * @return iterable<array{0: string, 1: int}> path of the file and the adapter in which it's missing
             */
            public function missingFilesFrom(int $source): iterable
            {
                foreach ($this->cache[$source] as $path => $lastModified) {
                    for ($destination = 0; $destination < count($this->cache); ++$destination) {
                        if ($source === $destination) {
                            continue;
                        }

                        $fileIsMissing = !key_exists($path, $this->cache[$destination] ?? []);
                        $fileIsOlder = $this->cache[$source][$path] ?? 0 > $this->cache[$destination][$path] ?? 0;

                        if ($fileIsMissing || $fileIsOlder) {
                            yield [$path, $destination];
                        }
                    }
                }
            }
        };

        foreach ($adapter->getInnerAdapters() as $i => $innerAdapter) {
            $cache->initializeAdapter($i);

            $this->eventDispatcher->dispatch(
                new ListingContentStarted($adapterName, $i)
            );

            try {
                foreach ($innerAdapter->listContents('/', true) as $item) {
                    if ($item->isFile()) {
                        $cache->addFile($item, $i);
                    }
                }

                $this->eventDispatcher->dispatch(
                    new ListingContentSucceeded(
                        $adapterName,
                        $i,
                        $cache->adapterItemsCount($i)
                    )
                );
            } catch (FilesystemException) {
                $cache->clearAdapter($i);

                $this->eventDispatcher->dispatch(
                    new ListingContentFailed($adapterName, $i)
                );
            }
        }

        $this->eventDispatcher->dispatch(
            new SearchingFilesToReplicateStarted($adapterName)
        );

        foreach ($cache->missingFilesFrom(0) as [$path, $destination]) {
            $this->replicateFile($adapterName, $path, 0, $destination);
        }

        if (self::EXTRA_FILES_IGNORE !== $extraFilesStrategy) {
            for ($source = 1; $source < $cache->adaptersCount(); ++$source) {
                foreach ($cache->missingFilesFrom($source) as [$path, $destination]) {
                    switch ($extraFilesStrategy) {
                        case self::EXTRA_FILES_COPY:
                            $this->replicateFile(
                                $adapterName,
                                $path,
                                $source,
                                $destination
                            );
                            break;
                        case self::EXTRA_FILES_DELETE:
                            $this->deleteFile($adapterName, $path, $source);
                            break;
                    }
                }
            }
        }
    }

    private function replicateFile(
        string $adapterName,
        string $path,
        int $source,
        int $destination,
    ): void {
        $message = new ReplicateFile(
            $adapterName,
            $path,
            $source,
            $destination
        );

        $this->eventDispatcher->dispatch(
            new ReplicateFileMessagePreDispatch($message)
        );

        $envelope = $this->messageBus->dispatch($message);

        $this->eventDispatcher->dispatch(
            new ReplicateFileMessageDispatched($envelope)
        );
    }

    private function deleteFile(
        string $adapterName,
        string $path,
        int $adapter,
    ): void {
        $message = new DeleteFile(
            $adapterName,
            $path,
            $adapter
        );

        $this->eventDispatcher->dispatch(
            new DeleteFileMessagePreDispatch($message)
        );

        $envelope = $this->messageBus->dispatch($message);

        $this->eventDispatcher->dispatch(
            new DeleteFileMessageDispatched($envelope)
        );
    }
}
