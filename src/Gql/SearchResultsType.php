<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Gql;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use CraftCms\Cms\Gql\Interfaces\Element as ElementInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/** The shape `bloodhoundSearch` returns. */
class SearchResultsType
{
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('BloodhoundSearchResults', fn () => new ObjectType([
            'name' => 'BloodhoundSearchResults',
            'description' => 'A page of Bloodhound search results.',
            'fields' => fn () => [
                'elements' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(ElementInterface::getType()))),
                    'description' => 'Live elements in rank order. Use fragments (`... on EntryInterface`) for their fields.',
                ],
                'total' => ['type' => Type::nonNull(Type::int()), 'description' => 'Matches in the index.'],
                'page' => ['type' => Type::nonNull(Type::int())],
                'perPage' => ['type' => Type::nonNull(Type::int())],
                'pages' => ['type' => Type::nonNull(Type::int())],
                'query' => ['type' => Type::nonNull(Type::string()), 'description' => 'The query as searched.'],
                'error' => ['type' => Type::string(), 'description' => 'Set when the search engine is unavailable; `elements` is then empty.'],
            ],
        ]));
    }
}
