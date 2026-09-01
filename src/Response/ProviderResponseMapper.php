<?php

namespace OxygenSuite\AadeMyData\Response;

use Firebed\AadeMyData\Models\Error;
use Firebed\AadeMyData\Models\Errors;
use Firebed\AadeMyData\Models\Response;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Api\ProviderResponse;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;
use OxygenSuite\AadeMyData\Exceptions\MarkNotFoundException;

/**
 * Translates provider outcomes into the package's Response model (see spec §7).
 */
final class ProviderResponseMapper
{
    /** Bridge-originated: a referenced mark is unknown to the provider. */
    public const CODE_MARK_NOT_FOUND = '9001';

    /** Bridge-originated: the provider answered 2xx with a body that is not JSON. */
    public const CODE_UNREADABLE_RESPONSE = '9002';

    private const SUCCESS = 'Success';
    private const VALIDATION_ERROR = 'ValidationError';
    private const TECHNICAL_ERROR = 'TechnicalError';

    public function forStore(ProviderResponse $response): Response
    {
        if ($response->status !== 201 && $response->status !== 202) {
            return $this->failure($response);
        }

        if (! $response->isJson()) {
            return $this->unreadable($response);
        }

        // 202 = accepted and queued for retransmission: legally issued, no mark yet.
        return $this->invoice($response, withMark: $response->status === 201);
    }

    /**
     * From GET /invoices/{id}: the record of an invoice the provider already holds,
     * standing in for a duplicate-uid rejection. Falls back to the rejection itself
     * when the record cannot be read.
     */
    public function forStoredInvoice(ProviderResponse $stored, ProviderResponse $rejection): Response
    {
        return $stored->isSuccessful() && $stored->isJson()
            ? $this->invoice($stored, withMark: true)
            : $this->forStore($rejection);
    }

    public function forCancel(ProviderResponse $response): Response
    {
        if (! $response->isSuccessful()) {
            return $this->failure($response);
        }

        if (! $response->isJson()) {
            return $this->unreadable($response);
        }

        return $this->success(['cancellationMark' => $response->get('cancellation_mark')]);
    }

    /**
     * From POST /invoices/{id}/payments. A 202 means the payment is stored but its myDATA
     * transmission is queued, so it answers with a null payment mark — the caller detects
     * the deferred case exactly as it does for a 202 invoice.
     */
    public function forPayments(ProviderResponse $response): Response
    {
        if ($response->status !== 201 && $response->status !== 202) {
            return $this->failure($response);
        }

        if (! $response->isJson()) {
            return $this->unreadable($response);
        }

        return $this->success([
            'invoiceMark' => $response->get('invoice_mark'),
            'paymentMethodMark' => $response->get('payment_method_mark'),
        ]);
    }

    public function forTransportFailure(ProviderException $exception): Response
    {
        return $this->error(self::TECHNICAL_ERROR, [[$exception->getCode(), $exception->getMessage()]]);
    }

    public function forMarkNotFound(MarkNotFoundException $exception): Response
    {
        return $this->error(self::VALIDATION_ERROR, [[self::CODE_MARK_NOT_FOUND, $exception->getMessage()]]);
    }

    /**
     * A token rejected part-way through a batch. The invoices already registered keep
     * their marks, so the rest of the batch is reported per invoice instead of aborting.
     */
    public function forUnauthorized(UnauthorizedException $exception): Response
    {
        return $this->error(self::TECHNICAL_ERROR, [[401, $exception->getMessage()]]);
    }

    public function forMissingMark(string $message): Response
    {
        return $this->error(self::VALIDATION_ERROR, [[self::CODE_MARK_NOT_FOUND, $message]]);
    }

    /**
     * The provider computes the uid itself, so a 422 keyed by `uid` means it already
     * holds this invoice and quotes the stored uid in the message. Null when the
     * rejection is about anything else.
     */
    public function duplicateUid(ProviderResponse $response): ?string
    {
        if ($response->status !== 422) {
            return null;
        }

        foreach ((array) ($response->errors()['uid'] ?? []) as $message) {
            if (preg_match('/\b([0-9A-Fa-f]{40})\b/', (string) $message, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function invoice(ProviderResponse $response, bool $withMark): Response
    {
        return $this->success([
            'invoiceUid' => $response->get('uid'),
            'invoiceMark' => $withMark ? $response->get('mark') : null,
            'authenticationCode' => $response->get('authentication_code'),
            'qrUrl' => $response->get('url'),
        ]);
    }

    private function failure(ProviderResponse $response): Response
    {
        $message = $response->message() ?? $response->excerpt();

        return match (true) {
            $response->status === 422 => $this->error(self::VALIDATION_ERROR, $this->validationErrors($response, $message)),
            $response->status === 403, $response->status === 404 => $this->error(self::VALIDATION_ERROR, [[$response->status, $message]]),
            default => $this->error(self::TECHNICAL_ERROR, [[$response->status, $message]]),
        };
    }

    /**
     * Laravel groups messages by field; mydataprovider relays myDATA rejections grouped
     * by their numeric myDATA code. Numeric keys are forwarded as the error code.
     *
     * @return array<int, array{0: string|int, 1: string}> [code, message] pairs
     */
    private function validationErrors(ProviderResponse $response, string $fallback): array
    {
        $errors = [];

        foreach ($response->errors() as $key => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = is_numeric($key) ? [(string) $key, (string) $message] : [422, "$key: $message"];
            }
        }

        return $errors === [] ? [[422, $fallback]] : $errors;
    }

    private function unreadable(ProviderResponse $response): Response
    {
        return $this->error(self::TECHNICAL_ERROR, [
            [self::CODE_UNREADABLE_RESPONSE, 'The provider returned an unreadable response: '.$response->excerpt()],
        ]);
    }

    /**
     * @param array<string, mixed> $fields held as strings, exactly as a parsed AADE ResponseDoc would
     */
    private function success(array $fields): Response
    {
        $strings = array_map(fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null, $fields);

        return $this->response($strings + ['statusCode' => self::SUCCESS]);
    }

    /**
     * @param array<int, array{0: string|int, 1: string}> $errors [code, message] pairs
     */
    private function error(string $statusCode, array $errors): Response
    {
        $list = new Errors();

        foreach ($errors as [$code, $message]) {
            $list->add(Error::make(['message' => $message, 'code' => (string) $code]));
        }

        return $this->response(['statusCode' => $statusCode, 'errors' => $list]);
    }

    /**
     * The index slot is created first so that Response::setIndex(), called once the
     * position in the batch is known, keeps AADE's element order in the serialized XML.
     *
     * @param array<string, mixed> $attributes
     */
    private function response(array $attributes): Response
    {
        return Response::make(['index' => null] + $attributes);
    }
}
