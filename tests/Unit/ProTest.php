<?php

declare(strict_types=1);

use JustinHoltWeb\Bloodhound\Search\CraftSearch;
use JustinHoltWeb\Bloodhound\Settings;
use JustinHoltWeb\Bloodhound\Sync\Indexer;

it('reads synonym rows into groups, ignoring blanks and self-references', function () {
    $settings = new Settings;
    $settings->synonyms = [
        ['term' => ' Sofa ', 'synonyms' => 'couch, settee, sofa,'],
        ['term' => 'sofa', 'synonyms' => 'divan'],
        ['term' => '', 'synonyms' => 'orphan'],
        ['term' => 'lonely', 'synonyms' => ''],
        'not a row',
    ];

    expect($settings->synonymMap())->toBe(['sofa' => ['couch', 'settee', 'divan']]);
});

it('writes synonym groups out in every direction', function () {
    expect(Indexer::mutualSynonyms(['sofa' => ['couch', 'settee']]))->toBe([
        'sofa' => ['couch', 'settee'],
        'couch' => ['sofa', 'settee'],
        'settee' => ['sofa', 'couch'],
    ]);
});

it('splits stop words on commas and new lines', function () {
    $settings = new Settings;
    $settings->stopWords = "The, a\nof,,\n";

    expect($settings->stopWordList())->toBe(['the', 'a', 'of']);
});

it('leaves Craft search syntax to Craft', function (string $query, bool $craft) {
    expect(CraftSearch::usesCraftSyntax($query))->toBe($craft);
})->with([
    ['airships', false],
    ['red sofa', false],
    ['e-mail marketing', false],
    ['title:sofa', true],
    ['sofa -red', true],
    ['"red sofa"', true],
    ['sof*', true],
    ['sofa OR couch', true],
]);
