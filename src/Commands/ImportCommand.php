<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Plugin;
use JustinHoltWeb\Bloodhound\Sync\Indexer;
use LogicException;

class ImportCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:bloodhound:import {index? : Index handle (default: all)} {--site= : Site handle (default: all)} {--fresh : Flush the engine index first} {--swap : Pro: build a fresh index and swap it in (no gap in search)}';

    protected $description = 'Indexes every live element of a Bloodhound index';

    public function handle(Indexes $indexes, Indexer $indexer): int
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

        if ($this->option('swap') && ! Plugin::isPro()) {
            $this->components->error('--swap needs Bloodhound Pro.');

            return self::FAILURE;
        }

        if ($this->option('swap') && $this->option('fresh')) {
            $this->components->error('Use --swap or --fresh, not both.');

            return self::FAILURE;
        }

        try {
            $indexer->engine();
        } catch (LogicException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $sites = ($s = $this->option('site')) ? [(string) $s] : $indexer->siteHandles();

        foreach ($targets as $index) {
            foreach ($sites as $site) {
                if ($this->option('fresh')) {
                    rescue(fn () => $indexer->flush($index, $site), report: false);
                }
                $n = $this->option('swap') ? $indexer->rebuildWithSwap($index, $site) : $indexer->import($index, $site);
                $this->components->info("{$index->handle} ({$site}) → {$indexer->engineIndex($index, $site)}: {$n} documents");
            }
        }

        return self::SUCCESS;
    }
}
