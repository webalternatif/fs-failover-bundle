<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Flysystem;

use Traversable;
use Webf\FsFailoverBundle\Exception\FailoverAdapterNotFoundException;

interface FailoverAdaptersLocatorInterface extends Traversable
{
    /**
     * @throws FailoverAdapterNotFoundException
     */
    public function get(string $name): FailoverAdapter;
}
