<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Indexes;

/**
 * One search index: which elements go in it and which of their fields.
 * Each index is kept per site in the engine: `{prefix}{handle}_{siteHandle}`.
 */
final readonly class IndexDefinition
{
    /** Products need Craft Commerce. */
    public const array ELEMENT_TYPES = ['entries', 'assets', 'users', 'products'];

    /**
     * @param  list<string>  $sources  Section (entries), volume (assets), user group (users) or product type (products) handles; empty = all
     * @param  list<string>  $fields  Custom field handles copied into each document
     */
    public function __construct(
        public string $handle,
        public string $elementType,
        public array $sources,
        public array $fields,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $handle = trim((string) ($row['handle'] ?? ''));
        $type = (string) ($row['elementType'] ?? 'entries');

        if (! preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $handle) || ! in_array($type, self::ELEMENT_TYPES, true)) {
            return null;
        }

        return new self($handle, $type, self::list($row['sources'] ?? ''), self::list($row['fields'] ?? ''));
    }

    /** Attributes a document carries for filtering and sorting, never searched as text. */
    public const array ATTRIBUTES = ['id', 'url', 'siteId', 'dateUpdated', 'postDate', 'section', 'type', 'volume', 'kind', 'groups', 'productType', 'price'];

    /**
     * Text every document of this element type carries besides the title:
     * an asset's filename and alt text, a user's username, a product's variant SKUs.
     *
     * @return list<string>
     */
    public function builtInText(): array
    {
        return match ($this->elementType) {
            'assets' => ['filename', 'alt'],
            'users' => ['username'],
            'products' => ['sku'],
            default => [],
        };
    }

    /**
     * Attributes templates can filter on with `where`.
     *
     * @return list<string>
     */
    public function filterableAttributes(): array
    {
        return ['siteId', ...match ($this->elementType) {
            'assets' => ['volume', 'kind'],
            'users' => ['groups'],
            'products' => ['productType', 'price'],
            default => ['section', 'type'],
        }];
    }

    /** @return list<string> */
    public function sortableAttributes(): array
    {
        return ['dateUpdated', ...match ($this->elementType) {
            'entries' => ['postDate'],
            'products' => ['postDate', 'price'],
            default => [],
        }];
    }

    /**
     * What a query is matched against, in ranking order: the title, then the
     * element type's own text, then the index's fields. Every engine is told the
     * same list, so results don't depend on which engine is configured (left to
     * itself, Meilisearch would also search URLs and type handles).
     *
     * @return list<string>
     */
    public function searchableAttributes(): array
    {
        $fields = ['title', ...$this->builtInText(), ...$this->fields];

        return array_values(array_unique(array_diff($fields, self::ATTRIBUTES)));
    }

    public function engineIndexName(string $prefix, string $siteHandle): string
    {
        return $prefix.$this->handle.'_'.$siteHandle;
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);

        return array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $items ?: []), fn (string $v) => $v !== '')));
    }
}
