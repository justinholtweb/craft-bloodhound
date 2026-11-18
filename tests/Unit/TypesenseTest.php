<?php

declare(strict_types=1);

use JustinHoltWeb\Bloodhound\Engines\TypesenseSchema;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;

$entries = fn () => new IndexDefinition('articles', 'entries', [], ['summary', 'customers', 'section', 'id']);
$assets = fn () => new IndexDefinition('media', 'assets', [], ['caption']);

it('searches the same fields on every engine: title, then index fields, never attributes', function () use ($entries, $assets) {
    expect($entries()->searchableAttributes())->toBe(['title', 'summary', 'customers'])
        ->and($assets()->searchableAttributes())->toBe(['title', 'filename', 'alt', 'caption'])
        ->and(TypesenseSchema::queryBy($entries()))->toBe('title,summary,customers');
});

it('declares a typed schema with every query_by field present up front', function () use ($entries, $assets) {
    $schema = TypesenseSchema::collection('prod_articles_en', $entries());
    $byName = array_column($schema['fields'], null, 'name');

    expect($schema['name'])->toBe('prod_articles_en')
        ->and($byName['summary']['type'])->toBe('string[]')
        ->and($byName['dateUpdated']['type'])->toBe('int64')
        ->and($byName['section']['type'])->toBe('string') // a custom field can't redeclare an attribute
        ->and($byName)->not->toHaveKey('id')
        ->and(array_count_values(array_column($schema['fields'], 'name')))->each->toBe(1);

    foreach (explode(',', TypesenseSchema::queryBy($assets())) as $field) {
        expect(array_column(TypesenseSchema::collection('m', $assets())['fields'], 'name'))->toContain($field);
    }
});

it('shapes documents the way Typesense accepts them', function () use ($entries) {
    $doc = TypesenseSchema::document([
        'id' => 8, 'title' => 'Hello', 'siteId' => 1, 'dateUpdated' => 1700000000, 'section' => 'news',
        'summary' => 42, 'customers' => [['Ada', ''], 'Linus', true, null],
    ], $entries());

    expect($doc['id'])->toBe('8')
        ->and($doc['siteId'])->toBe(1)
        ->and($doc['dateUpdated'])->toBe(1700000000)
        ->and($doc['summary'])->toBe(['42'])
        ->and($doc['customers'])->toBe(['Ada', 'Linus', 'true'])
        ->and(TypesenseSchema::document(['id' => 1, 'customers' => [null, ''], 'summary' => null], $entries()))->not->toHaveKeys(['customers', 'summary']);
});

it('quotes string filter values so they stay literal', function () {
    expect(TypesenseSchema::filterValue('news'))->toBe('`news`')
        ->and(TypesenseSchema::filterValue('a` || section:=b'))->toBe('`a || section:=b`')
        ->and(TypesenseSchema::filterValue('[a,b]'))->toBe('`[a,b]`')
        ->and(TypesenseSchema::filterValue('3'))->toBe('3')
        ->and(TypesenseSchema::filterValue(3))->toBe(3)
        ->and(TypesenseSchema::filterValue(true))->toBeTrue();
});
