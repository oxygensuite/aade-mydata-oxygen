<?php

namespace Tests\Fixtures;

use Firebed\AadeMyData\Enums\IncomeClassificationCategory;
use Firebed\AadeMyData\Enums\IncomeClassificationType;
use Firebed\AadeMyData\Enums\InvoiceType;
use Firebed\AadeMyData\Enums\MovePurpose;
use Firebed\AadeMyData\Enums\PaymentMethod;
use Firebed\AadeMyData\Enums\UnitMeasurement;
use Firebed\AadeMyData\Enums\VatCategory;
use Firebed\AadeMyData\Models\Address;
use Firebed\AadeMyData\Models\Counterpart;
use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\InvoiceDetails;
use Firebed\AadeMyData\Models\InvoiceHeader;
use Firebed\AadeMyData\Models\InvoiceSummary;
use Firebed\AadeMyData\Models\Issuer;
use Firebed\AadeMyData\Models\OtherDeliveryNoteHeader;
use Firebed\AadeMyData\Models\PaymentMethod as PaymentMethodEntry;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use Firebed\AadeMyData\Models\TransportDetail;

final class Invoices
{
    public static function b2b(string $issueDate = '2026-08-27', string $aa = '42'): Invoice
    {
        $invoice = new Invoice();
        $invoice->setIssuer((new Issuer())->setVatNumber('123456789')->setCountry('GR')->setBranch(0));
        $invoice->setCounterpart((new Counterpart())->setVatNumber('987654321')->setCountry('GR')->setBranch(0)->setName('Customer')
            ->setAddress((new Address())->setStreet('Ermou')->setNumber('1')->setPostalCode('10563')->setCity('Athens')));
        $invoice->setInvoiceHeader((new InvoiceHeader())->setSeries('A')->setAa($aa)->setIssueDate($issueDate)->setIssueTime('09:30:00')->setInvoiceType(InvoiceType::TYPE_1_1)->setCurrency('EUR'));
        $invoice->addPaymentMethod((new PaymentMethodDetail())->setType(PaymentMethod::METHOD_3)->setAmount(124.0));
        $invoice->addInvoiceDetails((new InvoiceDetails())->setLineNumber(1)->setItemDescr('Consulting')->setQuantity(2)->setMeasurementUnit(UnitMeasurement::UNIT_1)->setUnitPrice(50.0)
            ->setNetValue(100.0)->setVatCategory(VatCategory::VAT_1)->setVatAmount(24.0)
            ->addIncomeClassification(IncomeClassificationType::E3_561_001, IncomeClassificationCategory::CATEGORY_1_1, 100.0));
        $invoice->setInvoiceSummary((new InvoiceSummary())->setTotalNetValue(100.0)->setTotalVatAmount(24.0)->setTotalGrossValue(124.0)
            ->addIncomeClassification(IncomeClassificationType::E3_561_001, IncomeClassificationCategory::CATEGORY_1_1, 100.0));

        return $invoice;
    }

    public static function retail(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setIssuer((new Issuer())->setVatNumber('123456789')->setCountry('GR')->setBranch(1));
        $invoice->setInvoiceHeader((new InvoiceHeader())->setSeries('R')->setAa('9')->setIssueDate('2026-08-27')->setIssueTime('12:00:00')->setInvoiceType(InvoiceType::TYPE_11_1)->setCurrency('EUR'));
        $invoice->addPaymentMethod((new PaymentMethodDetail())->setType(PaymentMethod::METHOD_3)->setAmount(12.4));
        $invoice->addInvoiceDetails((new InvoiceDetails())->setLineNumber(1)->setItemDescr('Coffee')->setQuantity(1)->setUnitPrice(10.0)->setNetValue(10.0)->setVatCategory(VatCategory::VAT_1)->setVatAmount(2.4)
            ->addIncomeClassification(IncomeClassificationType::E3_561_003, IncomeClassificationCategory::CATEGORY_1_1, 10.0));
        $invoice->setInvoiceSummary((new InvoiceSummary())->setTotalNetValue(10.0)->setTotalVatAmount(2.4)->setTotalGrossValue(12.4)
            ->addIncomeClassification(IncomeClassificationType::E3_561_003, IncomeClassificationCategory::CATEGORY_1_1, 10.0));

        return $invoice;
    }

    /**
     * A retail invoice paid by card: the shape a POS signature is issued for. The issue time
     * is set so create() and the later send agree on one instant.
     */
    public static function pos(): Invoice
    {
        $invoice = self::retail();
        $invoice->getInvoiceHeader()->setIssueTime('10:15:00');
        $invoice->setPaymentMethods([self::posDetail()]);

        return $invoice;
    }

    /**
     * One PaymentMethodsDoc entry for the deferred flow: the card payment of an invoice the
     * provider already holds.
     */
    public static function posPayment(int $mark = 400001, ?string $signature = 'SIGNED'): PaymentMethodEntry
    {
        $detail = self::posDetail();

        if ($signature !== null) {
            $detail->setProvidersSignature(null, $signature);
        }

        return (new PaymentMethodEntry())->setInvoiceMark($mark)->addPaymentMethodDetails($detail);
    }

    private static function posDetail(): PaymentMethodDetail
    {
        return (new PaymentMethodDetail())->setType(PaymentMethod::METHOD_7)->setAmount(12.4)
            ->setTid('TID-1')->setTransactionId('TX-1');
    }
    public static function deliveryNote(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setIssuer((new Issuer())->setVatNumber('123456789')->setCountry('GR')->setBranch(0));
        $invoice->setCounterpart((new Counterpart())->setVatNumber('987654321')->setCountry('GR')->setBranch(0)->setName('Customer')
            ->setAddress((new Address())->setStreet('Ermou')->setNumber('1')->setPostalCode('10563')->setCity('Athens')));
        $header = (new InvoiceHeader())->setSeries('D')->setAa('3')->setIssueDate('2026-08-27')->setIssueTime('08:45:00')->setInvoiceType(InvoiceType::TYPE_9_3)
            ->setDispatchDate('2026-08-27')->setDispatchTime('09:00:00')->setMovePurpose(MovePurpose::TYPE_1)->setVehicleNumber('ABC1234')
            ->setOtherDeliveryNoteHeader((new OtherDeliveryNoteHeader())->setStartShippingBranch(0)->setCompleteShippingBranch(0)
                ->setLoadingAddress((new Address())->setStreet('Depot')->setNumber('5')->setPostalCode('11111')->setCity('Piraeus'))
                ->setDeliveryAddress((new Address())->setStreet('Ermou')->setNumber('1')->setPostalCode('10563')->setCity('Athens')));
        $invoice->setInvoiceHeader($header);
        $invoice->addOtherTransportDetail(new TransportDetail('XYZ9876'));
        $invoice->addInvoiceDetails((new InvoiceDetails())->setLineNumber(1)->setItemCode('P-1')->setItemDescr('Pallet')->setQuantity(4)->setMeasurementUnit(UnitMeasurement::UNIT_1)->setUnitPrice(0)
            ->setNetValue(0)->setVatCategory(VatCategory::VAT_8)->setVatAmount(0)
            ->addIncomeClassification(null, IncomeClassificationCategory::CATEGORY_3, 0));
        $invoice->setInvoiceSummary((new InvoiceSummary())->setTotalNetValue(0)->setTotalVatAmount(0)->setTotalGrossValue(0)
            ->addIncomeClassification(null, IncomeClassificationCategory::CATEGORY_3, 0));

        return $invoice;
    }
}
