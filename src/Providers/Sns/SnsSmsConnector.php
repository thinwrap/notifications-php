<?php

declare(strict_types=1);

namespace Thinwrap\Notifications\Providers\Sns;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Thinwrap\Notifications\Base\BaseConnector;
use Thinwrap\Notifications\Contract\SmsConnectorInterface;
use Thinwrap\Notifications\DTO\Sms\SmsSendInput;
use Thinwrap\Notifications\DTO\Sms\SmsSendResult;
use Thinwrap\Notifications\DTO\Sms\SmsStatus;
use Thinwrap\Notifications\Enum\ProviderCode;
use Thinwrap\Notifications\Exception\ConnectorError;
use Thinwrap\Notifications\Util\Passthrough;

/**
 * AWS SNS SMS connector (PHP, hand-rolled Sig V4).
 *
 * Mirrors the TS connector (`notifications-ts/src/providers/sns/sns.connector.ts`).
 *
 * POSTs `application/x-www-form-urlencoded` AWS Query API request
 * (`Action=Publish&Version=2010-03-31&...`) to `https://sns.<region>.amazonaws.com/`
 * with hand-rolled AWS Signature Version 4 (PHP stdlib only — no `aws/aws-sdk-php`).
 * Sig V4 is intentionally duplicated with SES per the casing-locality rule; the
 * extraction threshold is a third AWS-signed connector.
 *
 * Per the stateless-wrapper rule, every `.send()` call
 * computes a fresh signature with a fresh `X-Amz-Date` — no signature caching.
 *
 * `MessageAttributes` flattening happens BEFORE signing, so the signed
 * canonical request covers the indexed `MessageAttributes.entry.N.*` keys.
 * `_passthrough` body merge also happens before signing.
 *
 * Error responses are XML (`<ErrorResponse><Error><Code>…</Code><Message>…</Message></Error></ErrorResponse>`)
 * — parsed via simple tag extraction, mirror of the TS regex approach.
 */
class SnsSmsConnector extends BaseConnector implements SmsConnectorInterface
{
    /**
     * Headers never folded into the Sig V4 signature, even when supplied via
     * `_passthrough.headers` (they still go on the wire, just unsigned — AWS
     * only validates headers listed in `SignedHeaders`). Per the AWS signing
     * guide: "Do not include hop-by-hop headers that are frequently altered
     * during transit" — proxies, load balancers, and the HTTP client itself
     * rewrite these after signing, which would invalidate the signature.
     * `content-length` is additionally client-owned (Guzzle/cURL recompute it
     * from the body). Mirrors `@smithy/signature-v4` ALWAYS_UNSIGNABLE_HEADERS
     * plus `content-length`, byte-parity with the TS sibling. Note
     * `Content-Type` is deliberately NOT here: the same guide requires it
     * signed when present (`aws-sdk-php` skips it; smithy/botocore sign it —
     * we follow the spec text and keep TS↔PHP signatures identical).
     *
     * @var array<string,true>
     */
    private const UNSIGNABLE_HEADERS = [
        'authorization'       => true,
        'cache-control'       => true,
        'connection'          => true,
        'content-length'      => true,
        'expect'              => true,
        'from'                => true,
        'keep-alive'          => true,
        'max-forwards'        => true,
        'pragma'              => true,
        'proxy-authenticate'  => true,
        'proxy-authorization' => true,
        'referer'             => true,
        'te'                  => true,
        'trailer'             => true,
        'transfer-encoding'   => true,
        'upgrade'             => true,
        'user-agent'          => true,
        'x-amzn-trace-id'     => true,
    ];

    public readonly string $id;

    public function __construct(
        private readonly SnsConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->id = 'sns';
        parent::__construct($client, $requestFactory, $streamFactory);
    }

