<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Commerce\Product\Elements\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;

/**
 * Craft saves nothing when a scheduled entry or product goes live or expires,
 * so no save event fires. This catches those whose post or expiry date passed
 * since the last run (scheduled every 15 minutes).
 */
class SyncScheduledCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:bloodhound:sync-scheduled {--since= : Override the window start (any strtotime string)}';

    protected $description = 'Indexes entries and products that went live, and removes those that expired, since the last run';

    private const string CACHE_KEY = 'bloodhound.syncScheduled.lastRun';

    public function handle(Indexes $indexes, Indexer $indexer): int
    {
        $now = now();
        $since = $this->option('since') ? now()->parse((string) $this->option('since')) : Cache::get(self::CACHE_KEY, $now->copy()->subHour());
        $total = 0;

        foreach ($indexes->all() as $index) {
            if (! in_array($index->elementType, ['entries', 'products'], true)) {
                continue;
            }

            foreach ($indexer->siteHandles() as $site) {
                $base = $index->elementType === 'products'
                    ? fn () => Product::find()->site($site)->status(null)->type($index->sources ?: null)
                    : fn () => Entry::find()->site($site)->status(null)->section($index->sources ?: null);
                $ids = array_values(array_unique(array_map(intval(...), array_merge(
                    $base()->postDate(['and', '> '.$since->toIso8601String(), '<= '.$now->toIso8601String()])->ids(),
                    $base()->expiryDate(['and', '> '.$since->toIso8601String(), '<= '.$now->toIso8601String()])->ids(),
                ))));

                $indexer->sync($index, $site, $ids);
                $total += count($ids);
            }
        }

        Cache::forever(self::CACHE_KEY, $now);
        $this->components->info("Re-synced {$total} scheduled/expired entries and products.");

        return self::SUCCESS;
    }
}
