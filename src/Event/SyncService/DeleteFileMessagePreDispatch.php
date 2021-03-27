<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Event\SyncService;

use Webf\FsFailoverBundle\Message\DeleteFile;

class DeleteFileMessagePreDispatch
{
    public function __construct(private DeleteFile $message)
    {
    }

    public function getMessage(): DeleteFile
    {
        return $this->message;
    }
}
