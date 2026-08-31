<?php

namespace OxygenSuite\AadeMyData\Mapping;

use OxygenSuite\AadeMyData\Api\ProviderClient;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Exceptions\MarkNotFoundException;

/**
 * Translates myDATA marks (what the package carries) into provider invoice ids (what
 * correlated_documents / connected_documents require). Lookups are memoized per instance.
 */
final class DocumentResolver
{
    /** @var array<string, string> */
    private array $resolved = [];

    public function __construct(private ProviderClient $client) {}

    /**
     * @param array<array-key, mixed>|null $marks myDATA marks; non-scalar entries cannot name a document and are skipped
     *
     * @return list<string>
     * @throws MarkNotFoundException|ProviderException
     */
    public function resolve(?array $marks): array
    {
        $ulids = [];

        foreach ($marks ?? [] as $mark) {
            if (is_scalar($mark)) {
                $ulids[] = $this->resolveOne((string) $mark);
            }
        }

        return $ulids;
    }

    /**
     * @throws MarkNotFoundException|ProviderException
     */
    public function resolveOne(string $mark): string
    {
        if (isset($this->resolved[$mark])) {
            return $this->resolved[$mark];
        }

        $response = $this->client->findInvoices(['mark' => $mark]);

        if (! $response->isSuccessful()) {
            throw new ProviderException(sprintf('Looking up mark %s in the provider failed with HTTP %d.', $mark, $response->status), $response->status);
        }

        $ulid = $response->firstId();

        if ($ulid === null) {
            throw new MarkNotFoundException($mark);
        }

        return $this->resolved[$mark] = $ulid;
    }
}
