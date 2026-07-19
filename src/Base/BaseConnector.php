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
        return $this->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
    }

    protected function getStreamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @param array<string, string> $headers
     */
    protected function sendGet(string $url, array $headers = []): ResponseInterface
    {
        $request = $this->getRequestFactory()->createRequest('GET', $url);
        foreach (array_merge($this->getAuthHeaders(), $headers) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->dispatch($request);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function sendPostJson(string $url, array $body, array $headers = []): ResponseInterface
    {
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

        return $this->sendPostJsonRaw($url, $serialized, $headers);
    }

    /**
     * Byte-exact JSON POST. Required for AWS Sig V4 (SES, SNS) where the
     * connector signs the exact bytes of the request payload and `sendPostJson`
     * re-encoding would invalidate the signature.
     *
     * @param array<string, string> $headers
     */
    protected function sendPostJsonRaw(string $url, string $rawJsonBody, array $headers = []): ResponseInterface
    {
        $request = $this->getRequestFactory()
            ->createRequest('POST', $url)
            ->withBody($this->getStreamFactory()->createStream($rawJsonBody));

        $finalHeaders = array_merge(
            ['Content-Type' => 'application/json'],
            $this->getAuthHeaders(),
            $headers,
        );
        foreach ($finalHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->dispatch($request);
    }

    /**
     * Form POST with `application/x-www-form-urlencoded`. Supports
     * array-valued fields via repeated keys per RFC 6570 (required by Twilio
     * MMS `MediaUrl`, Mailgun `o:tag`, etc.).
     *
     * @param array<string, scalar|array<int, scalar>|null> $form
     * @param array<string, string> $headers
     */
    protected function sendPostForm(string $url, array $form, array $headers = []): ResponseInterface
    {
        $serialized = self::serializeForm($form);
        $request = $this->getRequestFactory()
            ->createRequest('POST', $url)
            ->withBody($this->getStreamFactory()->createStream($serialized));

        $finalHeaders = array_merge(
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $this->getAuthHeaders(),
            $headers,
        );
        foreach ($finalHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->dispatch($request);
    }

    /**
     * Multipart POST. Each part is one of:
     *   ['name' => string, 'contents' => string]
     *   ['name' => string, 'filename' => string, 'contentType' => string, 'contents' => string]
     *
     * @param list<array{name:string,contents:string,filename?:string,contentType?:string}> $parts
     * @param array<string, string> $headers
     */
    protected function sendPostMultipart(string $url, array $parts, array $headers = []): ResponseInterface
    {
        $built = $this->buildMultipartBody($parts);

        $request = $this->getRequestFactory()
            ->createRequest('POST', $url)
            ->withBody($this->getStreamFactory()->createStream($built['body']));

        $finalHeaders = array_merge(
            ['Content-Type' => 'multipart/form-data; boundary=' . $built['boundary']],
            $this->getAuthHeaders(),
            $headers,
        );
        foreach ($finalHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->dispatch($request);
    }

    /**
     * @param array<string, scalar|array<int, scalar>|null> $form
     */
    private static function serializeForm(array $form): string
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
    private static function stringifyScalar(mixed $value): string
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
    private function buildMultipartBody(array $parts): array
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
     * of the surrounding quotes (W1). Mirrors the browser/RFC 2388 convention.
     */
    private static function sanitizeHeaderParam(string $value): string
    {
        $stripped = str_replace(["\r", "\n"], '', $value);

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $stripped);
    }

    /**
     * Sanitize an unquoted header value (`Content-Type`) — strip CR/LF so an
     * attacker-controlled content type cannot inject additional header lines (W1).
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
    protected function dispatch(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->getClient()->sendRequest($request);
        } catch (ClientExceptionInterface $err) {
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
}
