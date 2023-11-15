<?php

declare(strict_types=1);

namespace Szhorvath\FlysystemAwsS3Plus;

use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Conditionable;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use Psr\Http\Message\StreamInterface;
use Szhorvath\FlysystemAwsS3Plus\Exceptions\UnableToListVersions;
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
     * Get the URL for the file at the given path.
     *
     * @param  string  $path
     * @return string
     *
     * @throws \RuntimeException
     */
    public function url($path)
    {
        // If an explicit base URL has been set on the disk configuration then we will use
        // it as the base URL instead of the default path. This allows the developer to
        // have full control over the base path for this filesystem's generated URLs.
        if (isset($this->config['url'])) {
            return $this->concatPathToUrl($this->config['url'], $this->prefixer->prefixPath($path));
        }

        return $this->client->getObjectUrl(
            $this->config['bucket'], $this->prefixer->prefixPath($path)
        );
    }

    /**
     * Determine if temporary URLs can be generated.
     *
     * @return bool
     */
    public function providesTemporaryUrls()
    {
        return true;
    }

    /**
     * Get a temporary URL for the file at the given path.
     *
     * @param  string  $path
     * @param  DateTimeInterface  $expiration
     * @return string
     *
     * @throws RuntimeException
     * @throws LogicException
     * @throws InvalidArgumentException
     */
    public function temporaryUrl($path, $expiration, array $options = [], string $versionId = null)
    {
        $version = $versionId ? ['VersionId' => $versionId] : [];

        $command = $this->client->getCommand('GetObject', array_merge([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->prefixer->prefixPath($path),
        ], $options, $version));

        $uri = $this->client->createPresignedRequest(
            $command, $expiration, $options
        )->getUri();

        // If an explicit base URL has been set on the disk configuration then we will use
        // it as the base URL instead of the default path. This allows the developer to
        // have full control over the base path for this filesystem's generated URLs.
        if (isset($this->config['temporary_url'])) {
            $uri = $this->replaceBaseUrl($uri, $this->config['temporary_url']);
        }

        return (string) $uri;
    }

    /**
     * Get a temporary upload URL for the file at the given path.
     *
     * @param  string  $path
     * @param  DateTimeInterface  $expiration
     * @return array
     *
     * @throws RuntimeException
     * @throws LogicException
     * @throws InvalidArgumentException
     */
    public function temporaryUploadUrl($path, $expiration, array $options = [])
    {
        $command = $this->client->getCommand('PutObject', array_merge([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->prefixer->prefixPath($path),
        ], $options));

        $signedRequest = $this->client->createPresignedRequest(
            $command, $expiration, $options
        );

        $uri = $signedRequest->getUri();

        // If an explicit base URL has been set on the disk configuration then we will use
        // it as the base URL instead of the default path. This allows the developer to
        // have full control over the base path for this filesystem's generated URLs.
        if (isset($this->config['temporary_url'])) {
            $uri = $this->replaceBaseUrl($uri, $this->config['temporary_url']);
        }

        return [
            'url' => (string) $uri,
            'headers' => $signedRequest->getHeaders(),
        ];
    }

    /**
     * @throws UnableToReadFile
     * @throws InvalidArgumentException
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
        } catch (Throwable $th) {
            throw UnableToReadFile::fromLocation($path, '', $th);
        }
    }

    /**
     * Get a list of available versions of an object stored in S3
     *
     * @throws RuntimeException
     */
    private function listObjectVersions(string $path): array
    {
        $options = [
            'Bucket' => $this->config['bucket'],
            'Key' => $this->prefixer->prefixPath($path),
            'Prefix' => $path,
        ];

        try {
            $response = $this->client->listObjectVersions($options);

            if (! $response->hasKey('Versions')) {
                return [];
            }

            return [
                'versions' => $response->get('Versions'),
                'deleteMarkers' => $response->hasKey('DeleteMarkers') ? $response->get('DeleteMarkers') : [],
            ];
        } catch (Throwable $th) {
            throw UnableToListVersions::create($path, '', $th);
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

    /**
     * @return Collection<int, array{hash: string, key: string, version: string, type: string, latest: bool, updatedAt: CarbonImmutable, size: int}>
     *
     * @throws UnableToListVersions
     */
    public function versions(string $path): Collection
    {
        try {
            $versions = $this->listObjectVersions($path);

            $deleteMarkers = (new Collection($versions['deleteMarkers']))
                ->map(fn ($deleteMarker) => [
                    'hash' => str_replace('"', '', $deleteMarker['ETag']) ?? null, // https://github.com/aws/aws-sdk-net/issues/815#issuecomment-729056677
                    'key' => $deleteMarker['Key'],
                    'version' => $deleteMarker['VersionId'],
                    'type' => 'deleteMarker',
                    'latest' => (bool) $deleteMarker['IsLatest'],
                    'updatedAt' => new CarbonImmutable($deleteMarker['LastModified']),
                    'size' => 0,
                ]);

            return (new Collection($versions['versions']))
                ->map(fn ($version) => [
                    'hash' => str_replace('"', '', $version['ETag']), // https://github.com/aws/aws-sdk-net/issues/815#issuecomment-729056677
                    'key' => $version['Key'],
                    'version' => $version['VersionId'],
                    'type' => 'file',
                    'latest' => (bool) $version['IsLatest'],
                    'updatedAt' => new CarbonImmutable($version['LastModified']),
                    'size' => (int) $version['Size'],
                ])
                ->merge($deleteMarkers)
                ->sortByDesc('updatedAt')
                ->values();
        } catch (Throwable $th) {
            throw UnableToListVersions::create($path, '', $th);
        }
    }

    /**
     * @throws UnableToDeleteFile
     * @throws InvalidArgumentException
     */
    private function deleteObject(string $path, array $options = []): void
    {
        $arguments = ['Bucket' => $this->config['bucket'], 'Key' => $this->prefixer->prefixPath($path)] + $options;

        $command = $this->client->getCommand('DeleteObject', $arguments);

        try {
            $this->client->execute($command);
        } catch (Throwable $th) {
            throw UnableToDeleteFile::atLocation($path, '', $th);
        }
    }

    /**
     * Delete the file at a given path.
     *
     * @param  string|array  $paths
     * @return bool
     *
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function delete($paths)
    {
        $paths = is_array($paths) ? $paths : func_get_args();

        $success = true;
        $versioned = ! array_is_list($paths);

        foreach ($paths as $version => $path) {
            try {
                $options = $versioned ? ['VersionId' => $version] : [];

                $this->deleteObject($path, $options);
            } catch (UnableToDeleteFile $e) {
                throw_if($this->throwsExceptions(), $e);

                $success = false;
            }
        }

        return $success;
    }

    public function permanentDelete($paths): bool
    {
        $paths = is_array($paths) ? $paths : func_get_args();

        return true;
    }

    public function getClient(): S3Client
    {
        return $this->client;
    }
}
