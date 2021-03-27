<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Webf\FsFailoverBundle\Message\MessageInterface;

class AddRetryingDelayStampMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if ($message instanceof MessageInterface) {
            $retryCount = $message->getRetryCount();

            if ($retryCount > 0) {
                $stamp = new DelayStamp($this->getDelayForRetry($retryCount));
                $envelope = $envelope->with($stamp);
            }
        }

        return $stack->next()->handle($envelope->with(), $stack);
    }

    /**
     * Returns the delay for the nth retry (binary exponential backoff with a
     * limit of 10 minutes).
     */
    private function getDelayForRetry(int $nth): int
    {
        return min(10 * 60, (2 ** $nth)) * 1000;
    }
}
