<?php

namespace OxygenSuite\AadeMyData\Mapping;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Firebed\AadeMyData\Models\EntityType;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\OtherDeliveryNoteHeader;
use Firebed\AadeMyData\Models\Party;
use Firebed\AadeMyData\Models\TransportDetail;

final class HeaderMapper
{
    public const TIMEZONE = 'Europe/Athens';

    public function __construct(private PartyMapper $parties) {}

    /**
     * @return array<array-key, mixed>
     */
    public function header(InvoiceHeader $header): array
    {
        $issueDate = $header->getIssueDate() ?: null;
        $issueTime = $header->getIssueTime() ?: null;
        $dispatchDate = $header->getDispatchDate() ?: null;
        $dispatchTime = $header->getDispatchTime() ?: null;

        $issuedAt = $this->issuedAt($issueDate, $issueTime, $dispatchTime === null ? null : $this->athens($dispatchDate, $dispatchTime));
        // myDATA's dispatchTime is optional; the provider wants a full instant, so a missing time borrows the issue time.
        $dispatchedAt = $this->athens($dispatchDate, $dispatchTime ?? $issuedAt?->format('H:i:s'));

        return Values::compact([
            'series' => $header->getSeries(),
            'number' => $header->getAa(),
            'issued_at' => $issuedAt?->format(DateTimeInterface::ATOM) ?? $issueDate,
            'invoice_type' => Values::scalar($header->getInvoiceType()),
            'vat_payment_suspension' => Values::flag($header->isVatPaymentSuspension()),
            'currency' => Values::scalar($header->getCurrency()),
            'exchange_rate' => $header->getExchangeRate(),
            'self_pricing' => Values::flag($header->isSelfPricing()),
            'is_delivery_note' => Values::flag($header->getIsDeliveryNote()),
            'dispatched_at' => $dispatchedAt?->format(DateTimeInterface::ATOM) ?? $dispatchDate,
            'vehicle_number' => $header->getVehicleNumber(),
            'move_purpose' => Values::scalar($header->getMovePurpose()),
            'other_move_purpose_title' => $header->getOtherMovePurposeTitle(),
            'is_fuel_invoice' => Values::flag($header->isFuelInvoice()),
            'special_invoice_category' => Values::scalar($header->getSpecialInvoiceCategory()),
            'is_third_party_collection' => Values::flag($header->getThirdPartyCollection()),
            'table_number' => $header->getTableAA(),
            'is_reverse_delivery_note' => Values::flag($header->getReverseDeliveryNote()),
            'reverse_delivery_note_purpose' => Values::scalar($header->getReverseDeliveryNotePurpose()),
            'cancels_delivery_orders' => Values::flag($header->getTotalCancelDeliveryOrders()),
        ]);
    }

    /**
     * myDATA has an issue date only; the provider wants an instant. The ERP supplies the time
     * through InvoiceHeader::setIssueTime() (kept off the myDATA XML). Without it, today's
     * invoices are stamped with the current Athens time — capped at an earlier dispatch time
     * today, since the provider requires dispatched_at >= issued_at — and any other date is
     * sent as Athens midnight, which the provider accepts only with transmissionFailure = 1.
     */
    private function issuedAt(?string $issueDate, ?string $issueTime, ?DateTimeImmutable $dispatchedAt): ?DateTimeImmutable
    {
        $issuedAt = $this->athens($issueDate, $issueTime);

        if ($issueTime !== null || $issuedAt === null) {
            return $issuedAt;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));

        if ($issuedAt->format('Y-m-d') !== $now->format('Y-m-d')) {
            return $issuedAt;
        }

        return $dispatchedAt !== null && $dispatchedAt < $now && $dispatchedAt->format('Y-m-d') === $now->format('Y-m-d') ? $dispatchedAt : $now;
    }

    /**
     * Reads a myDATA date plus optional time as Europe/Athens. Null when the date is missing
     * or does not parse, so the caller forwards the raw value and the provider's validation
     * reports it instead of the bridge faulting.
     */
    private function athens(?string $date, ?string $time = null): ?DateTimeImmutable
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
     * @return array<array-key, mixed>
     */
    public function shippingDetails(?OtherDeliveryNoteHeader $details): array
    {
        if ($details === null) {
            return [];
        }

        return Values::compact([
            'pickup_branch_code' => $details->getStartShippingBranch(),
            'delivery_branch_code' => $details->getCompleteShippingBranch(),
            'pickup_address' => $this->parties->address($details->getLoadingAddress()),
            'delivery_address' => $this->parties->address($details->getDeliveryAddress()),
        ]);
    }

    /**
     * @param EntityType[]|null $entities
     *
     * @return array<array-key, mixed>
     */
    public function correlatedEntities(?array $entities): array
    {
        $result = [];

        foreach ($entities ?? [] as $entity) {
            // Raw attribute: the typed getter is non-nullable and faults when the party is unset.
            $party = $entity->get('entityData');

            $result[] = Values::compact([
                'type' => Values::scalar($entity->getType()),
                'party' => $this->parties->party($party instanceof Party ? $party : null),
            ]);
        }

        return Values::compact($result);
    }

    /**
     * @param TransportDetail[]|null $details
     *
     * @return array<array-key, mixed>
     */
    public function vehicles(?array $details): array
    {
        $vehicles = [];

        foreach ($details ?? [] as $detail) {
            // Raw attribute: the typed getter is non-nullable and faults when unset.
            $vehicle = $detail->get('vehicleNumber');

            if (is_string($vehicle) && $vehicle !== '') {
                $vehicles[] = $vehicle;
            }
        }

        return $vehicles;
    }
}
