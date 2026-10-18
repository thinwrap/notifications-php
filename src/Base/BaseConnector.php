<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Base;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\DTO\Email\EmailAttachment;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;

/**
 * Minimal HTTP base class for notification connectors.
 *
 * PSR-18 client + PSR-17 factories with auto-discovery via php-http/discovery
 * (mirrors the TS bring-your-own-fetch hook). Helpers return raw PSR-7
 * `ResponseInterface`; non-2xx flows back to the connector subclass which
 * inspects status + body and calls `mapVendorError()` to construct a
 * `ConnectorError`. The wrapper holds no mutable state — no caches, no token
 * storage, no shared HTTP-client singleton.
 *
 * Every parameter that can hold a credential (URL, headers, body, the built
 * request) is `#[\SensitiveParameter]`: with `zend.exception_ignore_args=0`
 * (the default when no php.ini is loaded, e.g. official Docker images) PHP
 * records call arguments in exception traces, which error trackers capture.
 */
abstract class BaseConnector
{
    public function __construct(
        protected readonly ?ClientInterface $client = null,
        protected readonly ?RequestFactoryInterface $requestFactory = null,
        protected readonly ?StreamFactoryInterface $streamFactory = null,
    ) {}

    /**
     * Subclasses override to inject auth headers (Bearer, Basic, custom).
     * Default empty.
     *
     * @return array<string, string>
     */
    protected function getAuthHeaders(): array
    {
        return [];
    }

    /**
     * Concrete connectors implement. Receives the full PSR-7 response so
     * subclasses can read `Retry-After` and surface it inside `cause` (the
     * wrapper itself never retries).
     *
     * @param array<string, mixed>|null $body
     */
    abstract protected function mapVendorError(
        int $statusCode,
        ?array $body,
        ResponseInterface $response,
    ): ConnectorError;

    /**
     * Resolve an `EmailAttachment.content` (`string|resource`) to a raw byte
     * string, uniformly for every email connector. A string passes through; a
     * stream resource is fully read (then NOT closed — the caller owns the
     * resource lifecycle, since the wrapper holds no state).
     * Any other type, or an unreadable stream, raises a typed `InvalidRequest`
     * so the same input never succeeds on one provider and throws on another.
     */
    protected function normalizeAttachmentContent(EmailAttachment $attachment): string
    {
        $content = $attachment->content;
        if (is_string($content)) {
            return $content;
        }
        if (is_resource($content)) {
            $raw = stream_get_contents($content);
            if ($raw === false) {
                throw new ConnectorError(
                    providerCode: ProviderCode::InvalidRequest,
                    providerMessage: "Failed to read attachment stream for '{$attachment->filename}'",
                    statusCode: 400,
                );
            }

            return $raw;
        }

        throw new ConnectorError(
            providerCode: ProviderCode::InvalidRequest,
            providerMessage: "Attachment content for '{$attachment->filename}' must be a string or stream resource",
            statusCode: 400,
        );
    }

