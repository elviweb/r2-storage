<?php

declare(strict_types=1);

namespace Elviweb\R2Storage\Tests\Integration;

use Elviweb\R2Storage\R2Client;
use Elviweb\R2Storage\R2Config;
use Elviweb\R2Storage\Signer;
use Elviweb\R2Storage\Storage\R2Storage;
use Elviweb\R2Storage\Storage\StoreOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Against a real S3-compatible server (VersityGW from compose.yaml, or an R2 test bucket):
 *   docker compose up -d
 *   R2_TEST_ENDPOINT=http://127.0.0.1:19000 R2_TEST_ACCESS_KEY_ID=r2-storage-test \
 *   R2_TEST_SECRET_ACCESS_KEY=r2-storage-test-secret R2_TEST_REGION=us-east-1 vendor/bin/phpunit --testsuite integration
 * Skipped when R2_TEST_ENDPOINT isn't set.
 */
final class S3CompatibleServerTest extends TestCase
{
    private HttpClientInterface $http;
    private R2Config $config;
    private string $bucket = '';
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $endpoint = getenv('R2_TEST_ENDPOINT');
        if (!\is_string($endpoint) || '' === $endpoint) {
            self::markTestSkipped('R2_TEST_ENDPOINT is not set (see the class comment).');
        }
        $this->http = HttpClient::create();
        $this->config = new R2Config($endpoint, (string) getenv('R2_TEST_ACCESS_KEY_ID'), (string) getenv('R2_TEST_SECRET_ACCESS_KEY'), (string) (getenv('R2_TEST_REGION') ?: 'auto'));
        $this->bucket = (string) (getenv('R2_TEST_BUCKET') ?: 'r2-storage-test-'.bin2hex(random_bytes(3)));
        if (!getenv('R2_TEST_BUCKET')) {
            $this->bucketRequest('PUT');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        if ('' !== $this->bucket && !getenv('R2_TEST_BUCKET')) {
            $this->bucketRequest('DELETE');
        }
    }

    /** Create or delete the test bucket (not part of the library: R2 buckets are made in the dashboard). */
    private function bucketRequest(string $method): void
    {
        $signer = new Signer($this->config->accessKeyId, $this->config->secretAccessKey, $this->config->region);
        $hash = hash('sha256', '');
        $headers = $signer->signRequest($method, '/'.$this->bucket, [], ['host' => $this->config->host(), 'x-amz-content-sha256' => $hash], $hash, new \DateTimeImmutable());
        unset($headers['host']);
        $status = $this->http->request($method, $this->config->endpoint.'/'.$this->bucket, ['headers' => $headers])->getStatusCode();
        self::assertLessThan(300, $status, "{$method} bucket");
    }

    private function file(int $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'r2i');
        $this->files[] = $path;
        $handle = fopen($path, 'w');
        \assert(false !== $handle);
        for ($written = 0; $written < $bytes; $written += 65536) {
            fwrite($handle, random_bytes(max(1, min(65536, $bytes - $written))));
        }
        fclose($handle);

        return $path;
    }

    public function testFullCycle(): void
    {
        $client = new R2Client($this->http, $this->config);
        $storage = new R2Storage($client, $this->bucket);

        // upload, check, presigned download (content and headers come back), delete
        $src = $this->file(70_000);
        $stored = $storage->store($src, 'projects/photo-1.bin', new StoreOptions('image/webp', 'public, max-age=31536000, immutable'));
        self::assertSame(70_000, $stored->size);
        self::assertTrue($storage->exists('projects/photo-1.bin'));

        $download = $this->http->request('GET', $storage->url('projects/photo-1.bin', 120));
        self::assertSame(200, $download->getStatusCode());
        self::assertSame((string) file_get_contents($src), $download->getContent());
        self::assertSame('image/webp', $download->getHeaders()['content-type'][0]);
        self::assertSame('public, max-age=31536000, immutable', $download->getHeaders()['cache-control'][0]);

        $attachment = $this->http->request('GET', $client->presignedGetUrl($this->bucket, 'projects/photo-1.bin', 120, 'attachment; filename="photo.webp"'));
        self::assertSame('attachment; filename="photo.webp"', $attachment->getHeaders()['content-disposition'][0]);

        $storage->delete('projects/photo-1.bin');
        self::assertFalse($storage->exists('projects/photo-1.bin'));
        $storage->delete('projects/photo-1.bin'); // already gone: fine

        // a tampered link is refused
        $tampered = preg_replace('/X-Amz-Signature=[0-9a-f]{4}/', 'X-Amz-Signature=0000', $client->presignedGetUrl($this->bucket, 'projects/photo-1.bin'));
        self::assertSame(403, $this->http->request('GET', (string) $tampered)->getStatusCode());
    }

    public function testDirectUploadWithAPresignedPutLink(): void
    {
        $client = new R2Client($this->http, $this->config);
        $status = $this->http->request('PUT', $client->presignedPutUrl($this->bucket, 'inbox/from-phone.txt', 120), ['body' => 'sent by the phone'])->getStatusCode();
        self::assertSame(200, $status);
        self::assertTrue($client->exists($this->bucket, 'inbox/from-phone.txt'));
        $client->delete($this->bucket, 'inbox/from-phone.txt');
    }

    public function testLargeFileInParts(): void
    {
        // 12 MiB in 5 MiB parts (the S3 minimum) with a 6 MiB threshold
        $client = new R2Client($this->http, $this->config, null, 2, 6 * 1024 * 1024, 5 * 1024 * 1024);
        $src = $this->file(12 * 1024 * 1024);
        $client->putFile($this->bucket, 'big/recording.bin', $src, 'audio/mpeg');

        $download = $this->http->request('GET', $client->presignedGetUrl($this->bucket, 'big/recording.bin', 120));
        self::assertSame(hash_file('sha256', $src), hash('sha256', $download->getContent()));
        $client->delete($this->bucket, 'big/recording.bin');
    }
}
