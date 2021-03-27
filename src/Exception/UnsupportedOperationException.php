<?php

declare(strict_types=1);

namespace Webf\FsFailoverBundle\Exception;

use League\Flysystem\FilesystemException;

class UnsupportedOperationException extends LogicException implements FilesystemException
{
}
