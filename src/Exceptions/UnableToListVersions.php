<?php

declare(strict_types=1);

namespace Szhorvath\FlysystemAwsS3Plus\Exceptions;

use League\Flysystem\FilesystemOperationFailed;
use RuntimeException;
use Throwable;

final class UnableToListVersions extends RuntimeException implements FilesystemOperationFailed
{
    private string $location;

    private string $reason;

    public static function create(string $location, string $reason = '', Throwable $previous = null): self
    {
        $e = new self("Unable to retrieve the versions for file at location: $location. {$reason}", 0, $previous);
        $e->reason = $reason;
        $e->location = $location;

        return $e;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function operation(): string
    {
        return FilesystemOperationFailed::OPERATION_LIST_CONTENTS;
    }
}
