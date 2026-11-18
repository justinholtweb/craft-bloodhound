<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Listeners;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Jobs\SyncElements;
use JustinHoltWeb\Bloodhound\Settings;
use JustinHoltWeb\Bloodhound\Sync\Indexer;
use Throwable;

/**
 * Saves and deletes re-sync the element in every index (and site) it belongs to.
 * The job re-reads the element, so a disable, expiry or delete removes it.
 */
class SyncOnElementChange
{
    public function __construct(
        private readonly Indexes $indexes,
        private readonly Indexer $indexer,
        private readonly Settings $settings,
    ) {}

    public function saved(ElementSaved $event): void
    {
        $this->handle($event->element);
    }

    public function deleted(ElementDeleted $event): void
    {
        $this->handle($event->element);
    }

    private function handle(ElementInterface $element): void
    {
        if ($element->id === null || $element->getIsDraft() || $element->getIsRevision() || $element->propagating) {
            return;
        }

        foreach ($this->indexes->for($element) as $index) {
            foreach ($this->indexer->siteHandles() as $site) {
                $job = new SyncElements($index->handle, $site, [(int) $element->id]);

                try {
                    $this->settings->queueUpdates ? dispatch($job)->afterCommit() : $job->handle($this->indexes, $this->indexer);
                } catch (Throwable $e) {
                    // A search outage must never block an editor's save.
                    report($e);
                }
            }
        }
    }

    /** @return array<class-string, string> */
    public function subscribe(): array
    {
        return [ElementSaved::class => 'saved', ElementDeleted::class => 'deleted'];
    }
}
