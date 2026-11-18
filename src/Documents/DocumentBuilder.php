<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Documents;

use BackedEnum;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Commerce\Product\Elements\Product;
use CraftCms\Commerce\Product\Variant\Elements\Variant;
use DateTimeInterface;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use Stringable;

/**
 * Turns an element into a flat, engine-friendly document: text for searching,
 * plus a few filterable/sortable attributes. Relations become their titles, rich
 * text becomes plain text, dates become timestamps. Relations use the element's UI
 * label, so title-less elements (e.g. Eloquent elements) still read as their name.
 */
class DocumentBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(ElementInterface $element, IndexDefinition $index): array
    {
        $doc = [
            'id' => (int) $element->id,
            'title' => (string) $element->title,
            'url' => $element->getUrl(),
            'siteId' => (int) $element->siteId,
            'dateUpdated' => $element->dateUpdated?->getTimestamp(),
        ];

        if ($element instanceof Entry) {
            $doc += [
                'section' => $element->getSection()?->handle,
                'type' => $element->getType()->handle,
                'postDate' => $element->postDate?->getTimestamp(),
            ];
        } elseif ($element instanceof Asset) {
            $doc += ['volume' => $element->getVolume()->handle, 'kind' => $element->kind, 'filename' => $element->getFilename(), 'alt' => $element->alt];
        } elseif ($element instanceof User) {
            $doc = [...$doc, ...self::user($element)];
        } elseif (class_exists(Product::class) && $element instanceof Product) {
            $doc += [
                'productType' => $element->getType()->handle,
                'postDate' => $element->postDate?->getTimestamp(),
                'price' => $element->getDefaultPrice(),
                // Every enabled variant's SKU, default first, so a SKU search finds the product.
                'sku' => array_values(array_unique(array_filter([
                    $element->defaultSku,
                    ...$element->getVariants()->map(fn (Variant $v) => rescue(fn () => $v->getSku(), null, false))->all(),
                ], fn ($sku) => is_string($sku) && $sku !== ''))),
            ];
        }

        foreach ($index->fields as $handle) {
            if (! array_key_exists($handle, $doc)) {
                $doc[$handle] = self::value(rescue(fn () => $element->getFieldValue($handle), null, false));
            }
        }

        return $doc;
    }

    /**
     * A user's public face: full name (else username) and group handles. The email
     * address is never indexed, nor a username that is one (`useEmailAsUsername`).
     *
     * @return array{title: string, username: ?string, groups: list<string>}
     */
    private static function user(User $user): array
    {
        $username = $user->username !== null && ! str_contains($user->username, '@') ? $user->username : null;

        return [
            'title' => trim((string) $user->fullName) ?: (string) $username,
            'username' => $username,
            'groups' => array_values(array_map(fn ($group) => (string) $group->handle, $user->getGroups())),
        ];
    }

    public static function value(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => self::text($value),
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof ElementInterface => $value->getUiLabel(),
            $value instanceof ElementQuery => array_values(array_map(fn (ElementInterface $e) => $e->getUiLabel(), $value->limit(50)->all())),
            $value instanceof Stringable => self::text((string) $value),
            is_iterable($value) => array_values(array_filter(array_map(self::value(...), is_array($value) ? $value : iterator_to_array($value)), fn ($v) => $v !== null && $v !== '' && $v !== [])),
            default => null,
        };
    }

    /** Plain text from (possibly) HTML, whitespace collapsed, capped for engine limits. */
    public static function text(string $value): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h[1-6])\b[^>]*>/i', ' $0', $value) ?? $value), ENT_QUOTES | ENT_HTML5);

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 0, 20000);
    }
}
