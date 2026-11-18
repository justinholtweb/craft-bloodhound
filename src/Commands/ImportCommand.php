<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;

class ImportCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:bloodhound:import {index? : Index handle (default: all)} {--site= : Site handle (default: all)} {--fresh : Flush the engine index first}';

    protected $description = 'Indexes every live element of a Bloodhound index';

    public function handle(Indexes $indexes, Indexer $indexer): int
    {
        $targets = ($h = $this->argument('index')) ? array_filter([$indexes->get((string) $h)]) : array_values($indexes->all());

        if ($targets === []) {
            $this->components->error('No matching index.');

            return self::FAILURE;
        }

        $sites = ($s = $this->option('site')) ? [(string) $s] : $indexer->siteHandles();

        foreach ($targets as $index) {
            foreach ($sites as $site) {
                if ($this->option('fresh')) {
                    rescue(fn () => $indexer->flush($index, $site), report: false);
                }
                $n = $indexer->import($index, $site);
                $this->components->info("{$index->handle} ({$site}) → {$indexer->engineIndex($index, $site)}: {$n} documents");
            }
        }

        return self::SUCCESS;
    }
}
