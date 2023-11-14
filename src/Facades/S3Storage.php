<?php

namespace Szhorvath\FlysystemAwsS3Plus\Facades;

use Illuminate\Support\Facades\Facade;

class S3Storage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 's3storage';
    }
}