    public function send(SmsSendInput $input): SmsSendResult
    {
        $topicArn = $input instanceof SnsNarrowedInput ? $input->topicArn : null;
        if ($input->to === '' && ($topicArn === null || $topicArn === '')) {
            throw new ConnectorError(
                providerCode: ProviderCode::InvalidRequest,
                providerMessage: 'SNS requires either `to` (phone E.164) or `topicArn`.',
                statusCode: 400,
            );
        }

        $form = [
            'Action'  => 'Publish',
            'Version' => '2010-03-31',
            'Message' => $input->body,
        ];
        if ($input->to !== '') {
            $form['PhoneNumber'] = $input->to;
        }
        if ($topicArn !== null && $topicArn !== '') {
            $form['TopicArn'] = $topicArn;
        }

        // Merge SMS-convenience fields into attributes.
        /** @var array<string,array{DataType:string,StringValue?:string,BinaryValue?:string}> $attrs */
        $attrs = [];
        if ($input instanceof SnsNarrowedInput) {
            if ($input->messageAttributes !== null) {
                $attrs = $input->messageAttributes;
            }
            if ($input->messageStructure !== null) {
                $form['MessageStructure'] = $input->messageStructure;
            }
            if ($input->smsType !== null) {
                $attrs['AWS.SNS.SMS.SMSType'] = ['DataType' => 'String', 'StringValue' => $input->smsType];
            }
            if ($input->senderId !== null) {
                $attrs['AWS.SNS.SMS.SenderID'] = ['DataType' => 'String', 'StringValue' => $input->senderId];
            }
            if ($input->maxPrice !== null) {
                $attrs['AWS.SNS.SMS.MaxPrice'] = ['DataType' => 'Number', 'StringValue' => $input->maxPrice];
            }
        }

        $i = 0;
        foreach ($attrs as $name => $value) {
            $i++;
            $form["MessageAttributes.entry.{$i}.Name"] = $name;
            $form["MessageAttributes.entry.{$i}.Value.DataType"] = $value['DataType'];
            if (isset($value['StringValue'])) {
                $form["MessageAttributes.entry.{$i}.Value.StringValue"] = $value['StringValue'];
            }
            if (isset($value['BinaryValue'])) {
                $form["MessageAttributes.entry.{$i}.Value.BinaryValue"] = $value['BinaryValue'];
            }
        }

        // Passthrough body merge BEFORE signing so consumer-supplied extras
        // participate in the signature.
        $merged = Passthrough::merge(
            ['body' => $form, 'headers' => [], 'query' => []],
            $input->_passthrough,
        );
        /** @var array<string,mixed> $mergedBody */
        $mergedBody = $merged['body'];
        /** @var array<string,string> $passthroughHeaders */
        $passthroughHeaders = $merged['headers'];

        // Coerce to scalar string form pairs.
        $formPairs = [];
        foreach ($mergedBody as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_bool($v)) {
                $formPairs[(string) $k] = $v ? 'true' : 'false';
            } elseif (is_scalar($v)) {
                $formPairs[(string) $k] = (string) $v;
            }
        }

        $serializedBody = self::serializeForm($formPairs);
        $isoTimestamp = $this->getTimestamp();

        $contentTypeHeader = 'application/x-www-form-urlencoded';
        $additionalSignedHeaders = array_merge(
            ['Content-Type' => $contentTypeHeader],
            $passthroughHeaders,
        );

        // Build the canonical query string (sorted keys, rawurlencoded) ONCE so
        // the signed canonical request and the request URL are byte-identical —
        // otherwise AWS returns SignatureDoesNotMatch (403).
        $canonicalQueryString = self::buildCanonicalQueryString($merged['query']);

        $signedHeaders = $this->signRequest($serializedBody, $additionalSignedHeaders, $isoTimestamp, $canonicalQueryString);

        $finalHeaders = array_merge($additionalSignedHeaders, $signedHeaders);

        $host = $this->host();
        $url = "https://{$host}/";
        if ($canonicalQueryString !== '') {
            $url .= '?' . $canonicalQueryString;
        }

