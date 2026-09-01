<?php

namespace OxygenSuite\AadeMyData\Mapping;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Firebed\AadeMyData\Models\InvoiceHeader;

/**
 * The Athens instant a document was issued at.
 *
 * myDATA carries only a date; both the invoice payload and a POS signature need a full
 * instant, and the two must agree — a signature's uid is generated from the same issue
 * instant as the invoice's, and nothing on the provider validates the pair, so a drift
 * would be silent. One implementation, used by HeaderMapper and SignatureMapper alike.
 */
final class IssuedAt
{
    public const TIMEZONE = 'Europe/Athens';

    /**
     * The ERP supplies the time through InvoiceHeader::setIssueTime() (kept off the myDATA
     * XML). Without it, today's documents are stamped with the current Athens time — capped
     * at an earlier dispatch time today, since the provider requires dispatched_at >=
     * issued_at — and any other date is sent as Athens midnight, which the provider accepts
     * only with transmissionFailure = 1.
     */
    public static function of(InvoiceHeader $header): ?DateTimeImmutable
    {
        $issueTime = $header->getIssueTime() ?: null;
        $issuedAt = self::athens($header->getIssueDate() ?: null, $issueTime);

        if ($issueTime !== null || $issuedAt === null) {
            return $issuedAt;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));

        if ($issuedAt->format('Y-m-d') !== $now->format('Y-m-d')) {
            return $issuedAt;
        }

        $dispatchedAt = self::dispatchedAt($header);

        return $dispatchedAt !== null && $dispatchedAt < $now && $dispatchedAt->format('Y-m-d') === $now->format('Y-m-d') ? $dispatchedAt : $now;
    }

    /**
     * The instant in the provider's format, or the raw myDATA date when it cannot be parsed
     * so the provider's date_format error names the value instead of the bridge faulting.
     */
    public static function atom(InvoiceHeader $header): ?string
    {
        return self::of($header)?->format(DateTimeInterface::ATOM) ?? ($header->getIssueDate() ?: null);
    }

    /**
     * myDATA's dispatchTime is optional; the provider wants a full instant, so a missing
     * time borrows the issue time.
     */
    public static function dispatch(InvoiceHeader $header, ?DateTimeImmutable $issuedAt): ?DateTimeImmutable
    {
        return self::athens($header->getDispatchDate() ?: null, ($header->getDispatchTime() ?: null) ?? $issuedAt?->format('H:i:s'));
    }

    /**
     * Reads a myDATA date plus optional time as Europe/Athens. Null when the date is missing
     * or does not parse, so the caller forwards the raw value and the provider's validation
     * reports it instead of the bridge faulting.
     */
    public static function athens(?string $date, ?string $time = null): ?DateTimeImmutable
    {
        if ($date === null) {
            return null;
        }

        try {
            return new DateTimeImmutable(trim("$date $time"), new DateTimeZone(self::TIMEZONE));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * The dispatch instant as the ERP stated it, used only to cap an unstated issue time.
     * A missing dispatchTime must not borrow the issue time here, or the cap would compare
     * the issue time with itself.
     */
    private static function dispatchedAt(InvoiceHeader $header): ?DateTimeImmutable
    {
        $dispatchTime = $header->getDispatchTime() ?: null;

        return $dispatchTime === null ? null : self::athens($header->getDispatchDate() ?: null, $dispatchTime);
    }
}
