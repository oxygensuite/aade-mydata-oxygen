<?php

namespace OxygenSuite\AadeMyData\Signatures;

use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use OxygenSuite\AadeMyData\Api\ProviderClient;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Api\ProviderResponse;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;
use OxygenSuite\AadeMyData\Enums\NSP;
use OxygenSuite\AadeMyData\Enums\SignatureDuration;
use OxygenSuite\AadeMyData\Exceptions\IssueTimeMissingException;
use OxygenSuite\AadeMyData\Mapping\SignatureMapper;

/**
 * POS signatures, reached through OxygenProvider::signatures().
 *
 * A card payment transmitted on the provider's channel must carry the provider's own
 * signature, which no ERP can compute. Issue one for the payment before the document is
 * transmitted, then hand it to the payment method:
 *
 *     $signature = OxygenProvider::signatures()->create($invoice, $payment, NSP::VIVA, SignatureDuration::HOURS_60);
 *     $payment->setProvidersSignature(null, $signature->signature);
 *
 * A signature is single use and expires; it can be cancelled while unused, never renewed.
 */
final class SignatureService
{
    public function __construct(private ProviderClient $client, private SignatureMapper $mapper) {}

    /**
     * Issues a signature for one card payment of $invoice. Every field except the network
     * and the validity window is read off the models, so set the payment's tid and — for a
     * payment on an already transmitted invoice — the invoice's mark beforehand.
     *
     * Never retry this call blindly: a lost answer may already have created the signature
     * (see pending()).
     *
     * @throws SignatureException|ProviderException|MyDataAuthenticationException|IssueTimeMissingException
     */
    public function create(Invoice $invoice, PaymentMethodDetail $payment, NSP $nsp, SignatureDuration $duration): Signature
    {
        $payload = $this->mapper->map($invoice, $payment, $nsp, $duration);
        $response = $this->send(fn (): ProviderResponse => $this->client->storeSignature($payload));

        // A success we cannot read still minted a signature, and the ERP has no id for it.
        return $this->one($response, 'The signature was created but could not be read: find it with pending() instead of creating another.');
    }

    /**
     * @throws SignatureException|ProviderException|MyDataAuthenticationException
     */
    public function find(string $id): Signature
    {
        return $this->one($this->send(fn (): ProviderResponse => $this->client->showSignature($id)));
    }

    /**
     * Releases a signature that was issued but will not be used. The provider refuses to
     * cancel one that an invoice or payment already references.
     *
     * @throws SignatureException|ProviderException|MyDataAuthenticationException
     */
    public function cancel(string $id): Signature
    {
        return $this->one($this->send(fn (): ProviderResponse => $this->client->cancelSignature($id)));
    }

    /**
     * The signatures still waiting to be used: unexpired and not yet referenced, newest
     * first, up to 100 per page ([] once the pages run out).
     *
     * This is the recovery tool, not a ledger: after a lost create() answer, look here for
     * the signature that may already exist before issuing a second one — a duplicate would
     * carry the same uid, since it is generated from the same invoice fields.
     *
     * @throws SignatureException|ProviderException|MyDataAuthenticationException
     * @return list<Signature>
     */
    public function pending(int $page = 1): array
    {
        $response = $this->send(fn (): ProviderResponse => $this->client->findSignatures(['status' => 'pending', 'page' => $page]));

        if (! $response->isSuccessful()) {
            throw SignatureException::rejected($response);
        }

        $data = $response->get('data');

        if (! is_array($data)) {
            throw SignatureException::unreadable($response);
        }

        $signatures = [];

        foreach ($data as $row) {
            $signatures[] = (is_array($row) ? Signature::tryFrom($row) : null) ?? throw SignatureException::unreadable($response);
        }

        return $signatures;
    }

    /**
     * @throws MyDataAuthenticationException|ProviderException
     *
     * @param callable(): ProviderResponse $call
     */
    private function send(callable $call): ProviderResponse
    {
        try {
            return $call();
        } catch (UnauthorizedException $e) {
            // The same exception an ERP already catches around SendInvoices.
            throw new MyDataAuthenticationException(401, $e);
        }
    }

    /**
     * @throws SignatureException
     */
    private function one(ProviderResponse $response, ?string $unreadableHint = null): Signature
    {
        if (! $response->isSuccessful()) {
            throw SignatureException::rejected($response);
        }

        $body = $response->body;

        return ($body === null ? null : Signature::tryFrom($body)) ?? throw SignatureException::unreadable($response, $unreadableHint);
    }
}
