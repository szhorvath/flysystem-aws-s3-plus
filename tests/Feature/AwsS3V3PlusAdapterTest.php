
<?php

use Aws\Result;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter as AwsS3PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Visibility;
use Szhorvath\FlysystemAwsS3Plus\AwsS3V3PlusAdapter;

beforeEach(function () {
    $this->config = [
        'bucket' => $_ENV['AWS_BUCKET'] = 'testbucket',
        'region' => $_ENV['AWS_DEFAULT_REGION'] = 'eu-west-1',
        'url' => $_ENV['AWS_URL'] = 'http://127.0.0.1:9000',
        'endpoint' => 'http://127.0.0.1:9000',
        'use_path_style_endpoint' => true,
        'throw' => true,
        'version' => 'latest',
        'credentials' => [
            'key' => $_ENV['AWS_ACCESS_KEY_ID'] = 'sail',
            'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] = 'password',
        ],
        'root' => $_ENV['AWS_ROOT'] = 'test',
    ];

    $this->client = new S3Client($this->config);

    createTestBucket($this->client, $this->config);

    $visibility = new AwsS3PortableVisibilityConverter(Visibility::PUBLIC);

    $this->flyAdapter = new S3Adapter($this->client, $this->config['bucket'], $this->config['root'], $visibility);

    $this->adapter = new AwsS3V3PlusAdapter(
        new Flysystem($this->flyAdapter),
        $this->flyAdapter,
        $this->config,
        $this->client
    );

});

afterEach(function () {
    $results = $this->client->listObjectsV2([
        'Bucket' => $this->config['bucket'],
    ]);

    foreach ($results['Contents'] as $content) {
        $versions = $this->client->listObjectVersions([
            'Bucket' => $this->config['bucket'],
            'Prefix' => $content['Key'],
        ]);

        if (! isset($versions['Versions'])) {
            $objects[] = ['Key' => $content['Key']];

            continue;
        }

        foreach ($versions['Versions'] as $version) {
            $objects[] = [
                'Key' => $version['Key'],
                'VersionId' => $version['VersionId'],
            ];
        }
    }

    $this->client->deleteObjects([
        'Bucket' => $this->config['bucket'],
        'Delete' => [
            'Objects' => $objects,
        ],
    ]);

    $this->client->deleteBucket(['Bucket' => $this->config['bucket']]);
});

it('should get an object content', function () {
    putObject($this->client, $this->config, 'text.txt', 'data');

    expect($this->adapter->get('text.txt'))->toBe('data');
});

it('should get the content of an object with the exact version', function () {
    turnOnVersioning($this->client, $this->config);

    putObject($this->client, $this->config, 'text.txt', 'DataVersion1');
    putObject($this->client, $this->config, 'text.txt', 'DataVersionTwo');

    $versions = listObjectVersions($this->client, $this->config, 'text.txt')->get('Versions');

    expect($this->adapter->get('text.txt', $versions[1]['VersionId']))->toBe('DataVersion1');
});

it('should read the latest version when the path called', function () {
    turnOnVersioning($this->client, $this->config);

    putObject($this->client, $this->config, 'text.txt', 'DataVersion1');
    putObject($this->client, $this->config, 'text.txt', 'DataVersionTwo');

    expect($this->adapter->get('text.txt'))->toBe('DataVersionTwo');
});

it('should retrieve a list of versions of an S3 object', function () {
    turnOnVersioning($this->client, $this->config);

    $path = $this->config['root'].'/text.txt';

    putObject($this->client, $this->config, 'text.txt', 'DataVersion1');
    putObject($this->client, $this->config, 'text.txt', 'DataVersionTwo');

    $versions = $this->adapter->versions($path);

    expect($versions)->toBeCollection()->toHaveCount(2);

    expect($versions[0])
        ->hash->toBeString()
        ->key->toBe($path)
        ->version->toBeString()
        ->type->toBe('file')
        ->latest->toBeTrue()
        ->updatedAt->toBeInstanceOf(CarbonImmutable::class)
        ->size->toBe(14);

    expect($versions[1])
        ->hash->toBeString()
        ->key->toBe($path)
        ->version->toBeString()
        ->type->toBe('file')
        ->latest->toBeFalse()
        ->updatedAt->toBeInstanceOf(CarbonImmutable::class)
        ->size->toBe(12);
});

it('should get a temporary url of a specific version of an object', function () {
    turnOnVersioning($this->client, $this->config);

    putObject($this->client, $this->config, 'text.txt', 'DataVersion1');
    putObject($this->client, $this->config, 'text.txt', 'DataVersionTwo');

    $versions = listObjectVersions($this->client, $this->config, 'text.txt')->get('Versions');

    $expiresAt = Carbon::now()->addMinutes(1);

    $latestUrl = $this->adapter->temporaryUrl(
        path: 'text.txt',
        expiration: $expiresAt,
        versionId: $versions[0]['VersionId']
    );

    expect(parse_url($latestUrl))
        ->path->toBe("/{$this->config['bucket']}/test/text.txt")
        ->query->toContain('versionId='.$versions[0]['VersionId'])
        ->query->toContain('X-Amz-Expires=60');

    $firstUrl = $this->adapter->temporaryUrl(
        path: 'text.txt',
        expiration: $expiresAt,
        versionId: $versions[1]['VersionId']
    );

    expect(parse_url($firstUrl))
        ->path->toBe("/{$this->config['bucket']}/test/text.txt")
        ->query->toContain('versionId='.$versions[1]['VersionId'])
        ->query->toContain('X-Amz-Expires=60');
});

function createTestBucket(S3Client $client, array $config): void
{
    $params = ['Bucket' => $config['bucket']];

    try {
        $client->headBucket($params);
    } catch (\Throwable $th) {
        $client->createBucket($params);
    }
}

function putObject(S3Client $client, array $config, string $path, string $content): void
{
    $client->putObject([
        'Bucket' => $config['bucket'],
        'Key' => $config['root'].'/'.$path,
        'Body' => Utils::streamFor($content),
    ]);
}

function listObjectVersions(S3Client $client, array $config, string $path): Result
{
    return $client->listObjectVersions([
        'Bucket' => $config['bucket'],
        'Key' => $config['root'].'/'.$path,
    ]);
}

function turnOnVersioning(S3Client $client, array $config): void
{
    $client->putBucketVersioning([
        'Bucket' => $config['bucket'],
        'VersioningConfiguration' => [
            'MFADelete' => 'Disabled',
            'Status' => 'Enabled',
        ],
    ]);
}
