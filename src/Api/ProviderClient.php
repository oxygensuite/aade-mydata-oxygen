<?php

namespace OxygenSuite\AadeMyData\Api;

use Closure;
use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin wrapper over the mydataprovider v2 endpoints.
 * HTTP errors are returned as ProviderResponse; only 401 and transport failures throw.
 * An `{invoice}` path segment is the provider's id or the document's myDATA mark: the
 * provider binds either.
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
     * Catering delivery orders that are currently pending or expired. The response is not
     * paginated: it lists every match together with the aggregate totals of the set.
     *
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<string, scalar> $filters status (`pending`/`expired`), table_number, locale
     */
    public function findCateringDocuments(array $filters = []): ProviderResponse
    {
        return $this->send('GET', 'catering-documents', ['query' => $filters]);
    }

    /**
     * Aggregated net, VAT and gross totals for a set of pending catering documents.
     *
     * @throws ProviderException|UnauthorizedException
     *
     * @param list<string> $ids ulids of the catering documents to aggregate
     */
    public function cateringDocumentTotals(array $ids): ProviderResponse
    {
        return $this->send('GET', 'catering-documents/totals', ['query' => ['ids' => $ids]]);
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
    public function showInvoice(string $invoice): ProviderResponse
    {
        return $this->send('GET', "invoices/$invoice");
    }

    /**
     * Updates the Peppol metadata (contract references and counterpart identification) of an
     * invoice already transmitted to myDATA. The payload must carry `options.is_peppol: true`
     * to route the request to the Peppol update handler.
     *
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<array-key, mixed> $payload
     */
    public function patchInvoice(string $invoice, array $payload): ProviderResponse
    {
        return $this->send('PATCH', "invoices/$invoice", ['json' => $payload]);
    }

    /**
     * The raw myDATA XML transmitted for the invoice, in the response's raw body.
     *
     * @throws ProviderException|UnauthorizedException
     */
    public function showInvoiceMyData(string $invoice): ProviderResponse
    {
        return $this->send('GET', "invoices/$invoice/mydata");
    }

    /**
     * Runs the invoice validation pipeline without creating or transmitting the invoice.
     *
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<array-key, mixed> $payload
     */
    public function validateInvoice(array $payload): ProviderResponse
    {
        return $this->send('POST', 'invoices/validate', ['json' => $payload]);
    }

    /**
     * @throws ProviderException|UnauthorizedException
     */
    public function cancelInvoice(string $invoice): ProviderResponse
    {
        return $this->send('PATCH', "invoices/$invoice/cancel");
    }

    /**
     * Re-submits an already-transmitted invoice to the Peppol network.
     *
     * @throws ProviderException|UnauthorizedException
     */
    public function resendInvoice(string $invoice): ProviderResponse
    {
        return $this->send('POST', "invoices/$invoice/resend");
    }

    /**
     * The deferred payment flow: payment methods for an invoice the provider already holds.
     *
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<array-key, mixed> $payload
     */
    public function storePayments(string $invoice, array $payload): ProviderResponse
    {
        return $this->send('POST', "invoices/$invoice/payments", ['json' => $payload]);
    }

    /**
     * The payments already registered against an invoice, ordered newest to oldest.
     *
     * @throws ProviderException|UnauthorizedException
     */
    public function findPayments(string $invoice): ProviderResponse
    {
        return $this->send('GET', "invoices/$invoice/payments");
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
     * The provider's application name and current API version.
     *
     * @throws ProviderException|UnauthorizedException
     */
    public function home(): ProviderResponse
    {
        return $this->send('GET', '');
    }

    /**
     * Liveness probe; the provider answers with the current date and time.
     *
     * @throws ProviderException|UnauthorizedException
     */
    public function ping(): ProviderResponse
    {
        return $this->send('GET', 'ping');
    }

    /**
     * Sends $payload to the echoing endpoint with $method (GET, POST, PUT, PATCH or DELETE)
     * and returns the request as the provider received it: useful for checking auth and the
     * exact encoded payload.
     *
     * @throws ProviderException|UnauthorizedException
     *
     * @param array<array-key, mixed> $payload
     */
    public function echo(string $method = 'GET', array $payload = []): ProviderResponse
    {
        return $this->send($method, 'echo', $payload === [] ? [] : ['json' => $payload]);
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
