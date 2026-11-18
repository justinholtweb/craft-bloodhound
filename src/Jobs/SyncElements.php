<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;

class SyncElements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  list<int>  $elementIds
     */
    public function __construct(public string $index, public string $siteHandle, public array $elementIds) {}

    public function handle(Indexes $indexes, Indexer $indexer): void
    {
        if (($index = $indexes->get($this->index)) !== null) {
            $indexer->sync($index, $this->siteHandle, $this->elementIds);
        }
    }
}
