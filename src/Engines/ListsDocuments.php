<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Engines;

/**
 * For Scout engines Bloodhound doesn't know: implement this on the engine to
 * get index health (missing / orphaned / stale documents) for it.
 */
interface ListsDocuments
{
    /**
     * Every document in an index as element id => the document's `dateUpdated`
     * (unix time, or null), or null when the index doesn't exist.
     *
     * @return array<int, int|null>|null
     */
    public function bloodhoundDocuments(string $index): ?array;
}
