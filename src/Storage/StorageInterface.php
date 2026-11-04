<?php

declare(strict_types=1);

namespace Elviweb\R2Storage\Storage;

/**
 * Where an app keeps its files. The same calls work on local disk (development) and on R2
 * (production), so switching is a setting, not a code change.
 */
interface StorageInterface
{
    /** Copy a file into the storage under $key (replacing what was there). */
    public function store(string $sourcePath, string $key, ?StoreOptions $options = null): StoredFile;

    /** Delete; deleting a missing file is not an error. */
    public function delete(string $key): void;

    public function exists(string $key): bool;

    /**
     * A link to the file: the permanent public link when the storage is public, otherwise a
     * temporary signed link (R2) valid for $expiresIn seconds.
     */
    public function url(string $key, int $expiresIn = 300): string;

    /** "local" or "r2" */
    public function driver(): string;
}
