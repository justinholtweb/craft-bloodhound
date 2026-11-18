<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Indexes;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Commerce\Product\Elements\Product;
use CraftCms\Commerce\Product\Variant\Elements\Variant;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Bloodhound\Settings;

/**
 * The configured indexes, and the element query behind each.
 */
class Indexes
{
    /** @var array<string, true> Indexes already warned about, so the log isn't flooded. */
    private static array $warned = [];

    public function __construct(private readonly Settings $settings) {}

    /**
     * Product indexes are left out while Commerce isn't installed and enabled,
     * so the rest keep working (a warning is logged).
     *
     * @return array<string, IndexDefinition>
     */
    public function all(): array
    {
        $indexes = [];

        foreach ($this->settings->indexes ?? [] as $row) {
            if (! is_array($row) || ($index = IndexDefinition::fromRow($row)) === null) {
                continue;
            }

            if ($index->elementType === 'products' && ! self::commerceAvailable()) {
                isset(self::$warned[$index->handle]) || Log::warning("Bloodhound: index “{$index->handle}” holds products, but Craft Commerce isn't installed and enabled; skipping it.");
                self::$warned[$index->handle] = true;

                continue;
            }

            $indexes[$index->handle] ??= $index;
        }

        return $indexes;
    }

    public function get(string $handle): ?IndexDefinition
    {
        return $this->all()[$handle] ?? null;
    }

    public static function commerceAvailable(): bool
    {
        return class_exists(Product::class) && Plugins::isPluginEnabled('commerce');
    }

    /**
     * Live, canonical elements that belong in the index for a site. Only these are
     * ever indexed, so search can't surface drafts, disabled, expired or
     * suspended content.
     *
     * @return ElementQuery<covariant ElementInterface>
     */
    public function query(IndexDefinition $index, string $siteHandle): ElementQuery
    {
        $query = match ($index->elementType) {
            'assets' => Asset::find(),
            // Active only: pending, suspended, locked and inactive accounts stay out.
            'users' => User::find()->status(User::STATUS_ACTIVE),
            'products' => Product::find()->status(Product::STATUS_LIVE),
            default => Entry::find()->status(Entry::STATUS_LIVE),
        };

        $query->site($siteHandle);

        match ($index->elementType) {
            'assets' => $index->sources !== [] ? $query->volume($index->sources) : null,
            'users' => $index->sources !== [] ? $query->group($index->sources) : null,
            'products' => $index->sources !== [] ? $query->type($index->sources) : null,
            // Always constrain to sections: `section('*')` means "any section", which excludes nested
            // (Matrix) entries. Those can be live while their owner is disabled, so they'd leak.
            default => $query->section($index->sources ?: '*'),
        };

        return $query;
    }

    /**
     * The element an index holds for a changed element: a variant's change is
     * its product's change. Other elements stand for themselves.
     */
    public function indexable(ElementInterface $element): ?ElementInterface
    {
        if (class_exists(Variant::class) && $element instanceof Variant) {
            return rescue(fn () => $element->getProduct(), null, false);
        }

        return $element;
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
                // Any user may belong: group membership can just have changed, and a
                // sync of someone who isn't in the index's groups removes them.
                $element instanceof User => $index->elementType === 'users',
                class_exists(Product::class) && $element instanceof Product => $index->elementType === 'products'
                    && ($index->sources === [] || in_array($element->getType()->handle, $index->sources, true)),
                default => false,
            };
        }));
    }
}
