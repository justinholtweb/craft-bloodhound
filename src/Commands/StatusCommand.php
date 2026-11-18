<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Bloodhound\Health\IndexHealth;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;

class StatusCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:bloodhound:status {--fail-on-drift : Exit non-zero when any index has drifted (for CI/monitoring)}';

    protected $description = 'Shows how each index has drifted from Craft (missing, orphaned, stale documents)';

    public function handle(Indexes $indexes, Indexer $indexer, IndexHealth $health): int
    {
        $rows = [];
        $drift = 0;

        foreach ($indexes->all() as $index) {
            foreach ($indexer->siteHandles() as $site) {
                $r = $health->report($index, $site);
                $d = count($r['missing']) + count($r['orphaned']) + count($r['stale']);
                $drift += $d;
                $rows[] = [$r['engineIndex'], $r['live'], $r['supported'] ? ($r['exists'] ? $r['documents'] : 'not created') : 'n/a',
                    count($r['missing']), count($r['orphaned']), count($r['stale'])];
            }
        }

        $this->table(['Engine index', 'Live', 'Documents', 'Missing', 'Orphaned', 'Stale'], $rows);

        return $drift > 0 && $this->option('fail-on-drift') ? self::FAILURE : self::SUCCESS;
    }
}
