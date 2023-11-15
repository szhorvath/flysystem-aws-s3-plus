<?php

declare(strict_types=1);

namespace Szhorvath\FlysystemAwsS3Plus\Exceptions;

use League\Flysystem\FilesystemOperationFailed;
use RuntimeException;
use Throwable;

final class UnableToRestoreFile extends RuntimeException implements FilesystemOperationFailed
{
    private string $path;

    public function path(): string
    {
        return $this->path;
    }

    public static function fromLocation(string $path, Throwable $previous = null): UnableToRestoreFile
    {
        $e = new self("Unable to restore file {$path}", 0, $previous);
        $e->path = $path;

        return $e;
    }

    public function operation(): string
    {
        return FilesystemOperationFailed::OPERATION_COPY;
    }
}
