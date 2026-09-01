<?php

namespace OxygenSuite\AadeMyData\Api;

use Closure;
use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin wrapper over the mydataprovider v2 endpoints the bridge needs.
 * HTTP errors are returned as ProviderResponse; only 401 and transport failures throw.
 */
final class ProviderClient
{
    /**
     * @param Closure(): string $baseUrl Resolved on every request so it can follow MyDataRequest's environment.
     */
    public function __construct(private Client $http, private Closure $baseUrl) {}

    /** Guzzle options for the provider connection; independent of MyDataRequest's AADE settings. */
    private const OPTIONS = ['connect_timeout' => 5, 'timeout' => 10];

    private const PACKAGE = 'oxygensuite/aade-mydata-oxygen';

    /**
     * @param Closure(): string $baseUrl
     * @param array<string, mixed> $options Extra Guzzle options (tests inject a handler here).
     */
    public static function create(string $token, Closure $baseUrl, array $options = []): self
    {
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'X-Client' => self::client(),
        ];

        return new self(new Client(['headers' => $headers] + $options + self::OPTIONS), $baseUrl);
    }

    /**
     * The token says which company is transmitting, not which software. X-Client tells the
     * provider that an invoice arrived through this bridge, and which release of it built the
     * payload, so its logs separate bridge traffic from a direct API integration.
     */
    private static function client(): string
    {
        $version = InstalledVersions::isInstalled(self::PACKAGE) ? InstalledVersions::getPrettyVersion(self::PACKAGE) : null;

        return $version === null ? self::PACKAGE : self::PACKAGE.'/'.$version;
    }

    /**
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<array-key, mixed> $payload
     */
    public function storeInvoice(array $payload): ProviderResponse
    {
        return $this->send('POST', 'invoices', ['json' => $payload]);
    }

    /**
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<array-key, mixed> $payload
     */
    public function cancelCateringDocuments(array $payload): ProviderResponse
    {
        return $this->send('POST', 'invoices/cancel', ['json' => $payload]);
    }

    /**
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<string, scalar> $filters
     */
    public function findInvoices(array $filters): ProviderResponse
    {
        return $this->send('GET', 'invoices', ['query' => $filters]);
    }

    /**
     * @throws ProviderException|UnauthorizedException
     */
    public function showInvoice(string $ulid): ProviderResponse
    {
        return $this->send('GET', "invoices/$ulid");
    }

    /**
     * @throws ProviderException|UnauthorizedException
     */
    public function cancelInvoice(string $ulid): ProviderResponse
    {
        return $this->send('PATCH', "invoices/$ulid/cancel");
    }

    /**
     * The deferred payment flow: payment methods for an invoice the provider already holds.
     *
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<array-key, mixed> $payload
     */
    public function storePayments(string $ulid, array $payload): ProviderResponse
    {
        return $this->send('POST', "invoices/$ulid/payments", ['json' => $payload]);
    }

    /**
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<array-key, mixed> $payload
     */
    public function storeSignature(array $payload): ProviderResponse
    {
        return $this->send('POST', 'signatures', ['json' => $payload]);
    }

    /**
     * @throws ProviderException|UnauthorizedException
     */
    public function showSignature(string $ulid): ProviderResponse
    {
        return $this->send('GET', "signatures/$ulid");
    }

    /**
     * Signatures are immutable; cancelling one is a delete.
     *
     * @throws ProviderException|UnauthorizedException
     */
    public function cancelSignature(string $ulid): ProviderResponse
    {
        return $this->send('DELETE', "signatures/$ulid");
    }

    /**
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<string, scalar> $filters
     */
    public function findSignatures(array $filters): ProviderResponse
    {
        return $this->send('GET', 'signatures', ['query' => $filters]);
    }

    /**
     * The profile of the company the token belongs to.
     *
     * @throws ProviderException|UnauthorizedException
     */
    public function showCompany(): ProviderResponse
    {
        return $this->send('GET', 'company');
    }
    /**
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, array $options = []): ProviderResponse
    {
        // 4xx/5xx are outcomes the response mapper decides on, never exceptions.
        $options['http_errors'] = false;

        try {
            $response = $this->http->request($method, $this->url($path), $options);
        } catch (GuzzleException $e) {
            throw ProviderException::fromGuzzle($e);
        }

        $result = new ProviderResponse($response->getStatusCode(), (string) $response->getBody());

        if ($result->status === 401) {
            throw new UnauthorizedException($result->message() ?? 'The provider rejected the API token.');
        }

        return $result;
    }

    private function url(string $path): string
    {
        return rtrim(($this->baseUrl)(), '/').'/'.ltrim($path, '/');
    }
}
