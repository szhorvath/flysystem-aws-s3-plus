<?php

declare(strict_types=1);

namespace Szhorvath\FlysystemAwsS3Plus;

use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Traits\Conditionable;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use Psr\Http\Message\StreamInterface;
use Throwable;

class AwsS3V3PlusAdapter extends FilesystemAdapter
{
    use Conditionable;

    protected S3Client $client;

    public function __construct(FilesystemOperator $driver, S3Adapter $adapter, array $config, S3Client $client)
    {
        parent::__construct($driver, $adapter, $config);

        $this->client = $client;
    }

    /**
     * @throws UnableToReadFile|InvalidArgumentException
     */
    private function readObject(string $path, bool $wantsStream, array $options = []): StreamInterface
    {
        $arguments = ['Bucket' => $this->config['bucket'], 'Key' => $this->prefixer->prefixPath($path)] + $options;

        $wantsStream = $wantsStream && ($this->config['stream_reads'] ?? false);

        if ($wantsStream && ! isset($arguments['@http']['stream'])) {
            $arguments['@http']['stream'] = true;
        }

        $command = $this->client->getCommand('GetObject', $arguments);

        try {
            return $this->client->execute($command)->get('Body');
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, '', $exception);
        }
    }

    /**
     * @param  string  $path
     * @param  mixed  $versionId
     * @return string|null
     *
     * @throws Throwable
     * @throws RuntimeException
     */
    public function get($path, $versionId = null)
    {
        $options = $versionId ? ['VersionId' => $versionId] : [];

        try {
            $body = $this->readObject($path, false, $options);

            return $body->getContents();
        } catch (UnableToReadFile $e) {
            throw_if($this->throwsExceptions(), $e);
        }
    }

    public function getClient(): S3Client
    {
        return $this->client;
    }
}
