<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Event\SyncService;

use Webf\FsFailoverBundle\Message\ReplicateFile;

class ReplicateFileMessagePreDispatch
{
    public function __construct(private ReplicateFile $message)
    {
    }

    public function getMessage(): ReplicateFile
    {
        return $this->message;
    }
}
