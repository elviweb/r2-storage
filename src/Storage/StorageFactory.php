<?php

declare(strict_types=1);

namespace Elviweb\R2Storage\Storage;

use Elviweb\R2Storage\R2Client;

/**
 * Builds a storage from settings at runtime, so the driver can come from an environment
 * variable (local in development, r2 in production) without changing code or config files.
 */
final class StorageFactory
{
    public static function create(
        string $driver,
        R2Client $client,
        string $bucket = '',
        ?string $publicUrl = null,
        string $directory = '',
        string $localUrl = '/uploads',
    ): StorageInterface {
        return match (strtolower(trim($driver))) {
            'local' => new LocalStorage($directory, $localUrl),
            'r2' => '' !== trim($bucket) ? new R2Storage($client, trim($bucket), $publicUrl) : throw new \InvalidArgumentException('The r2 storage driver needs a bucket.'),
            default => throw new \InvalidArgumentException(sprintf('Unknown storage driver "%s" (use "local" or "r2").', $driver)),
        };
    }
}
