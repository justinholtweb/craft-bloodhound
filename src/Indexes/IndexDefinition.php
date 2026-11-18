<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Indexes;

/**
 * One search index: which elements go in it and which of their fields.
 * Each index is kept per site in the engine: `{prefix}{handle}_{siteHandle}`.
 */
final readonly class IndexDefinition
{
    public const array ELEMENT_TYPES = ['entries', 'assets'];

    /**
     * @param  list<string>  $sources  Section handles (entries) or volume handles (assets); empty = all
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
