<?php

declare(strict_types=1);

namespace Elviweb\R2Storage\Storage;

use Elviweb\R2Storage\R2Client;

/**
 * Files in an R2 bucket. Public when $publicUrl is set (the bucket's custom domain or r2.dev
 * address: links are permanent), private otherwise (links are presigned and expire).
 */
final class R2Storage implements StorageInterface
{
    private readonly ?string $publicUrl;

    public function __construct(
        private readonly R2Client $client,
        private readonly string $bucket,
        ?string $publicUrl = null,
    ) {
        $this->publicUrl = null !== $publicUrl && '' !== trim($publicUrl) ? rtrim(trim($publicUrl), '/') : null;
    }

    public function store(string $sourcePath, string $key, ?StoreOptions $options = null): StoredFile
    {
        Key::assertValid($key);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('"%s" is not a readable file.', $sourcePath));
        }
        $contentType = $options->contentType ?? ContentType::detect($sourcePath);
        $headers = array_filter([
            'cache-control' => $options?->cacheControl,
            'content-disposition' => $options?->contentDisposition,
        ], static fn (?string $v): bool => null !== $v && '' !== $v);
        $this->client->putFile($this->bucket, $key, $sourcePath, $contentType, $headers);

        return new StoredFile('r2', $key, (int) filesize($sourcePath), $contentType, $this->bucket, null !== $this->publicUrl ? $this->publicUrl.'/'.Key::encode($key) : null);
    }

    public function delete(string $key): void
    {
        Key::assertValid($key);
        $this->client->delete($this->bucket, $key);
    }

    public function exists(string $key): bool
    {
        Key::assertValid($key);

        return $this->client->exists($this->bucket, $key);
    }

    public function url(string $key, int $expiresIn = 300): string
    {
        Key::assertValid($key);

        return null !== $this->publicUrl ? $this->publicUrl.'/'.Key::encode($key) : $this->client->presignedGetUrl($this->bucket, $key, $expiresIn);
    }

    public function driver(): string
    {
        return 'r2';
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function client(): R2Client
    {
        return $this->client;
    }
}