    protected function getClient(): ClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        try {
            return Psr18ClientDiscovery::find();
        } catch (\Http\Discovery\Exception\NotFoundException $err) {
            throw new ConnectorError(
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: 'No PSR-18 HTTP client found',
                cause: [
                    'raw' => ['exception' => $err::class, 'message' => $err->getMessage()],
                    'retryAfter' => null,
                ],
            );
        }
    }

    protected function getRequestFactory(): RequestFactoryInterface
    {
        try {
            return $this->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        } catch (\Http\Discovery\Exception\NotFoundException $err) {
            throw self::noPsr17Factory($err);
        }
    }

    protected function getStreamFactory(): StreamFactoryInterface
    {
        try {
            return $this->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        } catch (\Http\Discovery\Exception\NotFoundException $err) {
            throw self::noPsr17Factory($err);
        }
    }

    /** Same treatment as a missing PSR-18 client in getClient(). */
    private static function noPsr17Factory(\Throwable $err): ConnectorError
    {
        return new ConnectorError(
            providerCode: ProviderCode::ProviderUnavailable,
            providerMessage: 'No PSR-17 HTTP factory found',
            cause: [
                'raw' => ['exception' => $err::class, 'message' => $err->getMessage()],
                'retryAfter' => null,
            ],
        );
    }

    /**
     * @param array<string, string> $headers
     */
    protected function sendGet(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = []): ResponseInterface
    {
        return $this->dispatch($this->buildRequest('GET', $url, array_merge($this->getAuthHeaders(), $headers)));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function sendPostJson(
        #[\SensitiveParameter]
        string $url,
        #[\SensitiveParameter]
        array $body,
        #[\SensitiveParameter]
        array $headers = [],
        ?string $protocolVersion = null,
    ): ResponseInterface {
        try {
            $serialized = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $err) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Failed to encode request body',
                cause: [
                    'raw' => ['exception' => $err::class, 'message' => $err->getMessage()],
                    'retryAfter' => null,
                ],
            );
        }

        return $this->sendPostJsonRaw($url, $serialized, $headers, $protocolVersion);
    }

    /**
     * Byte-exact JSON POST. Required for AWS Sig V4 (SES, SNS) where the
     * connector signs the exact bytes of the request payload and `sendPostJson`
     * re-encoding would invalidate the signature.
     *
     * `$protocolVersion` (e.g. `'2.0'`) pins the PSR-7 request's HTTP version when
     * the endpoint mandates a specific protocol (APNs is HTTP/2-only); `null`
     * leaves the PSR-7 default (`1.1`).
     *
     * @param array<string, string> $headers
     */
    protected function sendPostJsonRaw(
        #[\SensitiveParameter]
        string $url,
        #[\SensitiveParameter]
        string $rawJsonBody,
        #[\SensitiveParameter]
        array $headers = [],
        ?string $protocolVersion = null,
    ): ResponseInterface {
        $finalHeaders = array_merge(
            ['Content-Type' => 'application/json'],
            $this->getAuthHeaders(),
            $headers,
        );

        return $this->dispatch($this->buildRequest('POST', $url, $finalHeaders, $rawJsonBody, $protocolVersion));
    }

    /**
     * Form POST with `application/x-www-form-urlencoded`. Supports
     * array-valued fields via repeated keys per RFC 6570 (required by Twilio
     * MMS `MediaUrl`, Mailgun `o:tag`, etc.).
     *
     * @param array<string, scalar|array<int, scalar>|null> $form
     * @param array<string, string> $headers
     */
    protected function sendPostForm(
        #[\SensitiveParameter]
        string $url,
        #[\SensitiveParameter]
        array $form,
        #[\SensitiveParameter]
        array $headers = [],
    ): ResponseInterface {
        $finalHeaders = array_merge(
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $this->getAuthHeaders(),
            $headers,
        );

        return $this->dispatch($this->buildRequest('POST', $url, $finalHeaders, self::serializeForm($form)));
    }

    /**
     * Multipart POST. Each part is one of:
     *   ['name' => string, 'contents' => string]
     *   ['name' => string, 'filename' => string, 'contentType' => string, 'contents' => string]
     *
     * @param list<array{name:string,contents:string,filename?:string,contentType?:string}> $parts
     * @param array<string, string> $headers
     */
    protected function sendPostMultipart(
        #[\SensitiveParameter]
        string $url,
        #[\SensitiveParameter]
        array $parts,
        #[\SensitiveParameter]
        array $headers = [],
    ): ResponseInterface {
        $built = $this->buildMultipartBody($parts);

        $finalHeaders = array_merge(
            ['Content-Type' => 'multipart/form-data; boundary=' . $built['boundary']],
            $this->getAuthHeaders(),
            $headers,
        );

        return $this->dispatch($this->buildRequest('POST', $url, $finalHeaders, $built['body']));
    }

    /**
     * Checked here rather than left to the PSR-7 implementation: Nyholm's
     * validator lets a single trailing LF through, so an API key read with its
     * newline would otherwise be sent (and could split the header). CR, LF and
     * NUL are refused in names and values; the error names the header at
     * most, never the value. Non-string scalars (from `_passthrough.headers`)
     * are sent as strings, as PSR-7 `withHeader` accepts them; anything else
     * is not a header value.
     *
     * @param array<array-key,mixed> $headers
     *
     * @return array<string,string>
     */
    private static function checkHeaders(#[\SensitiveParameter] array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $safeName = strpbrk($name, "\r\n\0") === false;
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (!is_string($value)) {
                throw self::invalidHeader($safeName ? "header '{$name}' must have a string or scalar value" : 'a header must have a string or scalar value');
            }
            if (!$safeName || strpbrk($value, "\r\n\0") !== false) {
                throw self::invalidHeader($safeName ? "header '{$name}' contains CR, LF or NUL" : 'a header name contains CR, LF or NUL');
            }
            $out[$name] = $value;
        }

        return $out;
    }

    private static function invalidHeader(string $detail): ConnectorError
    {
        return new ConnectorError(
            providerCode: ProviderCode::InvalidRequest,
            providerMessage: "Invalid request header: {$detail}",
            cause: ['raw' => null, 'retryAfter' => null],
        );
    }

    /**
     * Build a PSR-7 request, turning a PSR-7 rejection of the URL or a header
     * into a `ConnectorError`.
     *
     * PSR-7 implementations throw `\InvalidArgumentException` with the offending
     * value in the message (Guzzle: `"<value>" is not valid header value.`,
     * `Unable to parse URI: <uri>`; Nyholm likewise). The value is usually a
     * credential — an API key read from the environment with a trailing
     * newline, a Telegram token in the path, a webhook URL — so neither the
     * message nor the previous exception is kept: only the exception class.
     *
     * @param array<string, string> $headers
     */
    protected function buildRequest(
        string $method,
        #[\SensitiveParameter]
        string $url,
        #[\SensitiveParameter]
        array $headers,
        #[\SensitiveParameter]
        ?string $body = null,
        ?string $protocolVersion = null,
    ): RequestInterface {
        $headers = self::checkHeaders($headers);

        try {
            $request = $this->getRequestFactory()->createRequest($method, $url);
            if ($body !== null) {
                $request = $request->withBody($this->getStreamFactory()->createStream($body));
            }
            if ($protocolVersion !== null) {
                $request = $request->withProtocolVersion($protocolVersion);
            }
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
        } catch (\InvalidArgumentException $err) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'Invalid request URL or header value',
                cause: [
                    'raw' => ['exception' => $err::class],
                    'retryAfter' => null,
                ],
            );
        }

        return $request;
    }

    /**
     * @param array<string, scalar|array<int, scalar>|null> $form
     */
    private static function serializeForm(#[\SensitiveParameter] array $form): string
    {
        $pairs = [];
        foreach ($form as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $sub) {
                    $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(self::stringifyScalar($sub));
                }
            } else {
                $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(self::stringifyScalar($value));
            }
        }

        return implode('&', $pairs);
    }

    /**
     * @param scalar $value
     */
    private static function stringifyScalar(#[\SensitiveParameter] mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param list<array{name:string,contents:string,filename?:string,contentType?:string}> $parts
     *
     * @return array{boundary:string,body:string}
     */
    private function buildMultipartBody(#[\SensitiveParameter] array $parts): array
    {
        $boundary = bin2hex(random_bytes(16));
        $buffer = '';
        foreach ($parts as $part) {
            $buffer .= '--' . $boundary . "\r\n";
            $disposition = 'Content-Disposition: form-data; name="' . self::sanitizeHeaderParam($part['name']) . '"';
            if (isset($part['filename'])) {
                $disposition .= '; filename="' . self::sanitizeHeaderParam($part['filename']) . '"';
            }
            $buffer .= $disposition . "\r\n";
            if (isset($part['contentType'])) {
                $buffer .= 'Content-Type: ' . self::sanitizeHeaderValue($part['contentType']) . "\r\n";
            }
            $buffer .= "\r\n" . $part['contents'] . "\r\n";
        }
        $buffer .= '--' . $boundary . "--\r\n";

        return ['boundary' => $boundary, 'body' => $buffer];
    }

    /**
     * Sanitize a quoted Content-Disposition parameter (`name`/`filename`) before
     * it is interpolated into a multipart header line. Strips CR/LF (header
     * injection) and backslash-escapes `"` and `\` so a value cannot break out
     * of the surrounding quotes. Mirrors the browser/RFC 2388 convention.
     */
    private static function sanitizeHeaderParam(string $value): string
    {
        $stripped = str_replace(["\r", "\n"], '', $value);

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $stripped);
    }

    /**
     * Sanitize an unquoted header value (`Content-Type`) — strip CR/LF so an
     * attacker-controlled content type cannot inject additional header lines.
     */
    private static function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    /**
     * Send a fully-built PSR-7 request through the resolved PSR-18 client,
     * translating any transport failure into the hardened `ConnectorError`
     * below. `protected` so connectors that must build the request themselves
     * (SES/SNS SigV4 sign exact bytes) still funnel transport errors through
     * this single hardened path instead of re-implementing the catch.
     */
    protected function dispatch(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        try {
            return $this->getClient()->sendRequest($request);
        } catch (ClientExceptionInterface $err) {
            // PSR-18 requires a non-2xx to be RETURNED, not thrown, and every
            // connector's status mapping depends on it. A client that ignores that
            // (Guzzle used outside its PSR-18 adapter, a decorator calling
            // raise-on-error) still answered, so recover the response and let the
            // connector map it. Duck-typed: no HTTP-client dependency.
            $response = $this->recoverResponse($err);
            if ($response !== null) {
                return $response;
            }

            // The raw client-exception message can embed the full request URL,
            // which for some providers IS the credential (Telegram bot token in
            // the path; Slack/Discord/GoogleChat/Mattermost/MsTeams webhook URLs).
            // Surface only a generic message + the exception class — never the
            // raw message text — so consumers cannot log a credential-bearing URL.
            throw new ConnectorError(
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: 'Upstream transport error',
                cause: [
                    'raw' => ['exception' => $err::class],
                    'retryAfter' => null,
                ],
            );
        }
    }

    /**
     * Recover the PSR-7 response a client attached to its exception, if any.
     *
     * Guzzle's `BadResponseException`, Symfony's `HttpExceptionInterface` and
     * most decorators expose the answered response through `getResponse()`.
     * Checked reflectively so no HTTP-client package becomes a dependency.
     */
    private function recoverResponse(\Throwable $exception): ?ResponseInterface
    {
        if (!method_exists($exception, 'getResponse')) {
            return null;
        }

        try {
            $response = $exception->getResponse();
        } catch (\Throwable) {
            return null;
        }

        return $response instanceof ResponseInterface ? $response : null;
    }
}
