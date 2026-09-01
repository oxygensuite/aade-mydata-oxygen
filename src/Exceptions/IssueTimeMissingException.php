<?php

namespace OxygenSuite\AadeMyData\Exceptions;

use RuntimeException;

/**
 * The ERP stated an issue date but not the time of day.
 *
 * myDATA carries only a date, while the provider records an instant — and a POS signature
 * attests one. The bridge used to fill the gap with the current time (or midnight for an
 * older date), which meant transmitting, and signing, a time the document never carried.
 * It is refused instead: the value is the ERP's to state.
 *
 * On SendInvoices it becomes a per-invoice ValidationError 9003, so the rest of the batch is
 * still sent.
 */
final class IssueTimeMissingException extends RuntimeException
{
    public function __construct(public readonly ?string $document = null)
    {
        parent::__construct(sprintf(
            "%s has an issue date but no issue time: call InvoiceHeader::setIssueTime('hh:mm:ss') with the time the document was issued.",
            $document === null ? 'The invoice' : 'Invoice '.$document,
        ));
    }
}
