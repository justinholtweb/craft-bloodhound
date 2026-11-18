<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Search;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JustinHoltWeb\Bloodhound\Documents\SearchDocument;
use JustinHoltWeb\Bloodhound\Engines\TypesenseSchema;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Plugin;
use JustinHoltWeb\Bloodhound\Settings;
use JustinHoltWeb\Bloodhound\Sync\Indexer;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Engines\TypesenseEngine;
use Throwable;

use function CraftCms\Cms\t;

/**
 * The template API — `bloodhound.search(...)` in Twig, `$bloodhound->search(...)`
 * in Blade. Hits are re-read through the index's live-only element query, so a
 * stale document can never show a disabled, expired or deleted element.
 */
class Bloodhound
{
    public function __construct(
        private readonly Indexes $indexes,
        private readonly Indexer $indexer,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $options  page, perPage, site, where (attribute => value) — template input, so untrusted
     */
    public function search(string $index, ?string $query, array $options = []): SearchResults
    {
        $definition = $this->indexes->get($index) ?? throw new InvalidArgumentException("Unknown Bloodhound index “{$index}”.");
        $query = $this->queryText((string) $query);
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['perPage'] ?? 20)));
        $site = (string) ($options['site'] ?? Sites::getCurrentSite()->handle);

        if ($query === '') {
            return new SearchResults([], 0, $page, $perPage, $query);
        }

        // An unknown site (e.g. from a query string) finds nothing, without asking the engine.
        if (! in_array($site, $this->indexer->siteHandles(), true)) {
            Log::warning("Bloodhound search on {$index}: unknown site “{$site}”.");

            return new SearchResults([], 0, $page, $perPage, $query);
        }

        try {
            $builder = new Builder(SearchDocument::for($this->indexer->engineIndex($definition, $site), [], $definition), $query);

            $engine = $builder->model->searchableUsing();
            self::applyWhere($builder, $engine, $options['where'] ?? null);

            $raw = $engine->paginate($builder, $perPage, $page);
            $ids = array_map(intval(...), $engine->mapIds($raw)->all());
            $total = (int) $engine->getTotalCount($raw);
        } catch (Throwable $e) {
            // An engine outage shows as "no results", never as a broken page.
            Log::warning("Bloodhound search on {$index} failed: {$e->getMessage()}");

            // Details stay in the log; templates only learn that search is unavailable.
            return new SearchResults([], 0, $page, $perPage, $query, t('Search is temporarily unavailable.', category: 'bloodhound'));
        }

        return new SearchResults($this->hydrate($definition, $site, $ids), $total, $page, $perPage, $query);
    }

    /**
     * Element ids in engine rank order, up to `$max`, for one index and site.
     * Unlike `search()` this throws on an engine failure, so callers can fall back.
     *
     * @param  array<array-key, mixed>|null  $where  As `search()`'s `where` option
     * @return list<int>
     */
    public function rankedIds(IndexDefinition $index, string $site, string $query, int $max = 1000, ?array $where = null): array
    {
        $builder = new Builder(SearchDocument::for($this->indexer->engineIndex($index, $site), [], $index), $this->queryText($query));
        $engine = $builder->model->searchableUsing();
        self::applyWhere($builder, $engine, $where);
        $ids = [];
        $perPage = 250; // Typesense's per_page ceiling

        for ($page = 1; count($ids) < $max; $page++) {
            $raw = $engine->paginate($builder, $perPage, $page);
            $hits = array_map(intval(...), $engine->mapIds($raw)->all());
            array_push($ids, ...$hits);

            if (count($hits) < $perPage || $page * $perPage >= (int) $engine->getTotalCount($raw)) {
                break;
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, $max);
    }

    /**
     * The query as sent to the engine: trimmed, and on Pro without the configured
     * stop words (done here rather than per engine, so every engine behaves the
     * same). A query made only of stop words is left as typed.
     */
    public function queryText(string $query): string
    {
        $query = trim($query);
        $stop = Plugin::isPro() ? array_flip($this->settings->stopWordList()) : [];

        if ($stop === [] || $query === '') {
            return $query;
        }

        $kept = array_filter(preg_split('/\s+/u', $query) ?: [], fn (string $w) => ! isset($stop[mb_strtolower($w)]));

        return $kept === [] ? $query : implode(' ', $kept);
    }

    /**
     * Filters from template or API input (untrusted): identifier-safe attribute
     * names, scalar values, matched literally on every engine.
     *
     * @param  Builder<SearchDocument>  $builder
     */
    private static function applyWhere(Builder $builder, Engine $engine, mixed $where): void
    {
        foreach (is_array($where) ? $where : [] as $attribute => $value) {
            if (is_string($attribute) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $attribute) && is_scalar($value)) {
                $builder->where($attribute, $engine instanceof TypesenseEngine ? TypesenseSchema::filterValue($value) : $value);
            }
        }
    }

    /**
     * Live elements for engine hits, in the given order; ids that no longer
     * resolve to a live element are dropped.
     *
     * @param  list<int>  $ids
     * @return list<ElementInterface>
     */
    public function hydrate(IndexDefinition $index, string $site, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = [];
        foreach ($this->indexes->query($index, $site)->id($ids)->all() as $element) {
            $byId[(int) $element->id] = $element;
        }

        // Engine rank order; ids that no longer resolve to a live element are dropped.
        return array_values(array_filter(array_map(fn (int $id) => $byId[$id] ?? null, $ids)));
    }
}