        // Use a form-style post with the exact pre-serialized body. We override
        // Content-Type via finalHeaders so this is effectively raw POST.
        $request = $this->getRequestFactory()
            ->createRequest('POST', $url)
            ->withBody($this->getStreamFactory()->createStream($serializedBody));
        foreach ($finalHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->getClient()->sendRequest($request);
        } catch (\Psr\Http\Client\ClientExceptionInterface $err) {
            throw new ConnectorError(
                providerCode: ProviderCode::ProviderUnavailable,
                providerMessage: $err->getMessage(),
                cause: ['exception' => $err::class, 'message' => $err->getMessage()],
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $xml = (string) $response->getBody();
            throw $this->mapVendorError($status, $xml === '' ? null : ['raw' => $xml], $response);
        }

        $xml = (string) $response->getBody();
        $messageId = self::extractXmlTag($xml, 'MessageId');

        return new SmsSendResult(
            success: true,
            status: SmsStatus::Sent,
            providerMessageId: $messageId,
            raw: $xml,
        );
    }

    /**
     * Hand-rolled AWS Sig V4 mirroring the SES connector implementation.
     * Returns the AWS-managed headers (Host, X-Amz-Date, X-Amz-Content-Sha256,
     * Authorization, and optionally X-Amz-Security-Token).
     *
     * @param array<string,string> $additionalSignedHeaders Headers beyond Host/X-Amz-Date that must participate in the signature.
     * @param string $canonicalQueryString Sorted, rawurlencoded canonical query string (empty when no `_passthrough.query`).
     *
     * @return array<string,string>
     */
    private function signRequest(string $serializedBody, array $additionalSignedHeaders, string $isoTimestamp, string $canonicalQueryString = ''): array
    {
        $region = $this->config->region;
        $service = 'sns';
        $host = $this->host();
        $dateStamp = substr($isoTimestamp, 0, 8);

        $hashedPayload = hash('sha256', $serializedBody);

        // X-Amz-Content-Sha256 is signed here too — parity with the SES
        // connector and the TS siblings (both sign it on every AWS request).
        $canonicalHeaders = $additionalSignedHeaders;
        $canonicalHeaders['Host'] = $host;
        $canonicalHeaders['X-Amz-Date'] = $isoTimestamp;
        $canonicalHeaders['X-Amz-Content-Sha256'] = $hashedPayload;
        if ($this->config->sessionToken !== null) {
            $canonicalHeaders['X-Amz-Security-Token'] = $this->config->sessionToken;
        }

        // Lower-case names, trim + collapse internal whitespace runs in values
        // per the Sig V4 canonicalization spec (`aws-sdk-php` does the same
        // via `preg_replace('/\s+/', ' ', ...)`); sort by lower-cased name.
        // Hop-by-hop / client-owned headers are excluded from the signature.
        $normalized = [];
        foreach ($canonicalHeaders as $name => $value) {
            $lowerName = strtolower($name);
            if (isset(self::UNSIGNABLE_HEADERS[$lowerName])) {
                continue;
            }
            $normalized[$lowerName] = (string) preg_replace('/\s+/', ' ', trim((string) $value));
        }
        ksort($normalized);

        $canonicalHeadersString = '';
        foreach ($normalized as $name => $value) {
            $canonicalHeadersString .= "{$name}:{$value}\n";
        }
        $signedHeadersList = implode(';', array_keys($normalized));

        // AWS Query API uses POST / for SNS Publish. The canonical query string
        // (line 3) must match the query appended to the request URL.
        $canonicalRequest = "POST\n/\n{$canonicalQueryString}\n{$canonicalHeadersString}\n{$signedHeadersList}\n{$hashedPayload}";

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$isoTimestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->config->secretAccessKey, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = bin2hex(hash_hmac('sha256', $stringToSign, $kSigning, true));

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->config->accessKeyId,
            $credentialScope,
            $signedHeadersList,
            $signature,
        );

