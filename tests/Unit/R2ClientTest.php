<?php

declare(strict_types=1);

namespace Elviweb\R2Storage\Tests\Unit;

use Elviweb\R2Storage\R2Client;
use Elviweb\R2Storage\R2Config;
use Elviweb\R2Storage\R2Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class R2ClientTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ('' !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @param list<MockResponse>                                                                     $responses
     * @param list<array{method: string, url: string, headers: array<string, string>, body: string}> $requests
     */
    private function client(array $responses, array &$requests, int $multipartThreshold = R2Client::DEFAULT_MULTIPART_THRESHOLD, int $partSize = R2Client::DEFAULT_PART_SIZE): R2Client
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$requests): MockResponse {
            $headers = [];
            $lines = $options['headers'] ?? [];
            foreach (\is_array($lines) ? $lines : [] as $line) {
                if (\is_string($line) && str_contains($line, ': ')) {
                    [$name, $value] = explode(': ', $line, 2);
                    $headers[strtolower($name)] = $value;
                }
            }
            $body = $options['body'] ?? '';
            if (\is_resource($body)) {
                $body = (string) stream_get_contents($body);
            } elseif ($body instanceof \Closure) {
                $chunks = '';
                while (\is_string($chunk = $body(65536)) && '' !== $chunk) {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }
            $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => \is_string($body) ? $body : ''];
            $response = array_shift($responses);
            self::assertNotNull($response, "unexpected request {$method} {$url}");

            return $response;
        });

        return new R2Client($http, new R2Config('https://acc123.r2.cloudflarestorage.com', 'AKID', 'SECRET'), static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-04T10:00:00Z'), 2, $multipartThreshold, $partSize);
    }

    private function tempFile(string $contents): string
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'r2t');
        file_put_contents($this->file, $contents);

        return $this->file;
    }

    public function testPutFileSendsASignedPathStyleRequest(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('', ['http_code' => 200])], $requests);
        $client->putFile('my-bucket', 'projects/photo 1.jpg', $this->tempFile('jpeg bytes'), 'image/jpeg', ['Cache-Control' => 'public, max-age=60']);

        self::assertCount(1, $requests);
        [$r] = $requests;
        self::assertSame('PUT', $r['method']);
        self::assertSame('https://acc123.r2.cloudflarestorage.com/my-bucket/projects/photo%201.jpg', $r['url']);
        self::assertSame(hash('sha256', 'jpeg bytes'), $r['headers']['x-amz-content-sha256']);
        self::assertSame('image/jpeg', $r['headers']['content-type']);
        self::assertSame('public, max-age=60', $r['headers']['cache-control']);
        self::assertSame('20261104T100000Z', $r['headers']['x-amz-date']);
        self::assertSame('jpeg bytes', $r['body']);
        self::assertMatchesRegularExpression('#^AWS4-HMAC-SHA256 Credential=AKID/20261104/auto/s3/aws4_request, SignedHeaders=cache-control;content-length;content-type;host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $r['headers']['authorization']);
    }

    public function testDeleteToleratesMissingObjectsAndExistsReadsTheStatus(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 200]), new MockResponse('', ['http_code' => 404])], $requests);
        $client->delete('my-bucket', 'gone.jpg');
        self::assertTrue($client->exists('my-bucket', 'here.jpg'));
        self::assertFalse($client->exists('my-bucket', 'gone.jpg'));
        self::assertSame(['DELETE', 'HEAD', 'HEAD'], array_column($requests, 'method'));
    }

    public function testRetriesServerErrorsAndNetworkFailures(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('', ['error' => 'connection reset']),
            new MockResponse('<Error><Code>InternalError</Code></Error>', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 200]),
        ], $requests);
        $client->putContents('my-bucket', 'a.txt', 'hello', 'text/plain');
        self::assertCount(3, $requests);
        self::assertSame(['hello', 'hello', 'hello'], array_column($requests, 'body'), 'the body is sent again on each attempt');
    }

    public function testErrorsCarryR2sCodeAndStatus(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', ['http_code' => 403])], $requests);
        try {
            $client->putContents('my-bucket', 'a.txt', 'x');
            self::fail('expected an R2Exception');
        } catch (R2Exception $e) {
            self::assertSame(403, $e->status);
            self::assertSame('AccessDenied', $e->errorCode);
            self::assertStringContainsString('AccessDenied: Access Denied', $e->getMessage());
        }
    }

    public function testLargeFilesAreUploadedInParts(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('<InitiateMultipartUploadResult><UploadId>up-1</UploadId></InitiateMultipartUploadResult>'),
            new MockResponse('', ['response_headers' => ['ETag' => '"e1"']]),
            new MockResponse('', ['response_headers' => ['ETag' => '"e2"']]),
            new MockResponse('', ['response_headers' => ['ETag' => '"e3"']]),
            new MockResponse('<CompleteMultipartUploadResult><Key>big.bin</Key></CompleteMultipartUploadResult>'),
        ], $requests, 10, 4);
        $client->putFile('my-bucket', 'big.bin', $this->tempFile('0123456789AB'), 'application/octet-stream');

        self::assertSame(['POST', 'PUT', 'PUT', 'PUT', 'POST'], array_column($requests, 'method'));
        self::assertStringEndsWith('/my-bucket/big.bin?uploads=', $requests[0]['url']);
        self::assertStringEndsWith('?partNumber=2&uploadId=up-1', $requests[2]['url']);
        self::assertSame(['0123', '4567', '89AB'], [$requests[1]['body'], $requests[2]['body'], $requests[3]['body']]);
        self::assertStringContainsString('<Part><PartNumber>3</PartNumber><ETag>"e3"</ETag></Part>', $requests[4]['body']);
    }

    public function testAFailedMultipartUploadIsAborted(): void
    {
        $requests = [];
        $client = $this->client([
            new MockResponse('<InitiateMultipartUploadResult><UploadId>up-2</UploadId></InitiateMultipartUploadResult>'),
            new MockResponse('<Error><Code>AccessDenied</Code><Message>no</Message></Error>', ['http_code' => 403]),
            new MockResponse('', ['http_code' => 204]),
        ], $requests, 10, 4);
        try {
            $client->putFile('my-bucket', 'big.bin', $this->tempFile('0123456789AB'));
            self::fail('expected an R2Exception');
        } catch (R2Exception $e) {
            self::assertSame('AccessDenied', $e->errorCode);
        }
        self::assertSame('DELETE', $requests[2]['method']);
        self::assertStringEndsWith('?uploadId=up-2', $requests[2]['url']);
    }

    public function testPresignedLinks(): void
    {
        $requests = [];
        $client = $this->client([], $requests);
        $get = $client->presignedGetUrl('my-bucket', 'docs/report.pdf', 600, 'attachment; filename="report.pdf"');
        self::assertStringStartsWith('https://acc123.r2.cloudflarestorage.com/my-bucket/docs/report.pdf?', $get);
        self::assertStringContainsString('X-Amz-Expires=600', $get);
        self::assertStringContainsString('response-content-disposition=attachment%3B%20filename%3D%22report.pdf%22', $get);
        self::assertMatchesRegularExpression('/X-Amz-Signature=[0-9a-f]{64}/', $get);
        self::assertStringContainsString('X-Amz-SignedHeaders=host', $client->presignedPutUrl('my-bucket', 'in/upload.jpg'));
        self::assertCount(0, $requests, 'presigning makes no request');
    }

    public function testMissingSettingsAreReportedClearly(): void
    {
        $client = new R2Client(new MockHttpClient(), new R2Config('', '', ''));
        $this->expectException(R2Exception::class);
        $this->expectExceptionMessage('R2 is not configured: missing endpoint, access key id, secret access key.');
        $client->exists('my-bucket', 'a.jpg');
    }

    public function testRejectsInvalidBucketNames(): void
    {
        $requests = [];
        $this->expectException(R2Exception::class);
        $this->client([], $requests)->delete('Not_A_Bucket', 'a.jpg');
    }

    public function testNetworkFailureAfterRetriesIsAnR2Exception(): void
    {
        $requests = [];
        $client = $this->client([new MockResponse('', ['error' => 'down']), new MockResponse('', ['error' => 'down']), new MockResponse('', ['error' => 'down'])], $requests);
        try {
            $client->delete('my-bucket', 'a.jpg');
            self::fail('expected an R2Exception');
        } catch (R2Exception $e) {
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
        self::assertCount(3, $requests);
    }
}
