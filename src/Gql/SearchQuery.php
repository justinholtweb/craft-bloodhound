<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Gql;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `bloodhoundSearch` — an index searched from GraphQL, in Lite and Pro. Only
 * indexes ticked for the schema (Bloodhound → “Search the … index”) can be
 * queried, and hits are filtered through Craft's own resolvers, so a schema
 * never receives an element it couldn't fetch with `entries`, `assets`,
 * `users` or `products`.
 *
 * @phpstan-import-type UnnamedFieldDefinitionConfig from FieldDefinition
 */
class SearchQuery extends Query
{
    /** @return array<string, UnnamedFieldDefinitionConfig> */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && SearchResolver::allowedIndexes() === []) {
            return [];
        }

        return [
            'bloodhoundSearch' => [
                'type' => Type::nonNull(SearchResultsType::getType()),
                'args' => [
                    'index' => ['name' => 'index', 'type' => Type::nonNull(Type::string()), 'description' => 'The index handle.'],
                    'query' => ['name' => 'query', 'type' => Type::nonNull(Type::string()), 'description' => 'What to search for.'],
                    'site' => ['name' => 'site', 'type' => Type::string(), 'description' => 'A site handle (default: the primary site the schema can read).'],
                    'page' => ['name' => 'page', 'type' => Type::int(), 'description' => 'Page number, from 1.'],
                    'perPage' => ['name' => 'perPage', 'type' => Type::int(), 'description' => 'Results per page (default 20, at most 100).'],
                    'where' => ['name' => 'where', 'type' => Type::listOf(Type::nonNull(self::whereType())), 'description' => 'Filters on filterable attributes, e.g. `{attribute: "section", value: "news"}`. Values match literally.'],
                ],
                'resolve' => [SearchResolver::class, 'resolve'],
                'description' => 'Searches a Bloodhound index and returns live elements in rank order.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
        ];
    }

    private static function whereType(): InputObjectType
    {
        return GqlEntityRegistry::getOrCreate('BloodhoundWhereInput', fn () => new InputObjectType([
            'name' => 'BloodhoundWhereInput',
            'fields' => [
                'attribute' => ['name' => 'attribute', 'type' => Type::nonNull(Type::string())],
                'value' => ['name' => 'value', 'type' => Type::nonNull(Type::string())],
            ],
        ]));
    }
}
