<?php

namespace OxygenSuite\AadeMyData\Signatures;

use DateTimeImmutable;
use Exception;
use OxygenSuite\AadeMyData\Enums\NSP;
use OxygenSuite\AadeMyData\Enums\SignatureDuration;

/**
 * A POS signature the provider issued. Only the provider can sign a card payment on its own
 * channel, so this is the one value an ERP cannot compute for itself.
 *
 * Hand $signature back to the payment method it was issued for and the invoice carries it:
 * $payment->setProvidersSignature(null, $signature->signature) — the signing author is
 * stamped by the provider from its own ΥΠΑΗΕΣ registration.
 */
final class Signature
{
    private function __construct(
        /** The provider's id for this signature, used to read or cancel it. */
        public readonly string $id,
        /** The myDATA uid of the invoice it was signed for. */
        public readonly string $uid,
        public readonly ?string $mark,
        /** The provider's signed_text: what an invoice references. */
        public readonly string $signature,
        /** The same bytes in upper-case hex, for printing on the document. */
        public readonly string $signatureHex,
        public readonly string $terminalId,
        public readonly NSP $nsp,
        public readonly SignatureDuration $duration,
        public readonly float $paymentAmount,
        /** The instant the signature attests, and the one the invoice must carry. */
        public readonly DateTimeImmutable $invoiceIssuedAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly bool $expired,
        public readonly ?DateTimeImmutable $cancelledAt,
    ) {}

    /**
     * @internal Reads one provider record; null when it cannot be read, which the service
     *           turns into a SignatureException rather than a half-built signature.
     *
     * @param array<array-key, mixed> $row
     */
    public static function tryFrom(array $row): ?self
    {
        $id = $row['id'] ?? null;
        $uid = $row['uid'] ?? null;
        $signature = $row['signed_text'] ?? null;
        $hex = $row['signature_hex'] ?? null;
        $terminalId = $row['terminal_id'] ?? null;
        $amount = $row['payment_amount'] ?? null;
        $expired = $row['expired'] ?? null;
        $nsp = is_array($row['nsp'] ?? null) ? ($row['nsp']['value'] ?? null) : null;
        $duration = $row['duration'] ?? null;

        if (! is_string($id) || ! is_string($uid) || ! is_string($signature) || ! is_string($hex)
            || ! is_string($terminalId) || ! is_numeric($amount) || ! is_bool($expired)
            || ! is_int($nsp) || ! is_int($duration)) {
            return null;
        }

        $nspCase = NSP::tryFrom($nsp);
        $durationCase = SignatureDuration::tryFrom($duration);
        $issuedAt = self::instant($row['invoice_issued_at'] ?? null);
        $expiresAt = self::instant($row['expires_at'] ?? null);

        if ($nspCase === null || $durationCase === null || $issuedAt === null || $expiresAt === null) {
            return null;
        }

        $mark = $row['mark'] ?? null;

        return new self(
            id: $id,
            uid: $uid,
            mark: is_scalar($mark) ? (string) $mark : null,
            signature: $signature,
            signatureHex: $hex,
            terminalId: $terminalId,
            nsp: $nspCase,
            duration: $durationCase,
            paymentAmount: (float) $amount,
            invoiceIssuedAt: $issuedAt,
            expiresAt: $expiresAt,
            expired: $expired,
            cancelledAt: self::instant($row['cancelled_at'] ?? null),
        );
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
