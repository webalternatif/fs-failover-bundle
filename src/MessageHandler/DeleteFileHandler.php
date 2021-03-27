<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\MessageHandler;

use League\Flysystem\FilesystemException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Webf\FsFailoverBundle\Flysystem\FailoverAdaptersLocatorInterface;
use Webf\FsFailoverBundle\Message\DeleteFile;

class DeleteFileHandler implements MessageHandlerInterface
{
    public function __construct(
        private FailoverAdaptersLocatorInterface $adaptersLocator,
        private ?MessageBusInterface $messageBus = null
    ) {
    }

    /**
     * @throws FilesystemException if inner adapter failed and there is no message bus
     */
    public function __invoke(DeleteFile $message)
    {
        $adapter = $this->adaptersLocator
            ->get($message->getFailoverAdapter())
            ->getInnerAdapter($message->getInnerAdapter())
        ;

        try {
            $adapter->delete($message->getPath());
        } catch (FilesystemException $exception) {
            if (null === $this->messageBus) {
                throw $exception;
            }

            // TODO log exception ?

            $this->messageBus->dispatch(new DeleteFile(
                $message->getFailoverAdapter(),
                $message->getPath(),
                $message->getInnerAdapter(),
                $message->getRetryCount() + 1
            ));
        }
    }
}