        $out = [
            'Host'                 => $host,
            'X-Amz-Date'           => $isoTimestamp,
            'X-Amz-Content-Sha256' => $hashedPayload,
            'Authorization'        => $authorization,
        ];
        if ($this->config->sessionToken !== null) {
            $out['X-Amz-Security-Token'] = $this->config->sessionToken;
        }

        return $out;
    }

    /**
     * Override seam for deterministic signature testing — mirrors SesEmailConnector.
     */
    protected function getTimestamp(): string
    {
        return gmdate('Ymd\\THis\\Z');
    }

    private function host(): string
    {
        return "sns.{$this->config->region}.amazonaws.com";
    }

    /**
     * AWS Query API form serialization. Sorted alphabetically is optional for
     * the wire (the canonical request hashes the header set, not body order),
     * but stable order helps debugging.
     *
     * @param array<string,string> $form
     */
    private static function serializeForm(array $form): string
    {
        $pairs = [];
        foreach ($form as $key => $value) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    /**
     * AWS SigV4 canonical query string: keys sorted, both key and value
     * rawurlencoded, joined `k=v&...`. The exact same string is appended to the
     * request URL so the signed canonical request matches the sent request.
     *
     * @param array<string,mixed> $query
     */
    private static function buildCanonicalQueryString(array $query): string
    {
        if ($query === []) {
            return '';
        }
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $stringValue = $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $stringValue = (string) $value;
            } else {
                continue;
            }
            $pairs[rawurlencode((string) $key)] = rawurlencode($stringValue);
        }
        ksort($pairs);

        $out = [];
        foreach ($pairs as $k => $v) {
            $out[] = "{$k}={$v}";
        }

        return implode('&', $out);
    }

    private static function extractXmlTag(?string $xml, string $tag): ?string
    {
        if ($xml === null || $xml === '') {
            return null;
        }
        if (preg_match("/<{$tag}>([^<]+)<\\/{$tag}>/", $xml, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * @param array<string,mixed>|null $body
     */
    protected function mapVendorError(int $statusCode, ?array $body, ResponseInterface $response): ConnectorError
    {
        $xml = is_array($body) && isset($body['raw']) && is_string($body['raw']) ? $body['raw'] : null;
        $code = self::extractXmlTag($xml, 'Code');
        $message = self::extractXmlTag($xml, 'Message');
        // SNS error XML is entity-encoded; decode so providerMessage is human text.
        if ($message !== null) {
            $message = html_entity_decode($message, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        $providerCode = $this->classifyError($statusCode, $code);
        $baseMessage = $message ?? "SNS HTTP {$statusCode}";

        $retryAfter = $response->getHeaderLine('Retry-After');
        $parsedSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $providerMessage = $parsedSeconds !== null
            ? "{$baseMessage} (Retry-After: {$parsedSeconds} seconds)"
            : $baseMessage;

        return new ConnectorError(
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            cause: [
                'raw'        => $xml,
                'retryAfter' => $retryAfter !== '' ? $retryAfter : null,
                'retryAfterSeconds' => $parsedSeconds,
            ],
            statusCode: $statusCode,
        );
    }

    private function classifyError(int $statusCode, ?string $code): ProviderCode
    {
        if (
            $code === 'InvalidClientTokenId'
            || $code === 'SignatureDoesNotMatch'
            || $statusCode === 401
            || $statusCode === 403
        ) {
            return ProviderCode::AuthFailed;
        }
        if ($code === 'Throttling' || $code === 'ThrottlingException' || $statusCode === 429) {
            return ProviderCode::RateLimited;
        }
        if ($statusCode >= 500) {
            return ProviderCode::ProviderUnavailable;
        }
        if ($code === 'InvalidParameter' || $code === 'InvalidParameterValue') {
            return ProviderCode::InvalidRequest;
        }
        if ($statusCode >= 400) {
            return ProviderCode::InvalidRequest;
        }
        return ProviderCode::Unknown;
    }
}
