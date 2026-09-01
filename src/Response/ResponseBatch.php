<?php

namespace OxygenSuite\AadeMyData\Response;

use Firebed\AadeMyData\Models\Response;
use Firebed\AadeMyData\Models\ResponseDoc;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;

/**
 * Sends the entities of one request through the provider and collects a ResponseDoc with one
 * entry per entity, in submission order — the shape AADE answers with, which the package's
 * reader turns back into models.
 *
 * A batch never aborts midway: a failure becomes an entry of its own and the rest is still
 * sent. The one exception is a rejected token, and only until the first entity has been
 * registered — after that its mark has to reach the ERP.
 */
final class ResponseBatch
{
    public function __construct(private ProviderResponseMapper $responses) {}

    /**
     * @throws UnauthorizedException
     *
     * @template TItem
     *
     * @param iterable<TItem> $items
     * @param callable(TItem): Response $send
     */
    public function collect(iterable $items, callable $send): ResponseDoc
    {
        $doc = new ResponseDoc();
        $index = 0;
        $rejected = null;

        // Sequential on purpose: the provider locks per uid and the ERP relies on index order.
        foreach ($items as $item) {
            if ($rejected !== null) {
                $doc->add($this->responses->forUnauthorized($rejected)->setIndex(++$index));

                continue;
            }

            try {
                $doc->add($send($item)->setIndex(++$index));
            } catch (UnauthorizedException $e) {
                // Nothing is registered yet, so the ERP is better served by the exception.
                if ($index === 0) {
                    throw $e;
                }

                // The entities before this one are registered at the provider and legally
                // issued: their marks have to reach the ERP, so the rest of the batch is
                // reported per entity instead of aborting and losing them.
                $rejected = $e;
                $doc->add($this->responses->forUnauthorized($e)->setIndex(++$index));
            }
        }

        return $doc;
    }
}
