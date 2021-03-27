<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Event\SyncService;

use Symfony\Component\Messenger\Envelope;

class ReplicateFileMessageDispatched
{
    public function __construct(private Envelope $envelope)
    {
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }
}
