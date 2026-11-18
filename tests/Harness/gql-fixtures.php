<?php

// Bloodhound harness: GraphQL schemas + tokens (idempotent). Token = "bh{name}" padded to 32 chars.
//   full      articles section, staff group, the default site, the Bloodhound indexes (+ gear products)
//   nosection the Bloodhound indexes (articles, shop) and the site, but no section or product type
//   noindex   the articles section and the site, but no Bloodhound index
// Run with: ddev artisan tinker --execute "require 'storage/bh-gql-fixtures.php';"

use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Data\GqlToken;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Commerce\Product\ProductType\ProductTypes;

$site = 'sites.'.Sites::getPrimarySite()->uid.':read';
$articles = 'sections.'.Sections::getSectionByHandle('articles')->uid.':read';
$staff = 'usergroups.'.UserGroups::getGroupByHandle('staff')->uid.':read';

// With Commerce (and commerce-fixtures.php run): the `gear` product type and the `shop` index too.
$gear = class_exists(ProductTypes::class)
    ? app(ProductTypes::class)->getProductTypeByHandle('gear')
    : null;
$products = $gear ? ['productTypes.'.$gear->uid.':read', 'bloodhound.shop:read'] : [];

$schemas = [
    'full' => [$site, $articles, $staff, 'bloodhound.articles:read', 'bloodhound.people:read', ...$products],
    'nosection' => [$site, 'bloodhound.articles:read', ...($gear ? ['bloodhound.shop:read'] : [])],
    'noindex' => [$site, $articles],
];

foreach ($schemas as $name => $scope) {
    $schema = collect(Gql::getSchemas())->first(fn ($s) => $s->name === "bh_{$name}") ?? new GqlSchema;
    $schema->name = "bh_{$name}";
    $schema->scope = $scope;
    Gql::saveSchema($schema) || throw new RuntimeException(json_encode($schema->errors()->all()));

    $token = collect(Gql::getTokens())->first(fn ($t) => $t->name === "bh_{$name}") ?? new GqlToken;
    $token->name = "bh_{$name}";
    $token->schemaId = $schema->id;
    $token->accessToken = str_pad("bh{$name}", 32, '0');
    Gql::saveToken($token) || throw new RuntimeException(json_encode($token->errors()->all()));
}

Gql::invalidateCaches();
echo 'ok';
