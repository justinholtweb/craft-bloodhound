<?php

declare(strict_types=1);

use JustinHoltWeb\Bloodhound\Settings;

it('has sensible defaults', function () {
    $settings = new Settings;

    expect($settings->indexPrefix)->toBe(null);
});
