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
use JustinHoltWeb\Bloodhound\Settings;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Engines\TypesenseEngine;
use Typesense\Exceptions\ObjectNotFound;

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
        private readonly EngineManager $engines,
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
    public function import(IndexDefinition $index, string $siteHandle, ?\Closure $progress = null, int $chunk = 250): int
    {
        $name = $this->engineIndex($index, $siteHandle);
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

            return;
        }

        if (! method_exists($engine, 'updateIndexSettings')) {
            return;
        }

        rescue(fn () => $engine->createIndex($name, ['primaryKey' => 'id']), report: false);

        $filterable = $index->elementType === 'assets' ? ['siteId', 'volume', 'kind'] : ['siteId', 'section', 'type'];
        $engine->updateIndexSettings($name, [
            'searchableAttributes' => $index->searchableAttributes(),
            'filterableAttributes' => $filterable,
            'sortableAttributes' => ['dateUpdated', ...($index->elementType === 'entries' ? ['postDate'] : [])],
        ]);
    }

    public function flush(IndexDefinition $index, string $siteHandle): void
    {
        try {
            $this->engine()->deleteIndex($this->engineIndex($index, $siteHandle));
        } catch (ObjectNotFound) {
            // Typesense: nothing to flush.
        }
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
        return $this->engines->engine();
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
