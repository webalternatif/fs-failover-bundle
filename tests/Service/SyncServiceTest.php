<?php

declare(strict_types=1);

namespace Tests\Webf\FsFailoverBundle\Service;

use Generator;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Webf\FsFailoverBundle\Flysystem\FailoverAdapter;
use Webf\FsFailoverBundle\Flysystem\FailoverAdaptersLocator;
use Webf\FsFailoverBundle\Flysystem\FailoverAdaptersLocatorInterface;
use Webf\FsFailoverBundle\Message\DeleteFile;
use Webf\FsFailoverBundle\Message\ReplicateFile;
use Webf\FsFailoverBundle\MessageHandler\DeleteFileHandler;
use Webf\FsFailoverBundle\MessageHandler\ReplicateFileHandler;
use Webf\FsFailoverBundle\Service\SyncService;

/**
 * @internal
 * @covers \Webf\FsFailoverBundle\Service\SyncService
 */
class SyncServiceTest extends TestCase
{
    public function test_missing_files_in_secondary_storages_are_replicated_from_first_storage(): void
    {
        $config = new Config();
        $innerAdapter1 = new InMemoryFilesystemAdapter();
        $innerAdapter2 = new InMemoryFilesystemAdapter();
        $innerAdapter3 = new InMemoryFilesystemAdapter();

        $innerAdapter1->write($file1 = 'file1', $content1 = 'content1', $config);
        $innerAdapter1->write($file2 = 'file2', $content2 = 'content2', $config);
        $innerAdapter2->write($file1, $content1, $config);

        $syncService = $this->createSyncService([
            $adapterName = 'adapter' => [
                $innerAdapter1,
                $innerAdapter2,
                $innerAdapter3,
            ],
        ]);

        $syncService->sync($adapterName);

        $this->assertEquals($content1, $innerAdapter2->read($file1));
        $this->assertEquals($content1, $innerAdapter3->read($file1));
        $this->assertEquals($content2, $innerAdapter2->read($file2));
        $this->assertEquals($content2, $innerAdapter3->read($file2));
    }

    public function test_extra_files_in_secondary_storages_are_ignored_by_default(): void
    {
        $config = new Config();
        $innerAdapter1 = new InMemoryFilesystemAdapter();
        $innerAdapter2 = new InMemoryFilesystemAdapter();
        $innerAdapter3 = new InMemoryFilesystemAdapter();

        $innerAdapter2->write($file1 = 'file1', $content1 = 'content1', $config);
        $innerAdapter3->write($file2 = 'file2', $content2 = 'content2', $config);

        $syncService = $this->createSyncService([
            $adapterName = 'adapter' => [
                $innerAdapter1,
                $innerAdapter2,
                $innerAdapter3,
            ],
        ]);

        $syncService->sync($adapterName);

        $this->assertFalse($innerAdapter1->fileExists($file1));
        $this->assertFalse($innerAdapter1->fileExists($file2));
        $this->assertEquals($content1, $innerAdapter2->read($file1));
        $this->assertFalse($innerAdapter2->fileExists($file2));
        $this->assertFalse($innerAdapter3->fileExists($file1));
        $this->assertEquals($content2, $innerAdapter3->read($file2));
    }

    public function test_extra_files_in_secondary_storages_could_be_deleted(): void
    {
        $config = new Config();
        $innerAdapter1 = new InMemoryFilesystemAdapter();
        $innerAdapter2 = new InMemoryFilesystemAdapter();
        $innerAdapter3 = new InMemoryFilesystemAdapter();

        $innerAdapter2->write($file1 = 'file1', $content1 = 'content1', $config);
        $innerAdapter3->write($file2 = 'file2', $content2 = 'content2', $config);

        $syncService = $this->createSyncService([
            $adapterName = 'adapter' => [
                $innerAdapter1,
                $innerAdapter2,
                $innerAdapter3,
            ],
        ]);

        $syncService->sync($adapterName, SyncService::EXTRA_FILES_DELETE);

        $this->assertFalse($innerAdapter1->fileExists($file1));
        $this->assertFalse($innerAdapter1->fileExists($file2));
        $this->assertFalse($innerAdapter2->fileExists($file1));
        $this->assertFalse($innerAdapter2->fileExists($file2));
        $this->assertFalse($innerAdapter3->fileExists($file1));
        $this->assertFalse($innerAdapter3->fileExists($file2));
    }

    public function test_extra_files_in_secondary_storages_could_be_copied_in_other_storages(): void
    {
        $config = new Config();
        $innerAdapter1 = new InMemoryFilesystemAdapter();
        $innerAdapter2 = new InMemoryFilesystemAdapter();
        $innerAdapter3 = new InMemoryFilesystemAdapter();

        $innerAdapter2->write($file1 = 'file1', $content1 = 'content1', $config);
        $innerAdapter3->write($file2 = 'file2', $content2 = 'content2', $config);

        $syncService = $this->createSyncService([
            $adapterName = 'adapter' => [
                $innerAdapter1,
                $innerAdapter2,
                $innerAdapter3,
            ],
        ]);

        $syncService->sync($adapterName, SyncService::EXTRA_FILES_COPY);

        $this->assertEquals($content1, $innerAdapter1->read($file1));
        $this->assertEquals($content2, $innerAdapter1->read($file2));
        $this->assertEquals($content1, $innerAdapter2->read($file1));
        $this->assertEquals($content2, $innerAdapter2->read($file2));
        $this->assertEquals($content1, $innerAdapter3->read($file1));
        $this->assertEquals($content2, $innerAdapter3->read($file2));
    }

    /**
     * @param array<string, list<FilesystemAdapter>> $adapters
     */
    public function createSyncService(array $adapters): SyncService
    {
        $failoverAdaptersLocator = null;
        $middlewareGenerator = function () use (&$failoverAdaptersLocator): Generator {
            if (!$failoverAdaptersLocator instanceof FailoverAdaptersLocatorInterface) {
                throw new \LogicException('$failoverAdaptersLocator has not been initialized');
            }

            yield new HandleMessageMiddleware(new HandlersLocator([
                DeleteFile::class => [
                    new DeleteFileHandler($failoverAdaptersLocator, null),
                ],
                ReplicateFile::class => [
                    new ReplicateFileHandler($failoverAdaptersLocator, null),
                ],
            ]));
        };

        $messageBus = new MessageBus($middlewareGenerator());

        $failoverAdapters = [];
        foreach ($adapters as $adapterName => $innerAdapters) {
            $failoverAdapters[] = new FailoverAdapter(
                $adapterName,
                $innerAdapters,
                $messageBus
            );
        }

        $failoverAdaptersLocator = new FailoverAdaptersLocator(
            $failoverAdapters
        );

        return new SyncService(
            new EventDispatcher(),
            $failoverAdaptersLocator,
            $messageBus
        );
    }
}
