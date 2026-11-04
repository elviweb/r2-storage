<?php

declare(strict_types=1);

namespace Elviweb\R2Storage;

/**
 * Connection settings for an R2 account (or any S3-compatible service).
 *
 * The endpoint is the account's S3 API address, https://<account-id>.r2.cloudflarestorage.com;
 * buckets are addressed path-style under it. Settings are checked when a request is made,
 * not here, so an app can boot (e.g. with the local driver) while R2 isn't configured.
 */
final class R2Config
{
    public readonly string $endpoint;

    public function __construct(
        string $endpoint,
        public readonly string $accessKeyId,
        #[\SensitiveParameter] public readonly string $secretAccessKey,
        public readonly string $region = 'auto',
    ) {
        $this->endpoint = rtrim(trim($endpoint), '/');
    }

    public static function forAccount(string $accountId, string $accessKeyId, #[\SensitiveParameter] string $secretAccessKey): self
    {
        return new self(sprintf('https://%s.r2.cloudflarestorage.com', $accountId), $accessKeyId, $secretAccessKey);
    }

    /** @throws R2Exception when a setting is missing or the endpoint isn't an http(s) URL */
    public function assertComplete(): void
    {
        $missing = [];
        foreach (['endpoint' => $this->endpoint, 'access key id' => $this->accessKeyId, 'secret access key' => $this->secretAccessKey, 'region' => $this->region] as $name => $value) {
            if ('' === trim($value)) {
                $missing[] = $name;
            }
        }
        if ($missing) {
            throw new R2Exception('R2 is not configured: missing '.implode(', ', $missing).'.');
        }
        $scheme = parse_url($this->endpoint, \PHP_URL_SCHEME);
        if (!\in_array($scheme, ['https', 'http'], true) || null === parse_url($this->endpoint, \PHP_URL_HOST)) {
            throw new R2Exception(sprintf('The R2 endpoint "%s" is not an http(s) URL.', $this->endpoint));
        }
    }

    /** The Host header value requests are signed with (with the port when not the default one). */
    public function host(): string
    {
        $host = (string) parse_url($this->endpoint, \PHP_URL_HOST);
        $port = parse_url($this->endpoint, \PHP_URL_PORT);

        return \is_int($port) ? $host.':'.$port : $host;
    }
}
