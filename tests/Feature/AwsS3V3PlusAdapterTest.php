
<?php

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Http;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter as AwsS3PortableVisibilityConverter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Visibility;
use Szhorvath\FlysystemAwsS3Plus\AwsS3V3PlusAdapter;

describe('S3 AWS plus adapter', function () {
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

    it('should read', function () {
        $stream = tmpfile();
        fwrite($stream, 'data');
        rewind($stream);

        $this->client->putObject([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->config['root'].'/text.txt',
            'Body' => $stream,
        ]);

        expect($this->adapter->get('text.txt'))->toBe('data');

        fclose($stream);
    });

    it('should read the latest version when the path called', function () {
        $this->client->putBucketVersioning([
            'Bucket' => $this->config['bucket'],
            'VersioningConfiguration' => [
                'MFADelete' => 'Disabled',
                'Status' => 'Enabled',
            ],
        ]);

        $stream1 = tmpfile();
        fwrite($stream1, 'DataVersion1');
        rewind($stream1);

        $stream2 = tmpfile();
        fwrite($stream2, 'DataVersion2');
        rewind($stream2);

        $this->client->putObject([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->config['root'].'/text.txt',
            'Body' => $stream1,
        ]);

        $this->client->putObject([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->config['root'].'/text.txt',
            'Body' => $stream2,
        ]);

        expect($this->adapter->get('text.txt'))->toBe('DataVersion2');

        fclose($stream1);
        fclose($stream2);
    });
});
// ->skip(
//     fn () => rescue(fn () => Http::timeout(2)->get('http://minio:9000/minio/health/live')->failed(), true, false),
//     'minio is not running'
// );

function createTestBucket(S3Client $client, array $config): void
{
    $params = ['Bucket' => $config['bucket']];

    try {
        $client->headBucket($params);
    } catch (\Throwable $th) {
        $client->createBucket($params);
    }
}
