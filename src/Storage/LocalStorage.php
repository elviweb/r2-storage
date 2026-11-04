<?php

declare(strict_types=1);

namespace Elviweb\R2Storage\Storage;

/** Files on local disk, e.g. public/uploads in development. */
final class LocalStorage implements StorageInterface
{
    private readonly string $directory;
    private readonly string $publicUrl;

    /**
     * @param string $directory where files are written (created if needed)
     * @param string $publicUrl how the web server serves that directory, e.g. "/uploads"
     */
    public function __construct(string $directory, string $publicUrl = '/uploads')
    {
        if ('' === trim($directory)) {
            throw new \InvalidArgumentException('A local storage needs a directory.');
        }
        $this->directory = rtrim($directory, '/');
        $this->publicUrl = rtrim($publicUrl, '/');
    }

    public function store(string $sourcePath, string $key, ?StoreOptions $options = null): StoredFile
    {
        Key::assertValid($key);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('"%s" is not a readable file.', $sourcePath));
        }
        $target = $this->path($key);
        $dir = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create "%s".', $dir));
        }
        if (!copy($sourcePath, $target)) {
            throw new \RuntimeException(sprintf('Could not write "%s".', $target));
        }

        return new StoredFile('local', $key, (int) filesize($target), $options->contentType ?? ContentType::detect($target), null, $this->url($key));
    }

    public function delete(string $key): void
    {
        Key::assertValid($key);
        $path = $this->path($key);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException(sprintf('Could not delete "%s".', $path));
        }
    }

    public function exists(string $key): bool
    {
        Key::assertValid($key);

        return is_file($this->path($key));
    }

    public function url(string $key, int $expiresIn = 300): string
    {
        Key::assertValid($key);

        return $this->publicUrl.'/'.Key::encode($key);
    }

    public function driver(): string
    {
        return 'local';
    }

    /** The file's path on disk. */
    public function path(string $key): string
    {
        Key::assertValid($key);

        return $this->directory.'/'.$key;
    }
}
