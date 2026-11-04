# elviweb/r2-storage

File storage on **Cloudflare R2** (its S3 API) for PHP and Symfony, without the AWS SDK.
The same interface stores files on local disk in development, so switching is a setting.

- Signed requests (AWS Signature V4, checked against AWS's published test values).
- Upload (multipart for large files, retries on network errors, 5xx and 429), delete, exists.
- Public storages (permanent links on the bucket's custom domain) and private ones (presigned links that expire).
- Presigned **upload** links, so a browser or phone can send a file straight to R2.
- Safe keys: no `..`, no leading `/`, no hidden files; a key can't leave a local storage directory.
- PHP ≥ 8.1, Symfony 6.4 / 7 / 8 (the bundle is optional).

## Install

From [Packagist](https://packagist.org/packages/elviweb/r2-storage):

```sh
composer require elviweb/r2-storage symfony/http-client
```

(To work on the library next to a project, add `{ "type": "path", "url": "../r2-storage" }` to that project's `repositories` for a while.)

## Symfony

`config/packages/elviweb_r2_storage.yaml`:

```yaml
elviweb_r2_storage:
    r2:
        endpoint: '%env(R2_ENDPOINT)%'                 # https://<account-id>.r2.cloudflarestorage.com
        access_key_id: '%env(R2_ACCESS_KEY_ID)%'
        secret_access_key: '%env(R2_SECRET_ACCESS_KEY)%'
    storages:
        public:                                         # first one = the default StorageInterface
            driver: '%env(STORAGE_DRIVER)%'             # local | r2
            bucket: '%env(R2_PUBLIC_BUCKET)%'
            public_url: '%env(R2_PUBLIC_URL)%'          # e.g. https://media.example.com
            directory: '%kernel.project_dir%/public/uploads'   # local driver
            local_url: /uploads
        private:
            driver: '%env(STORAGE_DRIVER)%'
            bucket: '%env(R2_PRIVATE_BUCKET)%'          # no public_url: links are presigned
            directory: '%kernel.project_dir%/var/private-uploads'
```

`.env` (committed, no secrets):

```dotenv
STORAGE_DRIVER=local
R2_ENDPOINT=
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_PUBLIC_BUCKET=
R2_PUBLIC_URL=
```

Real keys go in `.env.local` or `bin/console secrets:set` on the server, never in Git.
Use an R2 API token limited to the project's buckets ("Object Read & Write").

```php
use Elviweb\R2Storage\Storage\StorageInterface;
use Elviweb\R2Storage\Storage\StoreOptions;

public function __construct(private StorageInterface $publicStorage, private StorageInterface $privateStorage) {}

$file = $this->publicStorage->store($uploaded->getPathname(), 'projects/'.$name, new StoreOptions(cacheControl: 'public, max-age=31536000, immutable'));
$file->publicUrl;                                   // https://media.example.com/projects/…
$this->privateStorage->url('scores/a.pdf', 300);    // presigned, 5 minutes
$this->publicStorage->delete('projects/'.$name);
```

Direct uploads (the app hands out a link, the file never passes through the app server):
inject `Elviweb\R2Storage\R2Client` and call `presignedPutUrl($bucket, $key, 300)`. The bucket
needs a CORS rule allowing `PUT` from the site's origin.

## Without Symfony

```php
$client = new R2Client(HttpClient::create(), R2Config::forAccount($accountId, $keyId, $secret));
$storage = new R2Storage($client, 'my-bucket', 'https://media.example.com');
```

## Development

```sh
composer install
composer qa                 # php-cs-fixer, PHPStan (max), unit tests
docker compose up -d        # MinIO, an S3-compatible server, for the integration tests
R2_TEST_ENDPOINT=http://127.0.0.1:19000 R2_TEST_ACCESS_KEY_ID=r2-storage-test \
R2_TEST_SECRET_ACCESS_KEY=r2-storage-test-secret R2_TEST_REGION=us-east-1 \
vendor/bin/phpunit --testsuite integration
```

To run the integration tests against a real R2 test bucket instead: `R2_TEST_ENDPOINT` = the
account endpoint, `R2_TEST_REGION=auto`, `R2_TEST_BUCKET` = an existing, empty test bucket.

Releases follow semantic versioning (Git tags `v0.1.0`…); see `CHANGELOG.md`. Packagist picks up new tags through the GitHub hook.

## License

MIT, see `LICENSE`.
