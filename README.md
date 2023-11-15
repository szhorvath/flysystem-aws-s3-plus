# Laravel AWS S3 filesystem adapter plus

AWS S3 filesystem adapter for Flysystem extended with S3 bucket object versioning

# Documentation and install instructions

### Disk Configuration

The `flysystem-aws-s3-plus` package requires that you change the disk driver configuration in your `config/filesystems.php` file. For every versioned S3 bucket disk, change the driver from `s3` to `s3-plus`.

```php
<?php

return [
    ...
    'disks' => [
    ...
        's3' => [
            'driver' => 's3-plus',
            ...
        ],
      ],
    ...
```

### Usage

#### Get S3 object versions
```php
Storage::disk('s3')->versions("path/to/file.text");
```

##### Response
```php
Illuminate\Support\Collection {
  [
    [
      "hash" => "27a03c63edc43a5191fb5d2868021a17"
      "key" => "path/to/file.text"
      "version" => "2e3bb2f1-2938-45ed-9efa-33e8346e397e"
      "type" => "file"
      "latest" => true
      "updatedAt" => Carbon\CarbonImmutable
      "size" => 14
    ]
    [
      "hash" => "9310ca6aea85baa1adb30292d379b274"
      "key" => "path/to/file.text"
      "version" => "83170b83-1d4d-405a-b0e7-02ccc2f55a3e"
      "type" => "file"
      "latest" => false
      "updatedAt" => Carbon\CarbonImmutable
      "size" => 12
    ]
  ]
}
```

#### Generate a temporary url for a specific version of the object
```php
Storage::disk('s3')->temporaryUrl(
    path: 'path/to/file.txt',
    expiration: Carbon::now()->addMinutes(1),
    versionId: 'ca3dd4a6-9a92-4368-8ce2-a5df45c63f43'
);
```

##### Response
```php
http://s3/bucket/path/to/file.txt?versionId=ca3dd4a6-9a92-4368-8ce2-a5df45c63f43&X-Amz-Content-Sha256=UNSIGNED-PAYLOAD&X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=sail%2F20231114%2Feu-west-1%2Fs3%2Faws4_request&X-Amz-Date=20231114T170430Z&X-Amz-SignedHeaders=host&X-Amz-Expires=60&X-Amz-Signature=f59d8e667cee7ac9ed5bc1fcfcd4cd02dd742fb9e4dd3f034186ec22dd699647
```

#### Get the content of a specific version
```php
Storage::disk('s3')->get(
    path: 'path/to/file.txt',
    versionId: 'b51e44fe-d47e-43dd-be6e-d07a4dd823d4'
);
```

#### Soft delete an object(s) or permanently delete a specific version(s) of an object
If versioning is enabled, you cannot permanently delete an object with a simple DELETE request that doesn't specify a version ID. Amazon S3 instead inserts a delete marker in the bucket, which becomes the object's current version with a new ID.
To delete versioned objects permanently, you must use provide the versionId.

For more information: [Deleting object versions from a versioning-enabled bucket](https://docs.aws.amazon.com/AmazonS3/latest/userguide/DeletingObjectVersions.html)

```php
//Multiple paths as arguments
Storage::disk('s3')->delete('path/to/file1.txt', 'path/to/file2.txt', ...);

// Multiple paths as array
Storage::disk('s3')->delete(['path/to/file1.txt', 'path/to/file2.txt', ...]);

// Delete specific version(s) permanently
Storage::disk('s3')->delete([
    'f8ffee13-9a0f-4031-85e7-f92f253ec42e' => 'path/to/file1.txt',  // versionId => path
    'b51e44fe-d47e-43dd-be6e-d07a4dd823d4' => 'path/to/file2.txt',
    ...
]);
```

#### Restoring previous versions
```php
// Deleting the Delete marker
Storage::disk('s3')->delete(['f8ffee13-9a0f-4031-85e7-f92f253ec42e' => 'path/to/file1.txt']);

// Copy a previous version of the object into the same bucket.
Storage::disk('s3')->restore('path/to/file1.txt', 'f8ffee13-9a0f-4031-85e7-f92f253ec42e');
```
[Restoring previous versions](https://docs.aws.amazon.com/AmazonS3/latest/userguide/RestoringPreviousVersions.html)

## Change log

Please see the [changelog][3] for more information on what has changed recently.

## Contributing

Contributions are welcome and will be fully credited.

Contributions are accepted via Pull Requests on [Github][4].

## Pull Requests

- **Document any change in behaviour** - Make sure the `readme.md` and any other relevant documentation are kept up-to-date.

- **Consider our release cycle** - We try to follow [SemVer v2.0.0][5]. Randomly breaking public APIs is not an option.

- **One pull request per feature** - If you want to do more than one thing, send multiple pull requests.

## Security

If you discover any security related issues, please email z.sandor.horvath@gmail.com email instead of using the issue tracker.

## License

license. Please see the [license file][6] for more information.

[3]:    changelog.md
[4]:    https://github.com/szhorvath/flysystem-aws-s3-plus
[5]:    http://semver.org/
[6]:    license.md
