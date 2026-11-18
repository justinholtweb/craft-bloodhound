<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound;

use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Cp\IconSets;
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

    /** Show a sidebar link to Bloodhound's index health page (Utilities → Bloodhound). */
    public bool $showInCpNav = true;

    /**
     * What the sidebar link says. Null uses “Bloodhound”. Only the sidebar changes:
     * page titles and the plugin list keep the plugin's name, so docs still match.
     */
    public ?string $cpNavLabel = null;

    /**
     * The sidebar icon: a Craft icon name (`magnifying-glass`) or a custom icon set
     * icon (`set:name`), as the icon picker saves it. Null uses Bloodhound's own icon.
     * File paths and raw SVG aren't accepted.
     */
    public ?string $cpNavIcon = null;

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

    /** The sidebar label, or null for the plugin's name. */
    public function navLabel(): ?string
    {
        $label = trim((string) $this->cpNavLabel);

        return $label === '' ? null : $label;
    }

    /** The sidebar icon if it names a real Craft or icon-set icon, else null. */
    public function navIcon(): ?string
    {
        $icon = trim((string) $this->cpNavIcon);

        return $icon !== '' && self::isIcon($icon) ? $icon : null;
    }

    public static function isIcon(string $icon): bool
    {
        if ($custom = IconSets::parse($icon)) {
            $svg = rescue(fn () => app(IconSets::class)->getIconSetByHandle($custom[0])?->getSvg($custom[1]), null, false);

            return is_string($svg) && stripos($svg, '<svg') !== false;
        }

        return (bool) preg_match('/^[\da-z\-]+$/D', $icon) && is_file(Icons::resolveIconPath($icon));
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
            'cpNavLabel' => ['nullable', 'string', 'max:100'],
            'cpNavIcon' => ['nullable', 'string', 'max:100', 'regex:/^([a-zA-Z][\w\-]*:)?[\w\-]+$/'],
            'indexes' => ['nullable', 'array'],
            'indexes.*.handle' => ['required', 'regex:/^[a-z][a-z0-9_]{0,47}$/', 'distinct'],
            'indexes.*.elementType' => ['required', 'in:entries,assets,users,products'],
            'synonyms' => ['nullable', 'array', 'max:500'],
            'stopWords' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
