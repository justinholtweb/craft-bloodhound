<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Engines;

use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use Laravel\Scout\Engines\TypesenseEngine;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Typesense needs what Meilisearch infers: a typed collection schema and the
 * list of fields to search (`query_by`). Scout reads both per model class, but
 * every Bloodhound index shares one document class, so they're derived from the
 * index definition here instead.
 */
final class TypesenseSchema
{
    /**
     * @return array{name: string, fields: list<array<string, mixed>>}
     */
    public static function collection(string $name, IndexDefinition $index): array
    {
        $fields = [
            ['name' => 'title', 'type' => 'string', 'optional' => true],
            ['name' => 'url', 'type' => 'string', 'optional' => true, 'index' => false],
            ['name' => 'siteId', 'type' => 'int32', 'optional' => true],
            ['name' => 'dateUpdated', 'type' => 'int64', 'optional' => true],
        ];

        $fields = [...$fields, ...match ($index->elementType) {
            'assets' => [
                ['name' => 'volume', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'kind', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'filename', 'type' => 'string', 'optional' => true],
                ['name' => 'alt', 'type' => 'string', 'optional' => true],
            ],
            default => [
                ['name' => 'section', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'type', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'postDate', 'type' => 'int64', 'optional' => true],
            ],
        }];

        $declared = array_column($fields, 'name');

        foreach ($index->fields as $handle) {
            if ($handle !== 'id' && ! in_array($handle, $declared, true)) {
                // Custom fields are search text, always a list of strings. Not `string*`:
                // its type stays unresolved until a document has a value, and query_by
                // can't name an unresolved field, so one empty field broke every search.
                $fields[] = ['name' => $handle, 'type' => 'string[]', 'optional' => true];
                $declared[] = $handle;
            }
        }

        return ['name' => $name, 'fields' => $fields];
    }

    public static function queryBy(IndexDefinition $index): string
    {
        return implode(',', $index->searchableAttributes());
    }

    /**
     * A document as Typesense accepts it: a string id, and custom fields as a
     * flat list of strings (one mistyped value would fail the whole batch).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function document(array $document, IndexDefinition $index): array
    {
        if (isset($document['id'])) {
            $document['id'] = (string) $document['id'];
        }

        $text = array_flip(array_diff($index->fields, [...IndexDefinition::ATTRIBUTES, 'title', 'filename', 'alt']));

        foreach ($document as $key => $value) {
            if (! isset($text[$key])) {
                continue;
            }

            $document[$key] = self::strings(is_array($value) ? $value : [$value]);

            if ($document[$key] === []) {
                unset($document[$key]);
            }
        }

        return $document;
    }

    /**
     * Creates the collection, or adds fields the index has gained since it was
     * created. Field types and removed fields are left alone; a rebuild with
     * `--fresh` resets the schema.
     */
    public static function ensure(TypesenseEngine $engine, string $name, IndexDefinition $index): void
    {
        $schema = self::collection($name, $index);

        try {
            // TypesenseEngine proxies unknown calls to the Typesense client.
            // @phpstan-ignore method.notFound
            $existing = $engine->getCollections()[$name]->retrieve();
        } catch (ObjectNotFound) {
            // @phpstan-ignore method.notFound
            $engine->getCollections()->create($schema);

            return;
        }

        $have = array_column($existing['fields'] ?? [], 'name');
        $add = array_values(array_filter($schema['fields'], fn (array $field) => ! in_array($field['name'], $have, true)));

        if ($add !== []) {
            // @phpstan-ignore method.notFound
            $engine->getCollections()[$name]->update(['fields' => $add]);
        }
    }

