<?php

declare(strict_types=1);

use JustinHoltWeb\Bloodhound\Health\IndexHealth;

it('finds missing, orphaned and stale documents', function () {
    $live = [1 => 100, 2 => 200, 3 => 300, 4 => null];
    $docs = [2 => 200, 3 => 299, 4 => 5, 9 => 50];

    expect(IndexHealth::compare($live, $docs))->toBe([
        'missing' => [1],
        'orphaned' => [9],
        'stale' => [3],
    ]);
});

it('treats a document without dateUpdated as stale, and an equal one as fresh', function () {
    expect(IndexHealth::compare([1 => 100, 2 => 100], [1 => null, 2 => 100])['stale'])->toBe([1]);
});

it('reports nothing for an index in step', function () {
    expect(IndexHealth::compare([1 => 1, 2 => 2], [2 => 2, 1 => 1]))->toBe(['missing' => [], 'orphaned' => [], 'stale' => []]);
});

it('treats every live element as missing when the index does not exist', function () {
    expect(IndexHealth::compare([3 => 1, 1 => 1], [])['missing'])->toBe([1, 3]);
});
