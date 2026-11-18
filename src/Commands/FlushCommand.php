<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;

class FlushCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:bloodhound:flush {index : Index handle} {--site= : Site handle (default: all)}';

    protected $description = 'Deletes a Bloodhound index from the search engine';

    public function handle(Indexes $indexes, Indexer $indexer): int
    {
        if (($s = $this->option('site')) && ! in_array((string) $s, $indexer->siteHandles(), true)) {
            $this->components->error("Unknown site “{$s}”. Sites: ".implode(', ', $indexer->siteHandles()).'.');

            return self::FAILURE;
        }

        $index = $indexes->get((string) $this->argument('index'));

        if ($index === null) {
            $this->components->error('Unknown index.');

            return self::FAILURE;
        }

        foreach (($s = $this->option('site')) ? [(string) $s] : $indexer->siteHandles() as $site) {
            $indexer->flush($index, $site);
            $this->components->info("Flushed {$indexer->engineIndex($index, $site)}");
        }

        return self::SUCCESS;
    }
}
