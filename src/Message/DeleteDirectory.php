<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Message;

class DeleteDirectory implements MessageInterface
{
    public function __construct(
        private string $failoverAdapter,
        private string $path,
        private int $innerAdapter,
        private int $retryCount = 0
    ) {
    }

    public function getFailoverAdapter(): string
    {
        return $this->failoverAdapter;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getInnerAdapter(): int
    {
        return $this->innerAdapter;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }
}
