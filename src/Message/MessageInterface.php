<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Message;

interface MessageInterface
{
    public function getRetryCount(): int;
}
