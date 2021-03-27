<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\MessageHandler;

use League\Flysystem\Config;
use League\Flysystem\FilesystemException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Webf\FsFailoverBundle\Flysystem\FailoverAdaptersLocatorInterface;
use Webf\FsFailoverBundle\Message\ReplicateFile;

class ReplicateFileHandler implements MessageHandlerInterface
{
    public function __construct(
        private FailoverAdaptersLocatorInterface $adaptersLocator,
        private ?MessageBusInterface $messageBus = null
    ) {
    }

    /**
     * @throws FilesystemException if inner adapter failed and there is no message bus
     */
    public function __invoke(ReplicateFile $message)
    {
        $failoverAdapter = $this->adaptersLocator->get(
            $message->getFailoverAdapter()
        );

        $sourceAdapter = $failoverAdapter->getInnerAdapter(
            $message->getInnerSourceAdapter()
        );

        $destinationAdapter = $failoverAdapter->getInnerAdapter(
            $message->getInnerDestinationAdapter()
        );

        try {
            $destinationAdapter->writeStream(
                $message->getPath(),
                $sourceAdapter->readStream($message->getPath()),
                new Config()
            );
        } catch (FilesystemException $exception) {
            if (null === $this->messageBus) {
                throw $exception;
            }

            // TODO log exception ?

            $this->messageBus->dispatch(new ReplicateFile(
                $message->getFailoverAdapter(),
                $message->getPath(),
                $message->getInnerSourceAdapter(),
                $message->getInnerDestinationAdapter(),
                $message->getRetryCount() + 1
            ));
        }
    }
}
