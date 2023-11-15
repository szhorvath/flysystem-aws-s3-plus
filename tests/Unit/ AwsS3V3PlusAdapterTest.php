
<?php

use Aws\Api\DateTimeResult;
use Aws\MockHandler;
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

it('should get an object content', function () {
    $stream = Utils::streamFor('data');

    $adapter = mockAdapter(new Result(['Body' => $stream]));

    expect($adapter->get('text.txt'))->toBe('data');
});

it('should get the content of an object with the exact version', function () {
    $stream = Utils::streamFor('data');

    $adapter = mockAdapter(new Result(['Body' => $stream]));

    expect($adapter->get('text.txt', 'version-id-string'))->toBe('data');
});

it('should retrieve a list of versions of an S3 object', function () {

    $result = new Result([
        'Versions' => [
            [
                'ETag' => '"27a03c63edc43a5191fb5d2868021a17"',
                'Size' => '14',
                'StorageClass' => 'STANDARD',
                'Key' => 'test/text.txt',
                'VersionId' => '5d0f17d4-f4a5-4d9d-a296-383202bd5d35',
                'IsLatest' => true,
                'LastModified' => DateTimeResult::fromISO8601('2024-08-13T14:39:22.000Z'),
                'Owner' => [
                    'DisplayName' => 'minio',
                    'ID' => '02d6176db174dc93cb1b899f7c6078f08654445fe8cf1b6ce98d8855f66bdbf4',
                ],
            ],
            [
                'ETag' => '"9310ca6aea85baa1adb30292d379b274"',
                'Size' => '12',
                'StorageClass' => 'STANDARD',
                'Key' => 'test/text.txt',
                'VersionId' => '850de980-15f2-450f-bcd6-1f08d60f3988',
                'IsLatest' => false,
                'LastModified' => DateTimeResult::fromISO8601('2023-08-13T14:39:22.000Z'),
                'Owner' => [
                    'DisplayName' => 'minio',
                    'ID' => '02d6176db174dc93cb1b899f7c6078f08654445fe8cf1b6ce98d8855f66bdbf4',
                ],
            ],
        ]]);

    $adapter = mockAdapter($result);

    $path = 'test/text.txt';

    $versions = $adapter->versions($path);

    expect($versions)->toBeCollection()->toHaveCount(2);

    expect($versions[0])
        ->hash->toBe('27a03c63edc43a5191fb5d2868021a17')
        ->key->toBe($path)
        ->version->toBe('5d0f17d4-f4a5-4d9d-a296-383202bd5d35')
        ->type->toBe('file')
        ->latest->toBeTrue()
        ->updatedAt->toBeInstanceOf(CarbonImmutable::class)
        ->size->toBe(14);

    expect($versions[1])
        ->hash->toBe('9310ca6aea85baa1adb30292d379b274')
        ->key->toBe($path)
        ->version->toBe('850de980-15f2-450f-bcd6-1f08d60f3988')
        ->type->toBe('file')
        ->latest->toBeFalse()
        ->updatedAt->toBeInstanceOf(CarbonImmutable::class)
        ->size->toBe(12);
});

it('should get a temporary url of a specific version of an object', function () {
    $adapter = mockAdapter(new Result());

    $expiresAt = Carbon::now()->addMinutes(1);

    $url = $adapter->temporaryUrl(
        path: 'text.txt',
        expiration: $expiresAt,
        versionId: 'version-id-string'
    );

    expect(parse_url($url))
        ->path->toBe('/testbucket/test/text.txt')
        ->query->toContain('versionId=version-id-string')
        ->query->toContain('X-Amz-Expires=60');
});

it('should create a delete marker when delete used without version', function ($params, $result) {
    $adapter = mockAdapter($result);

    if (is_array($params) && count($params) === 3) {
        [$param1, $param2, $param3] = $params;

        expect($adapter->delete($param1, $param2, $param3))->toBeTrue();
    } else {
        expect($adapter->delete($params))->toBeTrue();
    }
})->with([
    'delete single' => [
        'text.txt',
        new Result([
            'DeleteMarker' => true,
            'VersionId' => '213da9a6-3f07-42c4-b7dc-e352d0cb6a0f',
        ])],
    'delete multiple as array' => [
        ['text1.txt', 'text2.txt'],
        [
            new Result([
                'DeleteMarker' => true,
                'VersionId' => '213da9a6-3f07-42c4-b7dc-e352d0cb6a0f',
            ]),
            new Result([
                'DeleteMarker' => true,
                'VersionId' => '313da9a6-3f07-42c4-b7dc-e352d0cb6a0f',
            ]),
        ]],
    'delete multiple as params' => [
        ['text1.txt', 'text2.txt', 'text3.txt'],
        [
            new Result([
                'DeleteMarker' => true,
                'VersionId' => '213da9a6-3f07-42c4-b7dc-e352d0cb6a0f',
            ]),
            new Result([
                'DeleteMarker' => true,
                'VersionId' => '313da9a6-3f07-42c4-b7dc-e352d0cb6a0f',
            ]),
            new Result([
                'DeleteMarker' => true,
                'VersionId' => '413da9a6-3f07-42c4-b7dc-e352d0cb6a0f',
            ]),
        ]],
]);

it('should permanently delete a specific version of an object', function ($params, $result) {
    $adapter = mockAdapter($result);

    expect($adapter->delete($params))->toBeTrue();
})->with([
    'delete single' => [
        ['213da9a6-3f07-42c4-b7dc-e352d0cb6a0f' => 'text.txt'],
        new Result([
            'DeleteMarker' => false,
            'VersionId' => '213da9a6-3f07-42c4-b7dc-e352d0cb6a0f',
        ])],
    'delete multiple' => [
        [
            '213da9a6-3f07-42c4-b7dc-e352d0cb6a0f' => 'text1.txt',
            '313da9a6-3f07-42c4-b7dc-e352d0cb6a0f' => 'text2.txt',
        ],
        [
            new Result([
                'DeleteMarker' => false,
                'VersionId' => '213da9a6-3f07-42c4-b7dc-e352d0cb6a0f',
            ]),
            new Result([
                'DeleteMarker' => false,
                'VersionId' => '313da9a6-3f07-42c4-b7dc-e352d0cb6a0f',
            ]),
        ]],
]);

function mockAdapter(array|Closure|Result $result)
{
    $config = [
        'bucket' => $_ENV['AWS_BUCKET'] = 'testbucket',
        'region' => $_ENV['AWS_DEFAULT_REGION'] = 'eu-west-1',
        'url' => $_ENV['AWS_URL'] = 'http://minio:9000',
        'endpoint' => 'http://minio:9000',
        'use_path_style_endpoint' => true,
        'throw' => true,
        'version' => 'latest',
        'credentials' => [
            'key' => $_ENV['AWS_ACCESS_KEY_ID'] = 'sail',
            'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] = 'password',
        ],
        'root' => $_ENV['AWS_ROOT'] = 'test',
    ];

    $mock = new MockHandler();

    if (is_array($result)) {
        foreach ($result as $value) {
            $mock->append($value);
        }
    } else {
        $mock->append($result);
    }

    $client = new S3Client($config + ['handler' => $mock]);

    $visibility = new AwsS3PortableVisibilityConverter(Visibility::PUBLIC);

    $s3Adapter = new S3Adapter($client, $config['bucket'], $config['root'], $visibility);

    return new AwsS3V3PlusAdapter(
        new Flysystem($s3Adapter),
        $s3Adapter,
        $config,
        $client
    );
}
