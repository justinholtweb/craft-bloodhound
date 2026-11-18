<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Engines;

use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Engines\MeilisearchEngine;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Exceptions\ApiException;

/**
 * Reads back what an engine holds, for health checks. Scout itself has no
 * "list documents" API, so each engine gets its own adapter.
 */
class DocumentLister
{
    public function supports(Engine $engine): bool
    {
        return $engine instanceof ListsDocuments || $engine instanceof MeilisearchEngine;
    }

    /**
     * @return array<int, int|null>|null id => dateUpdated, null when the index doesn't exist
     */
    public function list(Engine $engine, string $index): ?array
    {
        return match (true) {
            $engine instanceof ListsDocuments => $engine->bloodhoundDocuments($index),
            $engine instanceof MeilisearchEngine => $this->meilisearch($engine, $index),
            default => throw new \RuntimeException(get_class($engine).' can’t list its documents; implement '.ListsDocuments::class.'.'),
        };
    }

    /**
     * @return array<int, int|null>|null
     */
    private function meilisearch(MeilisearchEngine $engine, string $index): ?array
    {
        $docs = [];
        $offset = 0;

        try {
            do {
                // Scout's MeilisearchEngine proxies unknown calls to the Meilisearch client.
                // @phpstan-ignore method.notFound
                $page = $engine->index($index)->getDocuments(
                    (new DocumentsQuery)->setFields(['id', 'dateUpdated'])->setLimit(1000)->setOffset($offset),
                );
                foreach ($page->getResults() as $doc) {
                    $docs[(int) $doc['id']] = isset($doc['dateUpdated']) ? (int) $doc['dateUpdated'] : null;
                }
                $offset += 1000;
            } while ($offset < $page->getTotal());
        } catch (ApiException $e) {
            if ($e->httpStatus === 404) {
                return null;
            }
            throw $e;
        }

        return $docs;
    }
}
