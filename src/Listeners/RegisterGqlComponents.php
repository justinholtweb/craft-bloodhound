<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Listeners;

use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use JustinHoltWeb\Bloodhound\Gql\SearchResolver;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;

use function CraftCms\Cms\t;

/**
 * One GraphQL schema permission per index: “Search the “articles” index”.
 * Results are still limited to elements the schema (or token user) can read.
 */
class RegisterGqlComponents
{
    public function __construct(private readonly Indexes $indexes) {}

    public function handle(GqlSchemaComponentsResolving $event): void
    {
        $components = [];

        foreach ($this->indexes->all() as $index) {
            $components[SearchResolver::component($index->handle).':read'] = [
                'label' => t('Search the “{handle}” index', ['handle' => $index->handle], category: 'bloodhound'),
                'info' => t('Adds the `bloodhoundSearch` query. Results only include elements this schema can also read.', category: 'bloodhound'),
                // Token-authenticated requests are fine: hits are filtered to what that user may view.
                'authenticated' => true,
            ];
        }

        if ($components !== []) {
            $event->queries['Bloodhound'] = $components;
        }
    }
}
