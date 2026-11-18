<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Search;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Search\Events\SearchPerformed;
use CraftCms\Cms\Search\Search;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Commerce\Product\Elements\Product;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Settings;
use Throwable;

/**
 * Pro: Craft's own `.search()` answered by the search engine.
 *
 * The engine only holds live elements of the indexed sections/volumes, so it is
 * only authoritative for a query whose every candidate element is one of those.
 * That is checked, not guessed from query params: Craft's candidate ids (the
 * query minus its search) must all be live members of one index on one site.
 * Anything else — disabled or draft queries, the control panel's "all statuses"
 * listings, other sections, several sites, Craft search syntax, an engine
 * failure — runs Craft's built-in search unchanged.
 */
class CraftSearch extends Search
{
    /** Takes over from Craft's service, keeping its configuration. */
    public static function replacing(Search $craft): self
    {
        $search = new self;
        $search->useFullText = $craft->useFullText;
        $search->minFullTextWordLength = $craft->minFullTextWordLength;
        $search->maxPostgresKeywordLength = $craft->maxPostgresKeywordLength;

        return $search;
    }

    /** Hits taken from the engine per query; matches beyond this aren't returned. */
    public const int MAX_HITS = 1000;

    public function shouldCallSearchElements(ElementQueryInterface $elementQuery): bool
    {
        return ($this->enabled() && $this->indexesFor($elementQuery) !== []) || parent::shouldCallSearchElements($elementQuery);
    }

    /** @return array<string, int> */
    public function searchElements(ElementQueryInterface $elementQuery): array
    {
        try {
            $scores = $this->enabled() ? $this->fromEngine($elementQuery) : null;
        } catch (Throwable $e) {
            Log::warning("Bloodhound: engine search failed, using Craft's search: {$e->getMessage()}");
            $scores = null;
        }

        return $scores ?? parent::searchElements($elementQuery);
    }

    /**
     * Scores keyed `elementId-siteId` (rank order), or null when the engine
     * can't answer this query authoritatively.
     *
     * @return array<string, int>|null
     */
    private function fromEngine(ElementQueryInterface $elementQuery): ?array
    {
        $text = is_string($elementQuery->search) ? trim($elementQuery->search) : null;

        if ($text === null || $text === '' || self::usesCraftSyntax($text)) {
            return null;
        }

        if (($indexes = $this->indexesFor($elementQuery)) === [] || ($site = $this->singleSite($elementQuery)) === null) {
            return null;
        }

        $candidates = $this->candidateIds($elementQuery);
        $catalogue = app(Indexes::class);

        foreach ($indexes as $index) {
            if ($candidates !== [] && $catalogue->query($index, $site->handle)->id($candidates)->count() !== count($candidates)) {
                continue; // something the index doesn't hold (disabled, draft, other section)
            }

            $allowed = array_flip($candidates);
            $ranked = array_values(array_filter(
                app(Bloodhound::class)->rankedIds($index, $site->handle, $text, self::MAX_HITS),
                fn (int $id) => isset($allowed[$id]),
            ));

            $scores = [];
            foreach ($ranked as $rank => $id) {
                $scores["{$id}-{$site->id}"] = count($ranked) - $rank;
            }

            event(new SearchPerformed($elementQuery, $this->normalizeSearchQuery($text), [], $scores));

            return $scores;
        }

        return null;
    }

    /** @return list<IndexDefinition> Indexes of the query's element type */
    private function indexesFor(ElementQueryInterface $elementQuery): array
    {
        $type = match ($elementQuery->elementType) {
            Entry::class => 'entries',
            Asset::class => 'assets',
            User::class => 'users',
            Product::class => 'products', // a class name string, so Commerce needn't be installed
            default => null,
        };

        return $type === null ? [] : array_values(array_filter(app(Indexes::class)->all(), fn (IndexDefinition $i) => $i->elementType === $type));
    }

    private function singleSite(ElementQueryInterface $elementQuery): ?Site
    {
        $siteId = $elementQuery->siteId ?? null;

        if (is_array($siteId)) {
            $siteId = count($siteId) === 1 ? reset($siteId) : null;
        } elseif ($siteId === null) {
            return Sites::getCurrentSite();
        }

        return is_numeric($siteId) ? Sites::getSiteById((int) $siteId) : null;
    }

    /**
     * Every element the query would return without its search, as Craft's own
     * searchElements() computes them.
     *
     * @return list<int>
     */
    private function candidateIds(ElementQueryInterface $elementQuery): array
    {
        if (! $elementQuery instanceof ElementQuery) {
            return [];
        }

        $query = (clone $elementQuery)->search(null)->offset(null)->limit(null);
        $query->reorder();
        $query->select('elements.id as id');

        return array_values(array_unique(array_map(intval(...), $query->pluck('id')->all())));
    }

    /** Craft's search syntax (attribute:value, -exclusions, "phrases", wildcards, OR) means Craft's search. */
    public static function usesCraftSyntax(string $text): bool
    {
        return (bool) preg_match('/[:"*]|(^|\s)-\S|\sOR\s/', $text);
    }

    private function enabled(): bool
    {
        return app(Settings::class)->replaceCraftSearch;
    }
}
