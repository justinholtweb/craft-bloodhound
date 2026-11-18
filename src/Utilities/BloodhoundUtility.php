<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Utilities;

use CraftCms\Cms\Utility\Utility;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Bloodhound\Health\IndexHealth;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;
use Override;
use Throwable;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * Utilities → Bloodhound: per index and site, how the engine has drifted from
 * Craft, with Repair (just the drift) and Rebuild (everything).
 */
class BloodhoundUtility extends Utility
{
    #[Override]
    public static function id(): string
    {
        return 'bloodhound';
    }

    public static function displayName(): string
    {
        return t('Bloodhound', category: 'bloodhound');
    }

    #[Override]
    public static function icon(): ?string
    {
        return 'magnifying-glass';
    }

    #[Override]
    public static function contentHtml(): string
    {
        $rows = [];
        $error = null;

        try {
            $health = app(IndexHealth::class);
            $indexer = app(Indexer::class);
            foreach (app(Indexes::class)->all() as $index) {
                foreach ($indexer->siteHandles() as $site) {
                    $rows[] = $health->report($index, $site);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Bloodhound health check failed: '.$e->getMessage());
            $error = t('The search engine couldn’t be reached. Check SCOUT_DRIVER and its connection settings; details are in the log.', category: 'bloodhound');
        }

        return template('bloodhound/_utility', [
            'rows' => $rows,
            'error' => $error,
            'driver' => (string) config('scout.driver'),
            'repairUrl' => cp_url('bloodhound/repair'),
            'rebuildUrl' => cp_url('bloodhound/rebuild'),
        ]);
    }
}
