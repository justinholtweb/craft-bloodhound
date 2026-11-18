<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Health;

use JustinHoltWeb\Bloodhound\Engines\DocumentLister;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;

/**
 * How an engine index has drifted from Craft:
 * - missing: live elements with no document
 * - orphaned: documents whose element isn't live any more (or never existed)
 * - stale: documents older than their element's last update
 */
class IndexHealth
{
    public function __construct(
        private readonly Indexes $indexes,
        private readonly Indexer $indexer,
        private readonly DocumentLister $lister,
    ) {}

    /**
     * @return array{index: string, site: string, engineIndex: string, supported: bool, exists: bool, live: int, documents: int, missing: list<int>, orphaned: list<int>, stale: list<int>}
     */
    public function report(IndexDefinition $index, string $site): array
    {
        $name = $this->indexer->engineIndex($index, $site);
        $live = [];
        foreach ($this->indexes->query($index, $site)->select(['elements.id', 'elements.dateUpdated'])->asArray()->all() as $row) {
            $live[(int) $row['id']] = isset($row['dateUpdated']) ? strtotime((string) $row['dateUpdated'].' UTC') : null;
        }

        $base = ['index' => $index->handle, 'site' => $site, 'engineIndex' => $name, 'live' => count($live)];
        $engine = $this->indexer->engine();

        if (! $this->lister->supports($engine)) {
            return $base + ['supported' => false, 'exists' => false, 'documents' => 0, 'missing' => [], 'orphaned' => [], 'stale' => []];
        }

        $docs = $this->lister->list($engine, $name);

        return $base + self::compare($live, $docs ?? []) + ['supported' => true, 'exists' => $docs !== null, 'documents' => count($docs ?? [])];
    }

    /**
     * Pure comparison, unit-tested.
     *
     * @param  array<int, int|null>  $live  element id => updated (unix)
     * @param  array<int, int|null>  $docs  element id => document's dateUpdated (unix)
     * @return array{missing: list<int>, orphaned: list<int>, stale: list<int>}
     */
    public static function compare(array $live, array $docs): array
    {
        $missing = array_values(array_diff(array_keys($live), array_keys($docs)));
        $orphaned = array_values(array_diff(array_keys($docs), array_keys($live)));
        $stale = [];

        foreach (array_intersect_key($live, $docs) as $id => $updated) {
            if ($updated !== null && ($docs[$id] === null || $docs[$id] < $updated)) {
                $stale[] = $id;
            }
        }

        sort($missing);
        sort($orphaned);
        sort($stale);

        return ['missing' => $missing, 'orphaned' => $orphaned, 'stale' => $stale];
    }

    /**
     * Re-syncs exactly the drifted ids; returns how many were touched.
     */
    public function repair(IndexDefinition $index, string $site, bool $dryRun = false): int
    {
        $r = $this->report($index, $site);
        $ids = array_values(array_unique([...$r['missing'], ...$r['orphaned'], ...$r['stale']]));

        if (! $dryRun && $ids !== []) {
            if (! $r['exists']) {
                $this->indexer->prepare($r['engineIndex'], $index);
            }
            foreach (array_chunk($ids, 250) as $chunk) {
                $this->indexer->sync($index, $site, $chunk);
            }
        }

        return count($ids);
    }
}
