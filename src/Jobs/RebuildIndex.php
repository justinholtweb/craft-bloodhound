<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Plugin;
use JustinHoltWeb\Bloodhound\Sync\Indexer;

class RebuildIndex implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    /**
     * Unique locks are only released when the job finishes; without an expiry a
     * cleared job or crashed worker would block rebuilds of this index for good.
     */
    public int $uniqueFor = 3600;

    public function __construct(public string $index, public string $siteHandle) {}

    public function uniqueId(): string
    {
        return $this->index.'@'.$this->siteHandle;
    }

    public function handle(Indexes $indexes, Indexer $indexer): void
    {
        if (($index = $indexes->get($this->index)) !== null) {
            // Pro rebuilds into a fresh index and swaps it in, so search never goes empty.
            Plugin::isPro() ? $indexer->rebuildWithSwap($index, $this->siteHandle) : $indexer->import($index, $this->siteHandle);
        }
    }
}
