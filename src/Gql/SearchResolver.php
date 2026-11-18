<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Gql;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Resolvers\Elements\Asset as AssetResolver;
use CraftCms\Cms\Gql\Resolvers\Elements\Entry as EntryResolver;
use CraftCms\Cms\Gql\Resolvers\Elements\User as UserResolver;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Commerce\Gql\Resolvers\Elements\Product as ProductResolver;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Search\Bloodhound;
use Throwable;

use function CraftCms\Cms\t;

class SearchResolver
{
    /** @return list<string> Handles of the indexes the active schema may search. */
    public static function allowedIndexes(): array
    {
        return array_values(array_filter(
            array_keys(app(Indexes::class)->all()),
            fn (string $handle) => GqlHelper::canSchema(self::component($handle)),
        ));
    }

    public static function component(string $handle): string
    {
        return "bloodhound.{$handle}";
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function resolve(mixed $source, array $args): array
    {
        $handle = (string) $args['index'];
        $index = app(Indexes::class)->get($handle);

        // Unknown and not-allowed read the same, so a schema can't probe for index names.
        if ($index === null || ! GqlHelper::canSchema(self::component($handle))) {
            throw new UserError("This schema can’t search the “{$handle}” index.");
        }

        $site = self::site(isset($args['site']) ? (string) $args['site'] : null);
        $where = [];
        foreach ($args['where'] ?? [] as $filter) {
            $attribute = (string) $filter['attribute'];

            // Ignoring an unknown filter would widen the results, so it's an error instead.
            if (! in_array($attribute, $index->filterableAttributes(), true)) {
                throw new UserError("“{$attribute}” isn’t a filterable attribute of the “{$handle}” index. Filterable: ".implode(', ', $index->filterableAttributes()).'.');
            }

            $where[$attribute] = (string) $filter['value'];
        }

        $bloodhound = app(Bloodhound::class);
        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($args['perPage'] ?? 20)));
        $scope = self::scope($index, $site);

        // The schema can read everything the index holds: the engine's counts and pages are exact.
        if ($scope !== null && self::coversIndex($index, $site, $scope)) {
            $results = $bloodhound->search($handle, (string) $args['query'], [
                'site' => $site->handle, 'page' => $page, 'perPage' => $perPage, 'where' => $where,
            ]);

            return [
                'elements' => $results->elements,
                'total' => $results->total,
                'page' => $results->page,
                'perPage' => $results->perPage,
                'pages' => $results->pages(),
                'query' => $results->query,
                'error' => $results->error,
            ];
        }

        // Otherwise the engine's counts would include elements this schema can't read
        // (and reveal that they match), so count and page only the readable hits.
        $text = $bloodhound->queryText((string) $args['query']);
        $readable = [];
        $error = null;

        if ($scope !== null && $text !== '') {
            try {
                $ranked = $bloodhound->rankedIds($index, $site->handle, $text, self::MAX_HITS, $where);
                $allowed = array_flip(self::readableIds($scope, $site, $ranked));
                $readable = array_values(array_filter($ranked, fn (int $id) => isset($allowed[$id])));
            } catch (Throwable $e) {
                Log::warning("Bloodhound GraphQL search on {$handle} failed: {$e->getMessage()}");
                $error = t('Search is temporarily unavailable.', category: 'bloodhound');
            }
        }

        $total = count($readable);

        return [
            'elements' => $bloodhound->hydrate($index, $site->handle, array_slice($readable, ($page - 1) * $perPage, $perPage)),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => (int) ceil($total / $perPage),
            'query' => $text,
            'error' => $error,
        ];
    }

    /** The requested site, or the primary one the schema can read. */
    private static function site(?string $handle): Site
    {
        $allowed = GqlHelper::getAllowedSites();

        foreach ($allowed as $site) {
            if ($handle === null ? $site->primary : $site->handle === $handle) {
                return $site;
            }
        }

        if ($handle === null && $allowed !== []) {
            return $allowed[0];
        }

        throw new UserError($handle === null ? 'This schema can’t read any site.' : "This schema can’t read the “{$handle}” site.");
    }

    /** Hits counted when the schema can't read the whole index (as Pro's `.search()` takeover). */
    public const int MAX_HITS = 1000;

    /**
     * The element query this schema may read for the index's element type, as
     * Craft's (or Commerce's) own GraphQL resolver builds it: sections, volumes,
     * user groups, product types, and for authenticated tokens what that user
     * may view. Null when the schema can't read that element type at all.
     *
     * @return ElementQueryInterface<covariant ElementInterface>|null
     */
    private static function scope(IndexDefinition $index, Site $site): ?ElementQueryInterface
    {
        $query = match ($index->elementType) {
            'entries' => GqlHelper::canQueryEntries() ? EntryResolver::prepareQuery(null, []) : null,
            'assets' => GqlHelper::canQueryAssets() ? AssetResolver::prepareQuery(null, []) : null,
            'users' => GqlHelper::canQueryUsers() ? UserResolver::prepareQuery(null, []) : null,
            'products' => class_exists(ProductResolver::class) ? ProductResolver::prepareQuery(null, []) : null,
            default => null,
        };

        return $query instanceof ElementQueryInterface ? $query->site($site->handle) : null;
    }

    /** Whether every live element of the index is readable by the schema. */
    private static function coversIndex(IndexDefinition $index, Site $site, ElementQueryInterface $scope): bool
    {
        $live = app(Indexes::class)->query($index, $site->handle);
        $total = (clone $live)->count();

        return $total === 0 || (clone $scope)->id((clone $live)->ids())->count() === $total;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function readableIds(ElementQueryInterface $scope, Site $site, array $ids): array
    {
        return $ids === [] ? [] : array_map(intval(...), (clone $scope)->id($ids)->ids());
    }
}
