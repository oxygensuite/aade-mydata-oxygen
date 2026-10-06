# Oxygen Provider bridge for `firebed/aade-mydata`

Routes `SendInvoices`, `CancelInvoice` and `SendPaymentsMethod` from
[`firebed/aade-mydata`](https://github.com/firebed/aade-mydata) through the Oxygen
e-invoicing provider, with no changes to the code that builds and sends your invoices.
Every other request keeps talking to ΑΑΔΕ directly.

## Requirements

- PHP ^8.2
- `firebed/aade-mydata` ^5.13
- A company API token from the Oxygen provider. For sandbox and production API keys,
  contact [contracts@oxygen.gr](mailto:contracts@oxygen.gr).

## Setup

```bash
composer require oxygensuite/aade-mydata-oxygen
```

```php
use Firebed\AadeMyData\Http\MyDataRequest;
use OxygenSuite\AadeMyData\OxygenProvider;

MyDataRequest::init($aadeUserId, $aadeSubscriptionKey, 'prod'); // still used by the requests that stay on ΑΑΔΕ

OxygenProvider::register(token: 'your-company-api-token');
```

The provider environment follows `MyDataRequest`'s: `prod` is the live API, anything else the
sandbox. Force one with `register(token: '…', env: 'dev')`; go back to ΑΑΔΕ with `unregister()`.
Your ΑΑΔΕ credentials are never sent to the provider.

## What is routed

| Package call | What happens |
|---|---|
| `SendInvoices::handle()` | each invoice is issued through the provider, in order |
| `CancelInvoice::handle($mark)` | the invoice with that mark is cancelled at the provider |
| `SendPaymentsMethod::handle()` | each `PaymentMethod` is recorded at the provider against its invoice mark |
| everything else | ΑΑΔΕ, unchanged |

Correlated and connected marks are sent as they are; the provider links its own documents and
forwards the rest to myDATA. Only 9.3 delivery notes and 10.1 / 10.2 receiving notes can be
cancelled; anything else needs a credit note.

## Results

You get the usual `ResponseDoc`, one `Response` per invoice, in order.

| Provider answer | `statusCode` | Notes |
|---|---|---|
| 201 | `Success` | `getInvoiceUid()`, `getInvoiceMark()`, `getAuthenticationCode()`, `getQrUrl()` |
| 202 | `Success` | issued and queued for retransmission: `getInvoiceMark()` is `null`, fetch it later |
| 422 / 403 / 404 | `ValidationError` | myDATA codes are relayed; provider messages carry the HTTP status as code |
| 423 / 429 / 5xx, unreachable | `TechnicalError` | code = HTTP status, `0` (connection) or `28` (timeout) |
| 401 | — | throws `MyDataAuthenticationException` |

Bridge codes: `9001` the provider does not hold the invoice to cancel or pay (retry once if it
was transmitted a second ago), `9002` unreadable provider response, `9003` the invoice has no
issue time. A batch never stops midway, and re-sending an invoice the provider already holds
returns its stored mark.

## What the provider needs that myDATA does not have

These stay on the models and never reach the myDATA XML:

```php
use Firebed\AadeMyData\Enums\DiscountType;

$invoice->getInvoiceHeader()->setIssueDate('2026-08-28')->setIssueTime('10:15:00'); // required

$line->setQuantity(2)->setUnitPrice(50.0)                     // required with the quantity
    ->setDiscount(DiscountType::PERCENTAGE, 10.0)             // or DiscountType::AMOUNT
    ->setNetValue(90.0);

$invoice->setExtraFields(['order' => 'SO-1']);                // also on lines and payment methods
```

Values are forwarded exactly as set; nothing is computed or filled in for you. The issue date
and time are read as Athens local time and sent with their offset; the provider accepts any
timezone, but the instant must not be in the future and must fall on today's date in Athens,
unless `transmissionFailure` is `ERP_CONNECTION_FAILURE`.

## POS payments

A card payment needs the provider's own signature. Ask for it, put it on the payment, send:

```php
use OxygenSuite\AadeMyData\Enums\NSP;
use OxygenSuite\AadeMyData\Enums\SignatureDuration;

$payment = PaymentMethodDetail::make()->setType(7)->setAmount(12.4)->setTid('TERM001')->setTransactionId('abc-123');
$invoice->addPaymentMethod($payment);

$signature = OxygenProvider::signatures()->create($invoice, $payment, NSP::VIVA, SignatureDuration::HOURS_60);
$payment->setProvidersSignature(null, $signature->signature);

(new SendInvoices())->handle($invoice);
```

To pay an invoice that was already transmitted, set its `mark` on the invoice before signing
and send the payment with `SendPaymentsMethod`. A signature is single use and cannot be
renewed; `signatures()->pending()` lists the unused ones, `find($id)` and `cancel($id)` manage
them. The provider refuses with `SignatureException` (`getCode()` is the HTTP status,
`$e->errors` the field messages).

## Limitations

- Not forwarded: `ProvidersSignature.SigningAuthor` and `tid` on the document (the provider
  fills them in), `ECRToken`, `EndToEndReferenceID`, `discountOption`, `invoiceVariationType`,
  `toWeigh` and `packingsDeclarations`.
- Documents transmitted through the ERP channel before the switch can be correlated but not
  cancelled or paid through the provider.

## Development

```bash
composer check   # Pint, PHPStan (level max) and PHPUnit
```
