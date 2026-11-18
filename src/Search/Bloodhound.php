<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Search;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JustinHoltWeb\Bloodhound\Documents\SearchDocument;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;
use Laravel\Scout\Builder;
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
    ) {}

    /**
     * @param  array<string, mixed>  $options  page, perPage, site, where (attribute => value) — template input, so untrusted
     */
    public function search(string $index, ?string $query, array $options = []): SearchResults
    {
        $definition = $this->indexes->get($index) ?? throw new InvalidArgumentException("Unknown Bloodhound index “{$index}”.");
        $query = trim((string) $query);
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['perPage'] ?? 20)));
        $site = (string) ($options['site'] ?? Sites::getCurrentSite()->handle);

        if ($query === '') {
            return new SearchResults([], 0, $page, $perPage, $query);
        }

        try {
            $builder = new Builder(SearchDocument::for($this->indexer->engineIndex($definition, $site)), $query);

            foreach (is_array($options['where'] ?? null) ? $options['where'] : [] as $attribute => $value) {
                if (is_string($attribute) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $attribute) && is_scalar($value)) {
                    $builder->where($attribute, $value);
                }
            }

            $engine = $builder->model->searchableUsing();
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
     * @param  list<int>  $ids
     * @return list<ElementInterface>
     */
    private function hydrate(IndexDefinition $index, string $site, array $ids): array
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
