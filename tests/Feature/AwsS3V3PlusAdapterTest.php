
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
    $list = $this->client->listObjectVersions([
        'Bucket' => $this->config['bucket'],
    ]);

    $objects = [];

    if (isset($list['Versions'])) {
        foreach ($list['Versions'] as $version) {
            $objects[] = [
                'Key' => $version['Key'],
                'VersionId' => $version['VersionId'],
            ];
        }
    }

    if (isset($list['DeleteMarkers'])) {
        foreach ($list['DeleteMarkers'] as $version) {
            $objects[] = [
                'Key' => $version['Key'],
                'VersionId' => $version['VersionId'],
            ];
        }
    }

    if (count($objects) > 0) {
        $this->client->deleteObjects([
            'Bucket' => $this->config['bucket'],
            'Delete' => [
                'Objects' => $objects,
            ],
        ]);
    }

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

it('should create a delete marker when delete used without version', function () {
    turnOnVersioning($this->client, $this->config);

    putObject($this->client, $this->config, 'text.txt', 'data');

    $this->adapter->delete('text.txt');

    $list = listObjectVersions($this->client, $this->config, 'text.txt')->get('DeleteMarkers');

    expect($list)
        ->toBeArray()
        ->toHaveCount(1);

    expect($list[0])
        ->Key->toBe($this->config['root'].'/text.txt')
        ->IsLatest->toBeTrue();
});

it('should permanently delete a specific version of an object', function () {
    turnOnVersioning($this->client, $this->config);

    $versionId = putObject($this->client, $this->config, 'text.txt', 'data')->get('VersionId');

    $this->adapter->delete([$versionId => 'text.txt']);

    $list = listObjectVersions($this->client, $this->config, 'text.txt');

    expect($list)
        ->hasKey('Versions')->toBeFalse()
        ->hasKey('DeleteMarkers')->toBeFalse();
});

it('should restores the object by deleting the the delete marker', function () {
    turnOnVersioning($this->client, $this->config);

    putObject($this->client, $this->config, 'text.txt', 'data');

    $versionId = deleteObject($this->client, $this->config, 'text.txt')->get('VersionId');

    expect($this->adapter->delete([$versionId => 'text.txt']))->toBeTrue();

    $list = listObjectVersions($this->client, $this->config, 'text.txt');

    expect($list)
        ->get('Versions')->toBeArray()->toHaveCount(1)
        ->hasKey('DeleteMarkers')->toBeFalse();

    expect($list->get('Versions')[0])
        ->Key->toBe($this->config['root'].'/text.txt')
        ->IsLatest->toBeTrue()
        ->Size->toBe('4');
});

it('should restores the object by copying the version to the top of the stack', function () {
    turnOnVersioning($this->client, $this->config);

    $versionId = putObject($this->client, $this->config, 'text.txt', 'data')->get('VersionId');

    deleteObject($this->client, $this->config, 'text.txt');

    expect($this->adapter->restore('text.txt', $versionId))->toBeTrue();

    $list = listObjectVersions($this->client, $this->config, 'text.txt');

    expect($list)
        ->get('Versions')->toBeArray()->toHaveCount(2)
        ->get('DeleteMarkers')->toBeArray()->toHaveCount(1);

    expect($list->get('Versions')[0])
        ->Key->toBe($this->config['root'].'/text.txt')
        ->IsLatest->toBeTrue()
        ->Size->toBe('4');
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

function putObject(S3Client $client, array $config, string $path, string $content): Result
{
    return $client->putObject([
        'Bucket' => $config['bucket'],
        'Key' => $config['root'].'/'.$path,
        'Body' => Utils::streamFor($content),
    ]);
}

function deleteObject(S3Client $client, array $config, string $path, string $versionId = null): Result
{
    $version = $versionId ? ['VersionId' => $versionId] : [];

    return $client->deleteObject(array_merge([
        'Bucket' => $config['bucket'],
        'Key' => $config['root'].'/'.$path,
    ], $version));
}

function listObjectVersions(S3Client $client, array $config, string $path): Result
{
    return $client->listObjectVersions([
        'Bucket' => $config['bucket'],
        'Key' => $config['root'].'/'.$path,
    ]);
}

function turnOnVersioning(S3Client $client, array $config): Result
{
    return $client->putBucketVersioning([
        'Bucket' => $config['bucket'],
        'VersioningConfiguration' => [
            'MFADelete' => 'Disabled',
            'Status' => 'Enabled',
        ],
    ]);
}
