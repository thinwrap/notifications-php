<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Tests\Base;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Base\BaseConnector;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;

/**
 * Test-only subclass exposing the protected helpers as public passthroughs and
 * providing a stub `mapVendorError`. Lives in `tests/` by design.
 */
final class TestableConnector extends BaseConnector
{
    /**
     * @param array<string, string> $authHeaders
     */
    public function __construct(
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        private readonly array $authHeaders = [],
    ) {
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    /**
     * @return array<string, string>
     */
    protected function getAuthHeaders(): array
    {
        return $this->authHeaders;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function mapVendorError(
        int $statusCode,
        ?array $body,
        ResponseInterface $response,
    ): ConnectorError {
        return new ConnectorError(ProviderCode::Unknown, 'stub', ['body' => $body], $statusCode);
    }

    /**
     * @param array<string, string> $headers
     */
    public function callSendGet(string $url, array $headers = []): ResponseInterface
    {
        return $this->sendGet($url, $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function callSendPostJson(string $url, array $body, array $headers = []): ResponseInterface
    {
        return $this->sendPostJson($url, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function callSendPostJsonRaw(string $url, string $rawJsonBody, array $headers = []): ResponseInterface
    {
        return $this->sendPostJsonRaw($url, $rawJsonBody, $headers);
    }

    /**
     * @param array<string, scalar|array<int, scalar>|null> $form
     * @param array<string, string> $headers
     */
    public function callSendPostForm(string $url, array $form, array $headers = []): ResponseInterface
    {
        return $this->sendPostForm($url, $form, $headers);
    }

    /**
     * @param list<array{name:string,contents:string,filename?:string,contentType?:string}> $parts
     * @param array<string, string> $headers
     */
    public function callSendPostMultipart(string $url, array $parts, array $headers = []): ResponseInterface
    {
        return $this->sendPostMultipart($url, $parts, $headers);
    }
}
