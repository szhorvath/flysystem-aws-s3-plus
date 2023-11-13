
<?php

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter as AwsS3PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Visibility;
use Szhorvath\FlysystemAwsS3Plus\AwsS3V3PlusAdapter;

it('should read', function () {
    $stream = Utils::streamFor('data');

    $adapter = mockAdapter(new Result(['Body' => $stream]));

    expect($adapter->get('text.txt'))->toBe('data');
});

it('should read return a file with version id provided', function () {
    $stream = Utils::streamFor('data');

    $adapter = mockAdapter(new Result(['Body' => $stream]));

    expect($adapter->get('text.txt', 'version-id-string'))->toBe('data');
});

function mockAdapter(Result $result)
{
    $config = [
        'bucket' => $_ENV['AWS_BUCKET'] = 'test',
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

    $mock->append($result);

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
