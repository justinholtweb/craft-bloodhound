<?php

declare(strict_types=1);

use Illuminate\Support\HtmlString;
use JustinHoltWeb\Bloodhound\Documents\DocumentBuilder;
use JustinHoltWeb\Bloodhound\Documents\SearchDocument;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use JustinHoltWeb\Bloodhound\Search\SearchResults;
use JustinHoltWeb\Bloodhound\Settings;

it('parses index rows and rejects bad ones', function () {
    $index = IndexDefinition::fromRow(['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'news, blog news', 'fields' => "summary\nbody"]);

    expect($index?->sources)->toBe(['news', 'blog'])
        ->and($index?->fields)->toBe(['summary', 'body'])
        ->and($index?->engineIndexName('prod_', 'en'))->toBe('prod_articles_en')
        ->and(IndexDefinition::fromRow(['handle' => 'Articles']))->toBeNull()
        ->and(IndexDefinition::fromRow(['handle' => 'articles', 'elementType' => 'users']))->toBeNull()
        ->and(IndexDefinition::fromRow(['handle' => 'a-b']))->toBeNull();
});

it('flattens field values into engine-friendly text', function () {
    expect(DocumentBuilder::value('<p>Hello <b>there</b></p><p>world</p>'))->toBe('Hello there world')
        ->and(DocumentBuilder::value(new HtmlString('Fish &amp; chips')))->toBe('Fish & chips')
        ->and(DocumentBuilder::value(new DateTimeImmutable('@1700000000')))->toBe(1700000000)
        ->and(DocumentBuilder::value(['a', '', null, ['b']]))->toBe(['a', ['b']])
        ->and(DocumentBuilder::value(new stdClass))->toBeNull()
        ->and(mb_strlen(DocumentBuilder::text(str_repeat('x ', 30000))))->toBe(20000);
});

it('presents a document the way Scout engines expect', function () {
    $doc = SearchDocument::for('prod_articles_en', ['id' => 42, 'title' => 'T']);

    expect($doc->indexableAs())->toBe('prod_articles_en')
        ->and($doc->searchableAs())->toBe('prod_articles_en')
        ->and($doc->getScoutKey())->toBe(42)
        ->and($doc->getScoutKeyName())->toBe('id')
        ->and($doc->toSearchableArray())->toBe(['id' => 42, 'title' => 'T'])
        ->and($doc->scoutMetadata())->toBe([]);
});

it('pages results', function () {
    $r = new SearchResults([], 45, 2, 20, 'q');

    expect($r->pages())->toBe(3)->and($r->hasNext())->toBeTrue()->and($r->hasPrevious())->toBeTrue()
        ->and(new SearchResults([], 0, 1, 20, 'q')->pages())->toBe(0);
});

it('only accepts identifier-safe index prefixes', function () {
    $s = new Settings;
    $s->indexPrefix = 'prod_';
    expect($s->prefix())->toBe('prod_');
    $s->indexPrefix = 'bad prefix/..';
    expect($s->prefix())->toBeNull();
});
