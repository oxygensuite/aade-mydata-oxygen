# Oxygen Provider bridge for `firebed/aade-mydata`

Routes `SendInvoices` and `CancelInvoice` from [`firebed/aade-mydata`](https://github.com/firebed/aade-mydata)
through the **Oxygen e-invoicing provider** (mydataprovider v2 API) — with no changes to the
code that builds and sends your invoices. Every other package request (RequestDocs,
RequestTransmittedDocs, classifications, payment methods, …) keeps talking to AADE directly.

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

Bridge-specific codes: `9001` — a referenced mark is unknown to the provider (e.g. an
invoice transmitted through the ERP channel before you switched); `9002` — the provider
returned an unreadable response.

A batch never stops midway: a failing invoice becomes a `TechnicalError` entry and the
remaining invoices are still sent. Re-sending an invoice the provider already holds
(e.g. after a lost response) returns `Success` with the stored mark instead of a
"uid already submitted" rejection.

## Cancellation

The provider can only cancel **9.3 delivery notes** (`PATCH /invoices/{id}/cancel`).
`CancelInvoice::handle($mark)` for any other invoice type returns a `ValidationError`
carrying the provider's message — issue a credit note instead. `entityVatNumber` is ignored;
the token identifies the company.

## Issue time, `issueDate` and `transmissionFailure`

myDATA carries only an issue **date**; the provider requires an issue **datetime**. Give it
the time your ERP printed on the document:

```php
$invoice->getInvoiceHeader()->setIssueDate('2026-08-28')->setIssueTime('10:15:00');
```

`setIssueTime()` (aade-mydata ≥ 5.11) stays on the model — `toArray()`, `make()` — but is
never written to the myDATA XML, so nothing changes for the ERP channel. Without it the
bridge stamps today's invoices with the current Athens time (capped at an earlier dispatch
time, since the provider requires `dispatched_at >= issued_at`) and sends any other date as
Athens midnight. A delivery note's `dispatchTime` is kept; when it is missing, the issue
time is used.

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

## Limitations

- POS payments: `tid`, `ProvidersSignature`, `ECRToken` are not forwarded — register
  signatures through the provider's `/signatures` API.
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
carries the `Gateway` seam, `SendInvoices::getInvoicesDoc()`, `InvoiceHeader::setIssueTime()`
and `InvoiceDetails::setUnitPrice()`. Replace the constraint with `^5.11` and drop the
`repositories` entry once the tag is out; the bridge cannot be published to Packagist while
it requires a branch.
