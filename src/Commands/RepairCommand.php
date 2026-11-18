<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Bloodhound\Health\IndexHealth;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;

class RepairCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:bloodhound:repair {index? : Index handle (default: all)} {--site= : Site handle (default: all)} {--dry-run : Report what would be re-synced}';

    protected $description = 'Re-syncs only the elements whose documents are missing, orphaned or stale';

    public function handle(Indexes $indexes, Indexer $indexer, IndexHealth $health): int
    {
        if (($s = $this->option('site')) && ! in_array((string) $s, $indexer->siteHandles(), true)) {
            $this->components->error("Unknown site “{$s}”. Sites: ".implode(', ', $indexer->siteHandles()).'.');

            return self::FAILURE;
        }

        $targets = ($h = $this->argument('index')) ? array_filter([$indexes->get((string) $h)]) : array_values($indexes->all());

        if ($targets === []) {
            $this->components->error('No matching index.');

            return self::FAILURE;
        }

        foreach ($targets as $index) {
            foreach (($s = $this->option('site')) ? [(string) $s] : $indexer->siteHandles() as $site) {
                $n = $health->repair($index, $site, (bool) $this->option('dry-run'));
                $this->components->info(sprintf('%s (%s): %d %s', $index->handle, $site, $n, $this->option('dry-run') ? 'would be re-synced' : 're-synced'));
            }
        }

        return self::SUCCESS;
    }
}
