<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound;

use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Support\Env;

/**
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 *
 * The engine itself is Scout's own config (`SCOUT_DRIVER`, `MEILISEARCH_HOST`, …).
 */
class Settings extends PluginSettings
{
    /** Prepended to every engine index name (after `scout.prefix`), e.g. `$CRAFT_ENVIRONMENT_`. */
    public ?string $indexPrefix = null;

    /** Push index updates to the queue instead of writing during the save request. */
    public bool $queueUpdates = true;

    /**
     * Index rows: handle, elementType (entries|assets), sources, fields.
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $indexes = [];

    public function prefix(): ?string
    {
        $prefix = Env::parse($this->indexPrefix === null ? null : trim($this->indexPrefix));

        return $prefix !== null && preg_match('/^[A-Za-z0-9_-]*$/D', $prefix) ? $prefix : null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getRules(): array
    {
        return [
            'indexPrefix' => ['nullable', 'string', 'max:64'],
            'indexes' => ['nullable', 'array'],
            'indexes.*.handle' => ['required', 'regex:/^[a-z][a-z0-9_]{0,47}$/', 'distinct'],
            'indexes.*.elementType' => ['required', 'in:entries,assets'],
        ];
    }
}
