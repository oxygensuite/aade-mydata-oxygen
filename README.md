# Oxygen Provider bridge for `firebed/aade-mydata`

Routes `SendInvoices`, `CancelInvoice` and `SendPaymentsMethod` from
[`firebed/aade-mydata`](https://github.com/firebed/aade-mydata) through the **Oxygen
e-invoicing provider** (mydataprovider v2 API) — with no changes to the code that builds
and sends your invoices. Every other package request (RequestDocs, RequestTransmittedDocs,
classifications, …) keeps talking to AADE directly.

ΑΑΔΕ is retiring the ERP transmission channel: invoices must be transmitted through a
licensed provider. Install this package, register your provider token, and your existing
`new SendInvoices()->handle($invoices)` calls keep working.

## Requirements

- PHP ^8.2 (the lowest branch still receiving security fixes; 8.1 reached EOL on 31 Dec 2025)
- `firebed/aade-mydata` with the `Gateway` seam this bridge plugs into — until it is tagged
  as 5.11.0, the `feature/gateway` branch (see [Development](#development))
- A company API token from the Oxygen provider

## Installation

```bash
composer require oxygensuite/aade-mydata-oxygen
```

## Setup

```php
use Firebed\AadeMyData\Http\MyDataRequest;
use OxygenSuite\AadeMyData\OxygenProvider;

// Your existing AADE setup — still needed for the requests that stay on the ERP channel.
MyDataRequest::init($aadeUserId, $aadeSubscriptionKey, 'prod');

// One line: from now on SendInvoices / CancelInvoice go through the provider.
OxygenProvider::register(token: 'your-company-api-token');
```

- The provider environment follows `MyDataRequest::setEnvironment()`: `prod` →
  `https://api.mydataprovider.gr/v2`, anything else → `https://sandbox-api.mydataprovider.gr/v2`.
  It is resolved per request, so the order of `init()` and `register()` does not matter.
- Force an environment: `OxygenProvider::register(token: '…', env: 'dev')`.
- The provider connection has its own timeouts (connect 5s, request 10s); `MyDataRequest`'s
  Guzzle settings keep governing the AADE leg.
- Back to AADE: `OxygenProvider::unregister()`.
- Every provider request carries `X-Client: oxygensuite/aade-mydata-oxygen/<version>`, so the
  provider's logs tell bridge traffic apart from a direct API integration and support can see
  which release built a payload. The version comes from Composer's installed metadata.

Your AADE credentials are never sent to the provider.

## What is routed

| Package call | Provider request(s) |
|---|---|
| `SendInvoices::handle()` | one `POST /v2/invoices` **per invoice**, in order (or `POST /v2/invoices/cancel` for an 8.6 total cancellation with `totalCancelDeliveryOrders`) |
| `CancelInvoice::handle($mark)` | `GET /v2/invoices?mark=…` then `PATCH /v2/invoices/{id}/cancel` |
| `SendPaymentsMethod::handle()` | `GET /v2/invoices?mark=…` then one `POST /v2/invoices/{id}/payments` **per `PaymentMethod`**, in order |
| everything else | AADE myDATA, unchanged |

`correlatedInvoices` / `multipleConnectedMarks` are looked up in the provider
(`GET /v2/invoices?mark=…`) and sent as provider document ids.

The bridge works on your `Invoice` models, not on the XML: a squashed invoice
(`squashInvoiceRows()`) is sent with its original detailed rows — the provider squashes
rows itself — and your object is left exactly as you handed it over.

## Reading the results

You still get a `ResponseDoc` with one `Response` per invoice, in submission order.
`getRequestXml()` returns the `InvoicesDoc` the package built; `getResponseXML()` the
`ResponseDoc` synthesized from the provider's answers.

| Provider answer | `statusCode` | What you get |
|---|---|---|
| 201 Created | `Success` | `getInvoiceUid()`, `getInvoiceMark()`, `getAuthenticationCode()`, `getQrUrl()` (the provider's invoice URL) |
| 202 Accepted (queued for retransmission) | `Success` | same, but **`getInvoiceMark()` is `null`** — the invoice is legally issued; fetch the mark later |
| 422 validation | `ValidationError` | one error per message: relayed myDATA codes keep their numeric code; provider field errors have code `422` and message `field: message` |
| 403 / 404 | `ValidationError` | code = HTTP status, message from the provider |
| 423 / 429 / 5xx | `TechnicalError` | code = HTTP status |
| unreachable / timeout | `TechnicalError` | code `0` (connection) or `28` (timeout) |
| 401 | — | throws `MyDataAuthenticationException` |

`SendPaymentsMethod` answers the same way: `201` → `Success` with `getInvoiceMark()` and
`getPaymentMethodMark()`; `202` → `Success` with `getPaymentMethodMark()` **null**, the
payment is stored and its myDATA transmission is queued; `422` → `ValidationError`; `503` →
`TechnicalError`.

Bridge-specific codes: `9001` — a referenced mark is unknown to the provider (e.g. an
invoice transmitted through the ERP channel before you switched); `9002` — the provider
returned an unreadable response; `9003` — the invoice states an issue date but no issue time.

A batch never stops midway: a failing invoice becomes a `TechnicalError` entry and the
remaining invoices are still sent. Re-sending an invoice the provider already holds
(e.g. after a lost response) returns `Success` with the stored mark instead of a
"uid already submitted" rejection.

## Cancellation

The provider can only cancel **9.3 delivery notes** (`PATCH /invoices/{id}/cancel`).
`CancelInvoice::handle($mark)` for any other invoice type returns a `ValidationError`
carrying the provider's message — issue a credit note instead. `entityVatNumber` is ignored;
the token identifies the company.

## Issue time (required), `issueDate` and `transmissionFailure`

myDATA carries only an issue **date**; the provider records an issue **datetime**, and a POS
signature attests one. That time is yours to state — **`setIssueTime()` is required** for
every invoice that goes through the provider:

```php
$invoice->getInvoiceHeader()->setIssueDate('2026-08-28')->setIssueTime('10:15:00');
```

`setIssueTime()` (aade-mydata ≥ 5.11) stays on the model — `toArray()`, `make()` — but is
never written to the myDATA XML, so nothing changes for the ERP channel.

An invoice with a date but no time comes back as a `ValidationError` with code **`9003`**,
naming the document, and is not transmitted; the rest of the batch still is. The bridge used
to fill the gap itself — the current time for today, Athens midnight for any other date —
which meant transmitting, and signing, a time the document never carried.

A delivery note's `dispatchTime` is kept; when it is missing, it borrows the issue time you
stated.

The provider rejects any date other than the current Athens day unless
`transmissionFailure` is `TransmissionFailure::ERP_CONNECTION_FAILURE` (value 1: you could
not reach the provider when the invoice was issued).

## Unit price

myDATA carries only a line's `netValue`; the provider prices every line and re-checks
`net_amount = ROUND(quantity × unit_price − discount, 2)`. Give it the price your ERP printed
on the document:

```php
$line->setQuantity(3)->setUnitPrice(3.333333)->setNetValue(10.0);
```

`setUnitPrice()` (aade-mydata ≥ 5.11) behaves like `setIssueTime()`: it stays on the model and
is never written to the myDATA XML.

The bridge sends `quantity` and `unit_price` exactly as the model holds them. It does not
divide the net value by the quantity, and a line without a quantity is not priced as a single
unit — the provider requires both fields, so an incomplete line comes back as a
`ValidationError` naming it rather than being transmitted with a price the ERP never issued.
The same applies to the values the provider fills in itself: a missing `series`, a missing
issuer `branch_code` and every line's `total_amount` are left out of the payload.

## POS payments (provider signature)

A card payment transmitted through a provider must carry the **provider's** signature —
signed with the provider's own ΥΠΑΗΕΣ key, which is why an ERP cannot compute it and why
`ECRToken`, the ΦΗΜ/ERP-channel equivalent, no longer applies. Ask the provider for one, then
hand it to the payment method:

```php
use Firebed\AadeMyData\Enums\PaymentMethod as PaymentType;
use Firebed\AadeMyData\Models\PaymentMethod;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use OxygenSuite\AadeMyData\Enums\NSP;
use OxygenSuite\AadeMyData\Enums\SignatureDuration;
use OxygenSuite\AadeMyData\OxygenProvider;

$payment = PaymentMethodDetail::make()
    ->setType(PaymentType::METHOD_7)     // 7 = POS, 8 = IRIS
    ->setAmount(12.4)
    ->setTid('TERM001')                  // the POS terminal id — required, set it before signing
    ->setTransactionId('abc-123');

$invoice->addPaymentMethod($payment);

$signature = OxygenProvider::signatures()->create($invoice, $payment, NSP::VIVA, SignatureDuration::HOURS_60);

$payment->setProvidersSignature(null, $signature->signature);

(new SendInvoices())->handle($invoice);
```

The signing author is left `null` on purpose: the provider stamps its own ΥΠΑΗΕΣ decision
number when it builds the myDATA XML. The `tid` is not sent on the document either — the
provider reads the terminal id back off the signature — so it travels exactly once, at
signing time.

`NSP` names the network the payment actually went through (`VIVA`, `WEB_ECR`, `WORLDLINE`,
`EDPS`, `EPAY_SOFT_POS`); it selects how the provider assembles the text it signs.
`SignatureDuration` is how long the signature stays usable — `HOURS_60` or `HOURS_2`.
Everything else in the request is read off the models you already built: the issuer, the
header, the invoice totals, the payment's amount and its `tid`.

The signature attests an issue instant and its uid is generated from it, so `create()` and
the later `handle()` must agree on one. That is why the issue time is required rather than
filled in: `create()` throws `IssueTimeMissingException` for an invoice that has none, the
same refusal `SendInvoices` reports as `9003`. The returned signature exposes the instant it
attests as `$signature->invoiceIssuedAt`, so you can check it against the document you printed.

### Paying an invoice that was already transmitted

`SendPaymentsMethod` now goes through the provider too. The provider requires the signature's
mark to match the invoice's, so put the mark on the invoice before signing:

```php
$mark = (new SendInvoices())->handle($invoice)->first()->getInvoiceMark();
$invoice->set('mark', $mark);

$signature = OxygenProvider::signatures()->create($invoice, $payment, NSP::VIVA, SignatureDuration::HOURS_60);
$payment->setProvidersSignature(null, $signature->signature);

(new SendPaymentsMethod())->handle(
    PaymentMethod::make()->setInvoiceMark((int) $mark)->addPaymentMethodDetails($payment)
);
```

Signing a document this process did not build needs only the fields the payload is made of:
the issuer (`vatNumber`, `branch`), the header (`series`, `aa`, `issueDate`, `issueTime`,
`invoiceType`), the summary totals, and the `mark`.

`entityVatNumber` is used when you set it; otherwise the bridge asks the provider which
company the token belongs to (`GET /v2/company`, once per registration). Note the deferred
endpoint is stricter than `POST /invoices`: every amount must be at least `0.01`, payment type
5 is refused, and `signature` and `transaction_id` are required on types 7 and 8 and
prohibited on every other.

### Managing signatures

```php
$signatures = OxygenProvider::signatures();

$signature = $signatures->find($id);      // by the id the provider gave it
$signatures->cancel($id);                 // release one that will not be used
$signatures->pending();                   // unexpired and not yet used, newest first, 100 per page
```

A signature is **single use** — the first invoice or payment that references it burns it — and
it **cannot be renewed**, only replaced, so transmit promptly and prefer `HOURS_60` when a
queue sits between issuing and sending. `pending()` is the recovery tool: `create()` has no
idempotency, so after a lost answer look there for the signature that may already exist
instead of creating a second one. A duplicate carries the same `uid`, since the uid is
generated from the same invoice fields; cancel the one you do not use.

| Outcome | What you get |
|---|---|
| the provider refused | `SignatureException` — `getCode()` is the HTTP status, `$e->errors` the field messages (in Greek) |
| your issuer VAT is not the token's company | `SignatureException` with code **403** and no field information: the provider authorises before it validates |
| the signature is expired, used or unknown | a `ValidationError` on the invoice or payment naming `signature` |
| the provider could not be reached | `ProviderException` — the signature may or may not exist, so look it up rather than retrying |
| the token was rejected | `MyDataAuthenticationException`, as everywhere else |

## Limitations

- POS payments: the provider fills in `ProvidersSignature.SigningAuthor` itself and reads
  the `tid` off the signature, so neither is forwarded on the document. `ECRToken` (the ERP
  channel's own signature) and `ProvidersSignature.EndToEndReferenceID` (the IRIS request
  reference) have no provider equivalent and are dropped.
- Fields with no provider equivalent are dropped: `invoiceVariationType`, `discountOption`,
  myDATA v2.0.2 header fields (`toWeigh`, `receivingNotePurpose`, …), Digital Goods Movement
  fields, and service-filled fields (`uid`, `mark`, `qrCodeUrl`, …).
- Documents transmitted through the ERP channel before the switch cannot be correlated
  (`9001`).

## Development

```bash
composer install
composer test        # PHPUnit
composer lint        # Pint, formats src and tests
composer stan        # PHPStan, level max over src
composer check       # all three, as CI runs them
```

`composer.json` pins `config.platform.php` to 8.2.0 so the toolchain always resolves against
the oldest PHP the package supports.

Until `firebed/aade-mydata` 5.11.0 is tagged, `composer.json` requires
`dev-feature/gateway` through a `vcs` repository on
[github.com/firebed/aade-mydata](https://github.com/firebed/aade-mydata) — the branch that
carries the `Gateway` seam, `SendInvoices::getInvoicesDoc()`, `InvoiceHeader::setIssueTime()`,
`InvoiceDetails::setUnitPrice()` and `SendPaymentsMethod::getPaymentMethodsDoc()`. Replace the constraint with `^5.11` and drop the
`repositories` entry once the tag is out; the bridge cannot be published to Packagist while
it requires a branch.
