<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Documents;

use Illuminate\Database\Eloquent\Model;
use JustinHoltWeb\Bloodhound\Engines\TypesenseSchema;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Engines\TypesenseEngine;
use LogicException;

/**
 * What Scout's engines see: a never-persisted model carrying one element's
 * document for one engine index. Implements the methods engines call instead
 * of using Scout's Searchable trait, which would register model observers and
 * global collection macros as a side effect.
 */
class SearchDocument extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    private string $engineIndex = '';

    /** @var array<string, mixed> */
    private array $document = [];

    private ?IndexDefinition $index = null;

    /**
     * @param  array<string, mixed>  $document  Must include `id` (the element id)
     * @param  IndexDefinition|null  $index  Needed by engines that want a schema (Typesense)
     */
    public static function for(string $engineIndex, array $document = [], ?IndexDefinition $index = null): self
    {
        $doc = new self;
        $doc->engineIndex = $engineIndex;
        $doc->document = $document;
        $doc->index = $index;
        $doc->setAttribute('id', $document['id'] ?? null);

        return $doc;
    }

    public function searchableAs(): string
    {
        return $this->engineIndex;
    }

    public function indexableAs(): string
    {
        return $this->engineIndex;
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return $this->index !== null && $this->searchableUsing() instanceof TypesenseEngine
            ? TypesenseSchema::document($this->document, $this->index)
            : $this->document;
    }

    /**
     * Read by Scout's Typesense engine when it creates a missing collection.
     *
     * @return array<string, mixed>
     */
    public function typesenseCollectionSchema(): array
    {
        return TypesenseSchema::collection($this->engineIndex, $this->index ?? throw new LogicException('A Typesense collection schema needs the index definition.'));
    }

    /**
     * Merged by Scout's Typesense engine into every search.
     *
     * @return array<string, mixed>
     */
    public function typesenseSearchParameters(): array
    {
        return $this->index !== null ? ['query_by' => TypesenseSchema::queryBy($this->index)] : [];
    }

    /** @return array<string, mixed> */
    public function scoutMetadata(): array
    {
        return [];
    }

    public function getScoutKey(): mixed
    {
        return $this->getAttribute('id');
    }

    public function getScoutKeyName(): string
    {
        return 'id';
    }

    public function getScoutKeyType(): string
    {
        return 'int';
    }

    public function searchableUsing(): Engine
    {
        return app(EngineManager::class)->engine();
    }

    public function shouldBeSearchable(): bool
    {
        return true;
    }

    public function syncWithSearchUsing(): ?string
    {
        return null;
    }

    public function syncWithSearchUsingQueue(): ?string
    {
        return null;
    }

    public function makeSearchableUsing(mixed $models): mixed
    {
        return $models;
    }

    public function searchableSoftDeleted(): bool
    {
        return false;
    }
}
