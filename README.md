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
