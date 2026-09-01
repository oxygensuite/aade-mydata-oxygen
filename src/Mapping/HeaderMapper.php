<?php

namespace OxygenSuite\AadeMyData\Mapping;

use DateTimeInterface;
use Firebed\AadeMyData\Models\EntityType;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\OtherDeliveryNoteHeader;
use Firebed\AadeMyData\Models\Party;
use Firebed\AadeMyData\Models\TransportDetail;

final class HeaderMapper
{
    public function __construct(private PartyMapper $parties) {}

    /**
     * @return array<array-key, mixed>
     */
    public function header(InvoiceHeader $header): array
    {
        // Resolved once: IssuedAt::of() falls back to the current time, so a second call could differ.
        $issuedAt = IssuedAt::of($header);
        $dispatchedAt = IssuedAt::dispatch($header, $issuedAt);

        return Values::compact([
            'series' => $header->getSeries(),
            'number' => $header->getAa(),
            'issued_at' => $issuedAt?->format(DateTimeInterface::ATOM) ?? ($header->getIssueDate() ?: null),
            'invoice_type' => Values::scalar($header->getInvoiceType()),
            'vat_payment_suspension' => Values::flag($header->isVatPaymentSuspension()),
            'currency' => Values::scalar($header->getCurrency()),
            'exchange_rate' => $header->getExchangeRate(),
            'self_pricing' => Values::flag($header->isSelfPricing()),
            'is_delivery_note' => Values::flag($header->getIsDeliveryNote()),
            'dispatched_at' => $dispatchedAt?->format(DateTimeInterface::ATOM) ?? ($header->getDispatchDate() ?: null),
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
