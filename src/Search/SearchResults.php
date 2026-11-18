<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Search;

use ArrayIterator;
use Countable;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use IteratorAggregate;
use Traversable;

/**
 * Ranked results: iterable (`{% for e in results %}` / `@foreach ($results as $e)`)
 * with paging info.
 *
 * @implements IteratorAggregate<int, ElementInterface>
 */
final class SearchResults implements Countable, IteratorAggregate
{
    /**
     * @param  list<ElementInterface>  $elements
     */
    public function __construct(
        public readonly array $elements,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $query,
        /** A generic, display-safe message when the engine failed; details are in the log. */
        public readonly ?string $error = null,
    ) {}

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function pages(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }
}
