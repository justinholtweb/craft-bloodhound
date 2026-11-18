<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Sync;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Database\Eloquent\Collection;
use JustinHoltWeb\Bloodhound\Documents\DocumentBuilder;
use JustinHoltWeb\Bloodhound\Documents\SearchDocument;
use JustinHoltWeb\Bloodhound\Engines\TypesenseSchema;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Plugin;
use JustinHoltWeb\Bloodhound\Settings;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Engines\MeilisearchEngine;
use Laravel\Scout\Engines\TypesenseEngine;

/**
 * Writes documents to the engine. Every write re-reads the element through the
 * index's live-only query, so whatever the trigger, only live elements end up
 * searchable and anything else is removed.
 */
class Indexer
{
    public function __construct(
        private readonly Indexes $indexes,
        private readonly DocumentBuilder $builder,
        private readonly Settings $settings,
    ) {}

    /**
     * Re-sync specific elements in one index and site: upsert the live ones,
     * delete the rest.
     *
     * @param  list<int>  $elementIds
     */
    public function sync(IndexDefinition $index, string $siteHandle, array $elementIds): void
    {
        if ($elementIds === []) {
            return;
        }

        $name = $this->engineIndex($index, $siteHandle);
        $live = $this->indexes->query($index, $siteHandle)->id($elementIds)->all();
        $liveIds = array_map(fn (ElementInterface $e) => (int) $e->id, $live);

        $this->write($name, $index, $live);

        $gone = array_values(array_diff(array_map(intval(...), $elementIds), $liveIds));
        if ($gone !== []) {
            $this->engine()->delete(new Collection(array_map(fn (int $id) => SearchDocument::for($name, ['id' => $id], $index), $gone)));
        }
    }

    /**
     * (Re)index everything in an index for a site, in chunks.
     *
     * @param  (\Closure(int): void)|null  $progress
     */
    public function import(IndexDefinition $index, string $siteHandle, ?\Closure $progress = null, int $chunk = 250, ?string $into = null): int
    {
        $name = $into ?? $this->engineIndex($index, $siteHandle);
        $this->prepare($name, $index);
        $done = 0;
        $offset = 0;

        do {
            $elements = $this->indexes->query($index, $siteHandle)->orderBy('elements.id')->offset($offset)->limit($chunk)->all();
            $this->write($name, $index, $elements);
            $done += count($elements);
            $offset += $chunk;
            $progress?->__invoke($done);
        } while (count($elements) === $chunk);

        return $done;
    }

    /**
     * Engines that need an index's schema declared up front (Meilisearch: filterable and
     * sortable attributes) get it here; others ignore it. Without this, `where` filters
     * fail on Meilisearch.
     */
    public function prepare(string $name, IndexDefinition $index): void
    {
        $engine = $this->engine();

        if ($engine instanceof TypesenseEngine) {
            TypesenseSchema::ensure($engine, $name, $index);
            TypesenseSchema::syncSynonyms($engine, $name, $this->synonyms());

            return;
        }

        if (! method_exists($engine, 'updateIndexSettings')) {
            return;
        }

        rescue(fn () => $engine->createIndex($name, ['primaryKey' => 'id']), report: false);

        $engine->updateIndexSettings($name, [
            'searchableAttributes' => $index->searchableAttributes(),
            'filterableAttributes' => $index->filterableAttributes(),
            'sortableAttributes' => $index->sortableAttributes(),
            // Meilisearch synonyms are one-way per key, so each group is written out in every direction.
            'synonyms' => (object) self::mutualSynonyms($this->synonyms()),
        ]);
    }

    /**
     * Pro: rebuild without a gap. Everything is indexed into a fresh index, which
     * then replaces the live one in one step (Meilisearch index swap, Typesense
     * alias), so searches keep answering from the old index until the new one is
     * complete. Elements saved meanwhile are re-synced afterwards. Engines without
     * a swap get a normal in-place import.
     */
    public function rebuildWithSwap(IndexDefinition $index, string $siteHandle, ?\Closure $progress = null): int
    {
        $name = $this->engineIndex($index, $siteHandle);
        $engine = $this->engine();
        $started = now()->subSeconds(5);

        if ($engine instanceof MeilisearchEngine) {
            $next = $name.'__next';
            rescue(fn () => $engine->deleteIndex($next), report: false);
            rescue(fn () => $engine->createIndex($name, ['primaryKey' => 'id']), report: false); // swap needs both to exist
            $count = $this->importOrDrop($index, $siteHandle, $progress, $next);
            // Tasks run in order: the swap waits for the import, the delete for the swap.
            // @phpstan-ignore method.notFound
            $engine->swapIndexes([[$name, $next]]);
            $engine->deleteIndex($next);
        } elseif ($engine instanceof TypesenseEngine) {
            $next = $name.'_'.now()->format('YmdHis');
            $count = $this->importOrDrop($index, $siteHandle, $progress, $next);
            TypesenseSchema::pointAlias($engine, $name, $next);
        } else {
            return $this->import($index, $siteHandle, $progress);
        }

        $changed = $this->indexes->query($index, $siteHandle)->dateUpdated('>= '.$started->toIso8601String())->ids();
        $this->sync($index, $siteHandle, array_map(intval(...), $changed));

        return $count;
    }

    /** A half-built index from a failed rebuild is removed rather than left behind. */
    private function importOrDrop(IndexDefinition $index, string $siteHandle, ?\Closure $progress, string $into): int
    {
        try {
            return $this->import($index, $siteHandle, $progress, into: $into);
        } catch (\Throwable $e) {
            rescue(fn () => $this->engine()->deleteIndex($into), report: false);
            throw $e;
        }
    }

    /**
     * Pro synonyms from settings (empty on Lite, which clears any left on the engine).
     *
     * @return array<string, list<string>>
     */
    public function synonyms(): array
    {
        return Plugin::isPro() ? $this->settings->synonymMap() : [];
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @return array<string, list<string>>
     */
    public static function mutualSynonyms(array $groups): array
    {
        $map = [];

        foreach ($groups as $term => $equivalents) {
            $words = array_values(array_unique([$term, ...$equivalents]));
            foreach ($words as $word) {
                $map[$word] = array_values(array_unique([...($map[$word] ?? []), ...array_diff($words, [$word])]));
            }
        }

        return $map;
    }

    public function flush(IndexDefinition $index, string $siteHandle): void
    {
        $engine = $this->engine();
        $name = $this->engineIndex($index, $siteHandle);

        if ($engine instanceof TypesenseEngine) {
            TypesenseSchema::drop($engine, $name);

            return;
        }

        $engine->deleteIndex($name);
    }

    public function engineIndex(IndexDefinition $index, string $siteHandle): string
    {
        return $index->engineIndexName((string) config('scout.prefix', '').($this->settings->prefix() ?? ''), $siteHandle);
    }

    /** @return list<string> */
    public function siteHandles(): array
    {
        return Sites::getAllSites()->pluck('handle')->values()->all();
    }

    public function engine(): Engine
    {
        return SearchDocument::engine();
    }

    /**
     * @param  array<array-key, ElementInterface>  $elements
     */
    private function write(string $name, IndexDefinition $index, array $elements): void
    {
        if ($elements === []) {
            return;
        }

        $this->engine()->update(new Collection(array_map(
            fn (ElementInterface $e) => SearchDocument::for($name, $this->builder->build($e, $index), $index),
            array_values($elements),
        )));
    }
}