    /**
     * Makes the collection's Bloodhound synonyms (ids `bloodhound_…`) match the
     * settings: multi-way groups upserted, ones no longer configured removed.
     * Synonyms anyone added by hand are left alone.
     *
     * @param  array<string, list<string>>  $groups
     */
    public static function syncSynonyms(TypesenseEngine $engine, string $name, array $groups): void
    {
        // @phpstan-ignore method.notFound
        $synonyms = $engine->getCollections()[$name]->getSynonyms();
        $wanted = [];

        foreach ($groups as $term => $equivalents) {
            $wanted['bloodhound_'.substr(md5($term), 0, 12)] = array_values(array_unique([$term, ...$equivalents]));
        }

        foreach ($synonyms->retrieve()['synonyms'] ?? [] as $existing) {
            $id = (string) ($existing['id'] ?? '');
            if (str_starts_with($id, 'bloodhound_') && ! isset($wanted[$id])) {
                $synonyms[$id]->delete();
            }
        }

        foreach ($wanted as $id => $words) {
            $synonyms->upsert($id, ['synonyms' => $words]);
        }
    }

    /**
     * Points `$alias` at `$collection` and drops what it pointed at before. The
     * first time, `$alias` is still a real collection (Typesense won't alias over
     * it), so that one is deleted just before the alias is made — a gap of one
     * request. Every swap after that is a single atomic alias update.
     */
    public static function pointAlias(TypesenseEngine $engine, string $alias, string $collection): void
    {
        // @phpstan-ignore method.notFound
        $aliases = $engine->getAliases();
        // @phpstan-ignore method.notFound
        $collections = $engine->getCollections();

        try {
            $previous = (string) ($aliases[$alias]->retrieve()['collection_name'] ?? '');
        } catch (ObjectNotFound) {
            $previous = '';
            try {
                $collections[$alias]->delete();
            } catch (ObjectNotFound) {
                // nothing there yet
            }
        }

        $aliases->upsert($alias, ['collection_name' => $collection]);

        if ($previous !== '' && $previous !== $collection) {
            try {
                $collections[$previous]->delete();
            } catch (ObjectNotFound) {
                // already gone
            }
        }
    }

    /** Deletes an index whether it is a collection or (after a swap rebuild) an alias and its collection. */
    public static function drop(TypesenseEngine $engine, string $name): void
    {
        // @phpstan-ignore method.notFound
        $aliases = $engine->getAliases();
        // @phpstan-ignore method.notFound
        $collections = $engine->getCollections();

        try {
            $target = (string) ($aliases[$name]->retrieve()['collection_name'] ?? '');
            $aliases[$name]->delete();
        } catch (ObjectNotFound) {
            $target = $name;
        }

        try {
            $collections[$target]->delete();
        } catch (ObjectNotFound) {
            // nothing to drop
        }
    }

    /**
     * Every document as id => dateUpdated, or null when the collection doesn't exist.
     *
     * @return array<int, int|null>|null
     */
    public static function documents(TypesenseEngine $engine, string $name): ?array
    {
        try {
            // @phpstan-ignore method.notFound
            $jsonl = (string) $engine->getCollections()[$name]->getDocuments()->export(['include_fields' => 'id,dateUpdated']);
        } catch (ObjectNotFound) {
            return null;
        }

        $docs = [];

        foreach (preg_split('/\R/', $jsonl) ?: [] as $line) {
            if (trim($line) === '' || ! is_array($doc = json_decode($line, true)) || ! isset($doc['id'])) {
                continue;
            }

            $docs[(int) $doc['id']] = isset($doc['dateUpdated']) ? (int) $doc['dateUpdated'] : null;
        }

        return $docs;
    }

    /**
     * A filter value Typesense reads literally: strings are backtick-quoted, so
     * template input like `a,b]` or `x || y` can't widen the filter.
     */
    public static function filterValue(int|float|string|bool $value): int|float|string|bool
    {
        return is_string($value) && ! is_numeric($value) ? '`'.str_replace('`', '', $value).'`' : $value;
    }

    private static function string(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $out = [];

        array_walk_recursive($values, function (mixed $v) use (&$out): void {
            if (($s = self::string($v)) !== null && $s !== '') {
                $out[] = $s;
            }
        });

        return $out;
    }
}
