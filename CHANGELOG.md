# Changelog

## 0.1.2 (unreleased)

- Endpoint with an unparsable port no longer produces a wrong `Host` header.
- Retry pauses are always whole microseconds; retry loop simplified.
- Psalm level 1 added to `composer qa`; GitHub Actions run it, and the tests (with MinIO) on
  PHP 8.1 to 8.5, including the oldest dependencies allowed (Symfony 6.4, PHPUnit 10.5).

## 0.1.1

- MIT license.

## 0.1.0

- R2 client (S3 API, Signature V4 without the AWS SDK): upload (multipart above 64 MiB),
  upload a string, delete, exists, presigned GET and PUT links, retries.
- `StorageInterface` with `LocalStorage` and `R2Storage` (public or private), `StorageFactory`.
- Symfony bundle: named storages, driver from an env variable, autowiring by name.
- Based on choir's `MediaStorageService`.
