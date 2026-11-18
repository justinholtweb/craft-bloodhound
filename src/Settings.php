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
     * Index rows: handle, elementType (entries|assets|users|products), sources, fields.
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $indexes = [];

    /** Pro: answer Craft's own `.search()` from the engine where an index covers the query. */
    public bool $replaceCraftSearch = false;

    /**
     * Pro: synonym rows — `term` and `synonyms` (comma-separated). Pushed to the engine on import.
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $synonyms = [];

    /** Pro: words the engine ignores in queries (comma or newline separated). */
    public ?string $stopWords = null;

    /**
     * Synonym groups: each term with its equivalents, lowercased, blanks dropped.
     *
     * @return array<string, list<string>>
     */
    public function synonymMap(): array
    {
        $map = [];

        foreach ($this->synonyms ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $term = mb_strtolower(trim((string) ($row['term'] ?? '')));
            $equivalents = self::words((string) ($row['synonyms'] ?? ''), ',');

            if ($term !== '' && ($equivalents = array_values(array_diff($equivalents, [$term]))) !== []) {
                $map[$term] = array_values(array_unique([...($map[$term] ?? []), ...$equivalents]));
            }
        }

        return $map;
    }

    /** @return list<string> */
    public function stopWordList(): array
    {
        return self::words((string) $this->stopWords, ",\n");
    }

    public function prefix(): ?string
    {
        $prefix = Env::parse($this->indexPrefix === null ? null : trim($this->indexPrefix));

        return $prefix !== null && preg_match('/^[A-Za-z0-9_-]*$/D', $prefix) ? $prefix : null;
    }

    /** @return list<string> */
    private static function words(string $value, string $separators): array
    {
        $words = array_map(fn (string $w) => mb_strtolower(trim($w)), preg_split('/['.preg_quote($separators, '/').']+/', $value) ?: []);

        return array_values(array_unique(array_filter($words, fn (string $w) => $w !== '' && mb_strlen($w) <= 64)));
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
            'indexes.*.elementType' => ['required', 'in:entries,assets,users,products'],
            'synonyms' => ['nullable', 'array', 'max:500'],
            'stopWords' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
