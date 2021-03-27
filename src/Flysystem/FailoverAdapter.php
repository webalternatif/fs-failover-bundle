<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Flysystem;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Webf\FsFailoverBundle\Exception\InnerAdapterNotFoundException;
use Webf\FsFailoverBundle\Exception\UnsupportedOperationException;
use Webf\FsFailoverBundle\Message\DeleteDirectory;
use Webf\FsFailoverBundle\Message\DeleteFile;
use Webf\FsFailoverBundle\Message\ReplicateFile;

class FailoverAdapter implements FilesystemAdapter
{
    /**
     * @param iterable<int, FilesystemAdapter> $adapters
     */
    public function __construct(
        private string $name,
        private iterable $adapters,
        private MessageBusInterface $messageBus
    ) {
    }

    public function fileExists(string $path): bool
    {
        foreach ($this->adapters as $adapter) {
            try {
                return $adapter->fileExists($path);
            } catch (FilesystemException) {
                // TODO log exception ?
            }
        }

        throw UnableToCheckFileExistence::forLocation($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $writtenAdapter = null;

        foreach ($this->adapters as $name => $adapter) {
            try {
                $adapter->write($path, $contents, $config);
                $writtenAdapter = $name;
                break;
            } catch (FilesystemException) {
                // TODO log exception ?
            }
        }

        if (null !== $writtenAdapter) {
            foreach ($this->adapters as $name => $adapter) {
                if ($name !== $writtenAdapter) {
                    $this->messageBus->dispatch(
                        new ReplicateFile(
                            $this->name,
                            $path,
                            $writtenAdapter,
                            $name
                        )
                    );
                }
            }

            return;
        }

        throw UnableToWriteFile::atLocation($path);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $writtenAdapter = null;

        foreach ($this->adapters as $name => $adapter) {
            try {
                $adapter->writeStream($path, $contents, $config);
                $writtenAdapter = $name;
                break;
            } catch (FilesystemException) {
                // TODO log exception ?
            }
        }

        if (null !== $writtenAdapter) {
            foreach ($this->adapters as $name => $adapter) {
                if ($name !== $writtenAdapter) {
                    $this->messageBus->dispatch(
                        new ReplicateFile(
                            $this->name,
                            $path,
                            $writtenAdapter,
                            $name
                        )
                    );
                }
            }

            return;
        }

        throw UnableToWriteFile::atLocation($path);
    }

    public function read(string $path): string
    {
        foreach ($this->adapters as $adapter) {
            try {
                return $adapter->read($path);
            } catch (FilesystemException) {
                // TODO log exception ?
            }
        }

        throw UnableToReadFile::fromLocation($path);
    }

    public function readStream(string $path)
    {
        foreach ($this->adapters as $adapter) {
            try {
                return $adapter->readStream($path);
            } catch (FilesystemException) {
                // TODO log exception ?
            }
        }

        throw UnableToReadFile::fromLocation($path);
    }

    public function delete(string $path): void
    {
        foreach ($this->adapters as $name => $adapter) {
            try {
                $adapter->delete($path);
            } catch (FilesystemException) {
                // TODO log exception ?
                $this->messageBus->dispatch(
                    new DeleteFile($this->name, $path, $name)
                );
            }
        }
    }

    public function deleteDirectory(string $path): void
    {
        foreach ($this->adapters as $name => $adapter) {
            try {
                $adapter->deleteDirectory($path);
            } catch (FilesystemException) {
                // TODO log exception ?
                $this->messageBus->dispatch(
                    new DeleteDirectory($this->name, $path, $name)
                );
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        throw new UnsupportedOperationException(sprintf('Method "createDirectory" is not supported with "%s".', self::class));
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw new UnsupportedOperationException(sprintf('Method "setVisibility" is not supported with "%s".', self::class));
    }

    public function visibility(string $path): FileAttributes
    {
        foreach ($this->adapters as $adapter) {
            try {
                return $adapter->visibility($path);
            } catch (FilesystemException) {
                // TODO log exception ?
            }
        }

        throw UnableToRetrieveMetadata::visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        foreach ($this->adapters as $adapter) {
            try {
                return $adapter->mimeType($path);
            } catch (FilesystemException) {
                // TODO log exception ?
            }
        }

        throw UnableToRetrieveMetadata::mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        foreach ($this->adapters as $adapter) {
            try {
                return $adapter->lastModified($path);
            } catch (FilesystemException) {
                // TODO log exception ?
            }
        }

        throw UnableToRetrieveMetadata::lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        foreach ($this->adapters as $adapter) {
            try {
                return $adapter->fileSize($path);
            } catch (FilesystemException) {
                // TODO log exception ?
            }
        }

        throw UnableToRetrieveMetadata::fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        throw new UnsupportedOperationException(sprintf('Method "listContents" is not supported with "%s".', self::class));
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw new UnsupportedOperationException(sprintf('Method "move" is not supported with "%s".', self::class));
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw new UnsupportedOperationException(sprintf('Method "copy" is not supported with "%s".', self::class));
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @throws InnerAdapterNotFoundException
     */
    public function getInnerAdapter(int $index): FilesystemAdapter
    {
        foreach ($this->adapters as $i => $adapter) {
            if ($i === $index) {
                return $adapter;
            }
        }

        throw InnerAdapterNotFoundException::in($this->name, $index);
    }

    /**
     * @return iterable<int, FilesystemAdapter>
     */
    public function getInnerAdapters(): iterable
    {
        return $this->adapters;
    }
}
