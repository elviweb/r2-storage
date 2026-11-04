# Changelog

## 0.1.1 (unreleased)

- MIT license.

## 0.1.0

- R2 client (S3 API, Signature V4 without the AWS SDK): upload (multipart above 64 MiB),
  upload a string, delete, exists, presigned GET and PUT links, retries.
- `StorageInterface` with `LocalStorage` and `R2Storage` (public or private), `StorageFactory`.
- Symfony bundle: named storages, driver from an env variable, autowiring by name.
- Based on choir's `MediaStorageService`.
