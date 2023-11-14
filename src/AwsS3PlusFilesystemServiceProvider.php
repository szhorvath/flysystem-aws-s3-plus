<?php

declare(strict_types=1);

namespace Szhorvath\FlysystemAwsS3Plus;

use Aws\S3\S3Client;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter as AwsS3PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Visibility;

class AwsS3PlusFilesystemServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('s3-plus', function (Application $app, array $config) {
            return $this->createS3ExtendedDriver($config);
        });
    }

    /**
     * Create an instance of the Amazon S3 extended driver.
     *
     *  @throws InvalidArgumentException
     */
    public function createS3ExtendedDriver(array $config): AwsS3V3PlusAdapter
    {
        $s3Config = $this->formatS3Config($config);

        $root = (string) ($s3Config['root'] ?? '');

        $visibility = new AwsS3PortableVisibilityConverter(
            $config['visibility'] ?? Visibility::PUBLIC
        );

        $streamReads = $s3Config['stream_reads'] ?? false;

        $client = new S3Client($s3Config);

        $adapter = new S3Adapter($client, $s3Config['bucket'], $root, $visibility, null, $config['options'] ?? [], $streamReads);

        $filesystem = new Flysystem($adapter, Arr::only($config, [
            'directory_visibility',
            'disable_asserts',
            'temporary_url',
            'url',
            'visibility',
        ]));

        return new AwsS3V3PlusAdapter(
            $filesystem, $adapter, $s3Config, $client
        );
    }

    /**
     * Format the given S3 configuration with the default options.
     *
     * @return array
     */
    protected function formatS3Config(array $config)
    {
        $config += ['version' => 'latest'];

        if (Arr::has($config, 'key') && Arr::has($config, 'secret')) {
            $config['credentials'] = Arr::only($config, ['key', 'secret']);
        }

        if (Arr::has($config, 'token')) {
            $config['credentials']['token'] = $config['token'];
        }

        return Arr::except($config, ['token']);
    }
}
