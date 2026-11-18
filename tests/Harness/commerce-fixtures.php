<?php

// Bloodhound harness: a `gear` and a `books` product type, and four products. Idempotent.
// Run with: ddev artisan tinker --execute "require 'resources/views/bh/commerce-fixtures.php';"

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Commerce\Product\Elements\Product;
use CraftCms\Commerce\Product\ProductType\Data\ProductType;
use CraftCms\Commerce\Product\ProductType\Data\ProductTypeSite;
use CraftCms\Commerce\Product\ProductType\ProductTypes;
use CraftCms\Commerce\Product\Variant\Elements\Variant;

$types = app(ProductTypes::class);

foreach (['gear' => 'Gear', 'books' => 'Books'] as $handle => $name) {
    if ($types->getProductTypeByHandle($handle)) {
        continue;
    }

    $type = new ProductType;
    $type->name = $name;
    $type->handle = $handle;
    $type->skuFormat = null;
    $sites = [];
    foreach (Sites::getAllSites() as $site) {
        $s = new ProductTypeSite;
        $s->siteId = $site->id;
        $s->hasUrls = true;
        $s->uriFormat = "shop/{$handle}/{slug}";
        $s->template = 'shop/_product';
        $sites[$site->id] = $s;
    }
    $type->setSiteSettings($sites);
    $types->saveProductType($type) || throw new RuntimeException('product type: '.json_encode($type->errors()->all()));
}

$make = function (string $type, string $title, array $variants, bool $enabled = true) use ($types): int {
    $product = Product::find()->type($type)->title($title)->status(null)->one() ?? new Product;
    $product->typeId = $types->getProductTypeByHandle($type)->id;
    $product->title = $title;
    $product->enabled = $enabled;
    $list = [];
    foreach ($variants as $i => [$sku, $price]) {
        $v = $product->id ? (Variant::find()->sku($sku)->status(null)->one() ?? new Variant) : new Variant;
        $v->title = "{$title} {$i}";
        $v->setSku($sku);
        $v->setBasePrice($price);
        $v->isDefault = $i === 0;
        $list[] = $v;
    }
    $product->setVariants($list);
    Elements::saveElement($product) || throw new RuntimeException("{$title}: ".json_encode($product->errors()->all()));

    return (int) $product->id;
};

echo json_encode([
    'lamp' => $make('gear', 'Brass Lantern', [['LAMP-100', 49.5], ['LAMP-200', 79]]),
    'tent' => $make('gear', 'Alpine Tent', [['TENT-1', 320]]),
    'book' => $make('books', 'Lantern Making for Beginners', [['BOOK-7', 18]]),
    'off' => $make('gear', 'Retired Lantern', [['LAMP-OLD', 10]], false),
]);
