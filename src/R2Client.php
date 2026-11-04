<?php

declare(strict_types=1);

namespace Elviweb\R2Storage;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * R2 through its S3 API, signed with AWS Signature V4 (no AWS SDK): upload (multipart for
 * large files), delete, existence check, and presigned links to download or upload directly.
 * Buckets are addressed path-style: <endpoint>/<bucket>/<key>.
 */
final class R2Client
{
    /** Files above this size are sent in parts (S3 parts are 5 MiB minimum, except the last). */
    public const DEFAULT_MULTIPART_THRESHOLD = 64 * 1024 * 1024;
    public const DEFAULT_PART_SIZE = 16 * 1024 * 1024;

    private readonly Signer $signer;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly R2Config $config,
        ?\Closure $clock = null,
        private readonly int $retries = 2,
        private readonly int $multipartThreshold = self::DEFAULT_MULTIPART_THRESHOLD,
        private readonly int $partSize = self::DEFAULT_PART_SIZE,
    ) {
        $this->signer = new Signer($config->accessKeyId, $config->secretAccessKey, $config->region);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Upload a file. Extra headers: cache-control, content-disposition, x-amz-meta-*.
     *
     * @param array<string, string> $headers
     */
    public function putFile(string $bucket, string $key, string $sourcePath, string $contentType = 'application/octet-stream', array $headers = []): void
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new R2Exception(sprintf('"%s" is not a readable file.', $sourcePath));
        }
        $size = (int) filesize($sourcePath);
        if ($size > $this->multipartThreshold) {
            $this->putMultipart($bucket, $key, $sourcePath, $size, $contentType, $headers);

            return;
        }
        $hash = (string) hash_file('sha256', $sourcePath);
        $this->send('PUT', $bucket, $key, [], ['content-type' => $contentType, 'content-length' => (string) $size] + self::lower($headers), $hash, static function () use ($sourcePath) {
            $handle = fopen($sourcePath, 'r');
            if (false === $handle) {
                throw new R2Exception(sprintf('"%s" could not be opened.', $sourcePath));
            }

            return $handle;
        });
    }

    /**
     * Upload a string (small generated files, thumbnails...).
     *
     * @param array<string, string> $headers
     */
    public function putContents(string $bucket, string $key, string $contents, string $contentType = 'application/octet-stream', array $headers = []): void
    {
        $this->send('PUT', $bucket, $key, [], ['content-type' => $contentType, 'content-length' => (string) \strlen($contents)] + self::lower($headers), hash('sha256', $contents), static fn (): string => $contents);
    }

    /** Delete an object; deleting one that isn't there is not an error. */
    public function delete(string $bucket, string $key): void
    {
        $this->send('DELETE', $bucket, $key, [], [], hash('sha256', ''), null, [404]);
    }

    public function exists(string $bucket, string $key): bool
    {
        return 200 === $this->send('HEAD', $bucket, $key, [], [], hash('sha256', ''), null, [404])->getStatusCode();
    }

    /** A link anyone can open for $expiresIn seconds, e.g. a private file's download. */
    public function presignedGetUrl(string $bucket, string $key, int $expiresIn = 300, ?string $contentDisposition = null): string
    {
        $extra = null !== $contentDisposition ? ['response-content-disposition' => $contentDisposition] : [];

        return $this->presign('GET', $bucket, $key, $expiresIn, $extra);
    }

    /** A link a browser or phone can PUT a file to for $expiresIn seconds (direct upload, no app server in between). */
    public function presignedPutUrl(string $bucket, string $key, int $expiresIn = 300): string
    {
        return $this->presign('PUT', $bucket, $key, $expiresIn);
    }

    /** The object's address (path-style), e.g. for logs. */
    public function objectUrl(string $bucket, string $key): string
    {
        return $this->config->endpoint.self::path($bucket, $key);
    }

    /** "/bucket/key" with each segment encoded, as sent and as signed. */
    public static function path(string $bucket, string $key): string
    {
        $segments = array_map('rawurlencode', explode('/', trim($key, '/')));

        return '/'.rawurlencode($bucket).('' !== $key ? '/'.implode('/', $segments) : '');
    }

    /** @param array<string, string> $extraQuery */
    private function presign(string $method, string $bucket, string $key, int $expiresIn, array $extraQuery = []): string
    {
        $this->config->assertComplete();
        self::assertKey($bucket, $key);
        $path = self::path($bucket, $key);

        return $this->config->endpoint.$path.'?'.$this->signer->presignQuery($method, $this->config->host(), $path, $expiresIn, ($this->clock)(), $extraQuery);
    }

    /**
     * @param array<string, string> $headers
     */
    private function putMultipart(string $bucket, string $key, string $sourcePath, int $size, string $contentType, array $headers): void
    {
        $start = $this->send('POST', $bucket, $key, ['uploads' => ''], ['content-type' => $contentType] + self::lower($headers), hash('sha256', ''));
        $uploadId = (string) (self::xml($start->getContent())->UploadId ?? '');
        if ('' === $uploadId) {
            throw new R2Exception('R2 did not return an upload id for the multipart upload.');
        }

        try {
            $etags = [];
            $partNumber = 0;
            for ($offset = 0; $offset < $size; $offset += $this->partSize) {
                ++$partNumber;
                $length = max(1, min($this->partSize, $size - $offset));
                $chunk = (string) file_get_contents($sourcePath, false, null, $offset, $length);
                $response = $this->send('PUT', $bucket, $key, ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId], ['content-length' => (string) \strlen($chunk)], hash('sha256', $chunk), static fn (): string => $chunk);
                $etags[$partNumber] = $response->getHeaders()['etag'][0] ?? '';
            }

            $xml = '<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
            foreach ($etags as $number => $etag) {
                $xml .= sprintf('<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>', $number, htmlspecialchars($etag, \ENT_XML1));
            }
            $xml .= '</CompleteMultipartUpload>';
            $done = $this->send('POST', $bucket, $key, ['uploadId' => $uploadId], ['content-type' => 'application/xml', 'content-length' => (string) \strlen($xml)], hash('sha256', $xml), static fn (): string => $xml);
            // S3 can answer 200 with an <Error> body when completing fails.
            $body = $done->getContent();
            if (str_contains($body, '<Error>')) {
                $error = self::xml($body);
                throw new R2Exception(sprintf('R2 multipart upload failed: %s', (string) ($error->Message ?? 'unknown error')), 200, (string) ($error->Code ?? '') ?: null);
            }
        } catch (\Throwable $e) {
            try {
                $this->send('DELETE', $bucket, $key, ['uploadId' => $uploadId], [], hash('sha256', ''), null, [404]);
            } catch (\Throwable) {
                // The upload expires on its own; report the first error.
            }
            throw $e instanceof R2Exception ? $e : new R2Exception('R2 multipart upload failed: '.$e->getMessage(), 0, null, $e);
        }
    }

    /**
     * Send a signed request; retry network errors, 5xx and 429 (with a short pause).
     *
     * @param array<string, string>                $query
     * @param array<string, string>                $headers
     * @param (\Closure(): (string|resource))|null $body    a fresh body for each attempt
     * @param list<int>                            $accept  other statuses that aren't errors
     */
    private function send(string $method, string $bucket, string $key, array $query, array $headers, string $payloadHash, ?\Closure $body = null, array $accept = []): ResponseInterface
    {
        $this->config->assertComplete();
        self::assertKey($bucket, $key);
        $path = self::path($bucket, $key);
        $url = $this->config->endpoint.$path.([] !== $query ? '?'.Signer::canonicalQuery($query) : '');

        for ($attempt = 0;; ++$attempt) {
            $signed = $this->signer->signRequest($method, $path, $query, ['host' => $this->config->host(), 'x-amz-content-sha256' => $payloadHash] + $headers, $payloadHash, ($this->clock)());
            unset($signed['host']); // the HTTP client sets it (same value)
            $options = ['headers' => $signed];
            if (null !== $body) {
                $options['body'] = $body();
            }
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $e) {
                if ($attempt < $this->retries) {
                    usleep(200_000 * (2 ** $attempt));
                    continue;
                }
                throw new R2Exception(sprintf('R2 %s %s failed: %s', $method, $path, $e->getMessage()), 0, null, $e);
            }
            if (($status >= 500 || 429 === $status) && $attempt < $this->retries) {
                usleep(200_000 * (2 ** $attempt));
                continue;
            }
            if (($status >= 200 && $status < 300) || \in_array($status, $accept, true)) {
                return $response;
            }

            $content = 'HEAD' === $method ? '' : $response->getContent(false);
            $error = '' !== $content && str_starts_with(ltrim($content), '<') ? self::xml($content) : null;
            $code = null !== $error ? (string) ($error->Code ?? '') : '';
            $message = null !== $error ? (string) ($error->Message ?? '') : '';
            throw new R2Exception(sprintf('R2 %s %s failed with status %d%s.', $method, $path, $status, '' !== $code ? " ({$code}: {$message})" : ''), $status, '' !== $code ? $code : null);
        }
    }

    private static function assertKey(string $bucket, string $key): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new R2Exception(sprintf('"%s" is not a valid bucket name.', $bucket));
        }
        if ('' === $key || \strlen($key) > 1024 || str_contains($key, "\0")) {
            throw new R2Exception('An object key is 1 to 1024 bytes, without NUL characters.');
        }
    }

    private static function xml(string $content): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, \LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (false === $xml) {
            throw new R2Exception('R2 sent a response that is not valid XML.');
        }

        return $xml;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function lower(array $headers): array
    {
        return array_change_key_case($headers, \CASE_LOWER);
    }
}
