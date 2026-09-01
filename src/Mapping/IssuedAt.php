<?php

namespace OxygenSuite\AadeMyData\Mapping;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Firebed\AadeMyData\Models\InvoiceHeader;
use OxygenSuite\AadeMyData\Exceptions\IssueTimeMissingException;

/**
 * The Athens instant a document was issued at.
 *
 * myDATA carries only a date, while both the invoice payload and a POS signature need a full
 * instant — and the two must be the same one, since a signature's uid is generated from it
 * and nothing on the provider validates the pair. One implementation, one value: the time
 * the ERP stated through InvoiceHeader::setIssueTime().
 */
final class IssuedAt
{
    public const TIMEZONE = 'Europe/Athens';

    /**
     * @throws IssueTimeMissingException when the header states a date but no time
     */
    public static function of(InvoiceHeader $header): ?DateTimeImmutable
    {
        $issueTime = $header->getIssueTime() ?: null;
        $issuedAt = self::athens($header->getIssueDate() ?: null, $issueTime);

        // A date on its own would have to be timed by the bridge, which is how a document
        // ends up transmitted — and signed — with a time it never carried.
        if ($issuedAt !== null && $issueTime === null) {
            throw new IssueTimeMissingException(self::document($header));
        }

        return $issuedAt;
    }

    /**
     * The instant in the provider's format, or the raw myDATA date when it cannot be parsed
     * so the provider's date_format error names the value instead of the bridge faulting.
     *
     * @throws IssueTimeMissingException
     */
    public static function atom(InvoiceHeader $header): ?string
    {
        return self::of($header)?->format(DateTimeInterface::ATOM) ?? ($header->getIssueDate() ?: null);
    }

    /**
     * myDATA's dispatchTime is optional; the provider wants a full instant, so a missing
     * time borrows the issue time the ERP stated.
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
     * Series and number name the offending document in a batch, when the ERP set them.
     */
    private static function document(InvoiceHeader $header): ?string
    {
        $parts = array_filter([$header->getSeries(), $header->getAa()], fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode('-', $parts);
    }
}
