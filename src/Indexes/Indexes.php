<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Indexes;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use JustinHoltWeb\Bloodhound\Settings;

/**
 * The configured indexes, and the element query behind each.
 */
class Indexes
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, IndexDefinition> */
    public function all(): array
    {
        $indexes = [];

        foreach ($this->settings->indexes ?? [] as $row) {
            if (is_array($row) && ($index = IndexDefinition::fromRow($row)) !== null) {
                $indexes[$index->handle] ??= $index;
            }
        }

        return $indexes;
    }

    public function get(string $handle): ?IndexDefinition
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * Live, canonical elements that belong in the index for a site. Only these are
     * ever indexed, so search can't surface drafts, disabled or expired content.
     */
    /** @return ElementQuery<covariant ElementInterface> */
    public function query(IndexDefinition $index, string $siteHandle): ElementQuery
    {
        $query = match ($index->elementType) {
            'assets' => Asset::find(),
            default => Entry::find()->status(Entry::STATUS_LIVE),
        };

        $query->site($siteHandle);

        if ($index->sources !== []) {
            $index->elementType === 'assets' ? $query->volume($index->sources) : $query->section($index->sources);
        }

        return $query;
    }

    /**
     * The indexes an element belongs in (by type and source), ignoring status —
     * the caller decides between upsert and delete.
     *
     * @return list<IndexDefinition>
     */
    public function for(ElementInterface $element): array
    {
        return array_values(array_filter($this->all(), function (IndexDefinition $index) use ($element): bool {
            return match (true) {
                $element instanceof Entry => $index->elementType === 'entries'
                    && $element->getField() === null
                    && ($index->sources === [] || in_array($element->getSection()?->handle, $index->sources, true)),
                $element instanceof Asset => $index->elementType === 'assets'
                    && ($index->sources === [] || in_array($element->getVolume()->handle, $index->sources, true)),
                default => false,
            };
        }));
    }
}
