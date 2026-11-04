<?php

declare(strict_types=1);

namespace Elviweb\R2Storage\Tests\Unit;

use Elviweb\R2Storage\R2Client;
use Elviweb\R2Storage\R2Config;
use Elviweb\R2Storage\Storage\Key;
use Elviweb\R2Storage\Storage\LocalStorage;
use Elviweb\R2Storage\Storage\R2Storage;
use Elviweb\R2Storage\Storage\StorageFactory;
use Elviweb\R2Storage\Storage\StoreOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StorageTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/r2-storage-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    private function source(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($path, $contents);

        return $path;
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'parent' => ['../etc/passwd'];
        yield 'inner parent' => ['a/../../b'];
        yield 'absolute' => ['/abs.jpg'];
        yield 'trailing slash' => ['folder/'];
        yield 'empty segment' => ['a//b'];
        yield 'backslash' => ['a\\b.jpg'];
        yield 'space' => ['my photo.jpg'];
        yield 'empty' => [''];
        yield 'dot' => ['./a.jpg'];
        yield 'hidden file' => ['.htaccess'];
        yield 'hidden file in a folder' => ['uploads/.user.ini'];
    }

    #[DataProvider('invalidKeys')]
    public function testKeysCannotEscapeOrSurprise(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Key::assertValid($key);
    }

    public function testValidKeys(): void
    {
        Key::assertValid('projects/2026/site-v2_1.jpg');
        Key::assertValid('a/b/c.d.e.webp');
        $this->addToAssertionCount(2);
    }

    public function testLocalStorageStoresLinksAndDeletes(): void
    {
        $storage = new LocalStorage($this->dir, '/uploads/');
        $src = $this->source((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true)); // 1×1 PNG
        $file = $storage->store($src, 'projects/logo.png');
        unlink($src);

        self::assertSame('local', $file->driver);
        self::assertSame('image/png', $file->contentType, 'detected from the content');
        self::assertSame('/uploads/projects/logo.png', $file->publicUrl);
        self::assertSame('/uploads/projects/logo.png', $storage->url('projects/logo.png'));
        self::assertFileExists($this->dir.'/projects/logo.png');
        self::assertTrue($storage->exists('projects/logo.png'));

        $storage->delete('projects/logo.png');
        $storage->delete('projects/logo.png'); // already gone: fine
        self::assertFalse($storage->exists('projects/logo.png'));
    }

    public function testLocalStorageRefusesKeysOutsideItsDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LocalStorage($this->dir))->store($this->source('x'), '../outside.txt');
    }

    public function testR2StoragePublicAndPrivateLinks(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $lines = $options['headers'] ?? [];
            $requests[] = [$method, $url, array_map(static fn (mixed $l): string => \is_string($l) ? strtolower($l) : '', \is_array($lines) ? $lines : [])];

            return new MockResponse('', ['http_code' => 200]);
        });
        $client = new R2Client($http, new R2Config('https://acc.r2.cloudflarestorage.com', 'AKID', 'SECRET'));

        $public = new R2Storage($client, 'site-media', 'https://media.example.com/');
        $file = $public->store($this->source('GIF89a'), 'projects/a.gif', new StoreOptions(cacheControl: 'public, max-age=31536000, immutable'));
        self::assertSame('r2', $file->driver);
        self::assertSame('site-media', $file->bucket);
        self::assertSame('image/gif', $file->contentType);
        self::assertSame('https://media.example.com/projects/a.gif', $file->publicUrl);
        self::assertSame('https://media.example.com/projects/a.gif', $public->url('projects/a.gif'));
        self::assertContains('cache-control: public, max-age=31536000, immutable', $requests[0][2]);

        $private = new R2Storage($client, 'site-private');
        self::assertNull($private->store($this->source('x'), 'docs/a.txt')->publicUrl);
        self::assertStringContainsString('X-Amz-Signature=', $private->url('docs/a.txt', 60));
    }

    public function testFactoryPicksTheDriver(): void
    {
        $client = new R2Client(new MockHttpClient(), new R2Config('', '', ''));
        self::assertInstanceOf(LocalStorage::class, StorageFactory::create('local', $client, '', null, $this->dir));
        self::assertInstanceOf(R2Storage::class, StorageFactory::create(' R2 ', $client, 'bucket-1', null));
    }

    public function testFactoryRejectsUnknownDriversAndR2WithoutBucket(): void
    {
        $client = new R2Client(new MockHttpClient(), new R2Config('', '', ''));
        foreach ([['s3', 'b-1'], ['r2', '']] as [$driver, $bucket]) {
            try {
                StorageFactory::create($driver, $client, $bucket);
                self::fail("{$driver} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
