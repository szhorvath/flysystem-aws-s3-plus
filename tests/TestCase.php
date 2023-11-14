<?php

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Szhorvath\FlysystemAwsS3Plus\AwsS3PlusFilesystemServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            AwsS3PlusFilesystemServiceProvider::class,
        ];
    }
}
