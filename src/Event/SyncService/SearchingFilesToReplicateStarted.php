<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Event\SyncService;

class SearchingFilesToReplicateStarted
{
    public function __construct(
        private string $failoverAdapter,
    ) {
    }

    public function getFailoverAdapter(): string
    {
        return $this->failoverAdapter;
    }
}
