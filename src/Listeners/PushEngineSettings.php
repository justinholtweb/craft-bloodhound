<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Listeners;

use CraftCms\Cms\Plugin\Events\PluginSettingsSaved;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Sync\Indexer;
use Throwable;

/**
 * Saving Bloodhound's settings pushes index settings (searchable fields, filters,
 * synonyms) to the engine straight away, so a synonym edit doesn't wait for the
 * next import. An unreachable engine only logs; the settings are still saved.
 */
class PushEngineSettings
{
    public function __construct(
        private readonly Indexes $indexes,
        private readonly Indexer $indexer,
    ) {}

    public function handle(PluginSettingsSaved $event): void
    {
        if ($event->plugin->handle !== 'bloodhound') {
            return;
        }

        foreach ($this->indexes->all() as $index) {
            foreach ($this->indexer->siteHandles() as $site) {
                try {
                    $this->indexer->prepare($this->indexer->engineIndex($index, $site), $index);
                } catch (Throwable $e) {
                    Log::warning("Bloodhound: couldn't push settings for {$index->handle} ({$site}): {$e->getMessage()}");
                }
            }
        }
    }
}
