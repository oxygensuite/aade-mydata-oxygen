<?php

namespace OxygenSuite\AadeMyData\Api;

/**
 * An HTTP response from the provider API: status code plus the decoded JSON body (when it is JSON).
 */
final class ProviderResponse
{
    /** @var array<array-key, mixed>|null */
    public readonly ?array $body;

    public function __construct(public readonly int $status, public readonly string $rawBody)
    {
        $decoded = json_decode($rawBody, true);
        $this->body = is_array($decoded) ? $decoded : null;
    }

    public function isJson(): bool
    {
        return $this->body !== null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function message(): ?string
    {
        $message = $this->get('message');

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * Laravel-style validation errors: field (or relayed myDATA code) => messages.
     *
     * @return array<array-key, list<string>>
     */
    public function errors(): array
    {
        $errors = $this->get('errors');

        if (! is_array($errors)) {
            return [];
        }

        $normalized = [];

        foreach ($errors as $field => $messages) {
            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                if (is_scalar($message)) {
                    $normalized[$field][] = (string) $message;
                }
            }
        }

        return $normalized;
    }

    /**
     * A short printable form of the body for an error message, or the bare status when the
     * body is empty.
     */
    public function excerpt(int $limit = 500): string
    {
        $body = trim($this->rawBody);

        return $body === '' ? sprintf('HTTP %d', $this->status) : mb_substr($body, 0, $limit);
    }
    /**
     * The id (ulid) of the first record in a paginated index response.
     */
    public function firstId(): ?string
    {
        $data = $this->body['data'] ?? null;
        $first = is_array($data) ? ($data[0] ?? null) : null;
        $id = is_array($first) ? ($first['id'] ?? null) : null;

        return is_string($id) ? $id : null;
    }
}
