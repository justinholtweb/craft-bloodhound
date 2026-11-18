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
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Search\Bloodhound;

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
            $where[(string) $filter['attribute']] = (string) $filter['value'];
        }

        $results = app(Bloodhound::class)->search($handle, (string) $args['query'], [
            'site' => $site->handle,
            'page' => $args['page'] ?? 1,
            'perPage' => $args['perPage'] ?? 20,
            'where' => $where,
        ]);

        return [
            'elements' => self::visible($index, $site, $results->elements),
            'total' => $results->total,
            'page' => $results->page,
            'perPage' => $results->perPage,
            'pages' => $results->pages(),
            'query' => $results->query,
            'error' => $results->error,
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

    /**
     * The hits this schema may read, still in rank order: each element type's own
     * GraphQL resolver decides (sections, volumes, user groups, product types, and
     * for authenticated tokens, what that user may view).
     *
     * @param  list<ElementInterface>  $elements
     * @return list<ElementInterface>
     */
    private static function visible(IndexDefinition $index, Site $site, array $elements): array
    {
        if ($elements === []) {
            return [];
        }

        $query = match ($index->elementType) {
            'entries' => GqlHelper::canQueryEntries() ? EntryResolver::prepareQuery(null, []) : null,
            'assets' => GqlHelper::canQueryAssets() ? AssetResolver::prepareQuery(null, []) : null,
            'users' => GqlHelper::canQueryUsers() ? UserResolver::prepareQuery(null, []) : null,
            'products' => class_exists(ProductResolver::class) ? ProductResolver::prepareQuery(null, []) : null,
            default => null,
        };

        if (! $query instanceof ElementQueryInterface) {
            return [];
        }

        $ids = array_map(fn (ElementInterface $e) => (int) $e->id, $elements);
        $allowed = array_flip(array_map(intval(...), $query->site($site->handle)->id($ids)->ids()));

        return array_values(array_filter($elements, fn (ElementInterface $e) => isset($allowed[(int) $e->id])));
    }
}
