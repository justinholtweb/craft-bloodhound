<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound;

use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /** Index prefix. Prepended to every index name, e.g. the environment ($CRAFT_ENVIRONMENT). */
    public ?string $indexPrefix = null;

    /** Queue index updates. Push index updates to the queue instead of doing them during the save request. */
    public bool $queueUpdates = true;

    /** Take over Craft search. Answer element queries' search param from the Scout engine instead of Craft's own search index (Pro). */
    public bool $takeOverSearch = false;
}
