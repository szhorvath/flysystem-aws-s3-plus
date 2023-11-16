# Changelog

All notable changes to `FlysystemAwsS3Plus` will be documented in this file.

## Version 0.0.1

### Added
- Prototype adapter implementation
- Added Service provider
- Extend storage facade with S3 plus adapter implementation

## Version 1.0.0

### Added
- Added ability to list object versions
- Added ability to generate temporary url for a specific object version
- Added the ability to delete specific object versions or delete object delete markers
- Added the ability to restore any object versions and keep version history

## Version 1.0.1

### Added
- Added more tests for list versions

### Modified
- Rename version id in version list from version to id