<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Listeners;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\User\Events\UserActivated;
use CraftCms\Cms\User\Events\UserAssignedToGroups;
use CraftCms\Cms\User\Events\UserDeactivated;
use CraftCms\Cms\User\Events\UserEvent;
use CraftCms\Cms\User\Events\UserSuspended;
use CraftCms\Cms\User\Events\UserUnsuspended;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Jobs\SyncElements;
use JustinHoltWeb\Bloodhound\Settings;
use JustinHoltWeb\Bloodhound\Sync\Indexer;
use Throwable;

/**
 * Saves and deletes re-sync the element in every index (and site) it belongs to.
 * The job re-reads the element, so a disable, expiry or delete removes it.
 *
 * A variant's change re-syncs its product. Users also change without a save
 * (group assignment, suspension, activation), so those events re-sync them too.
 */
class SyncOnElementChange
{
    /** @var array<string, true> Syncs already started this request (a product save also saves each variant). */
    private array $started = [];

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

    public function userChanged(UserEvent $event): void
    {
        $this->handle($event->user);
    }

    public function userGroupsChanged(UserAssignedToGroups $event): void
    {
        if (($user = User::find()->id($event->userId)->status(null)->one()) !== null) {
            $this->handle($user);
        }
    }

    private function handle(ElementInterface $element): void
    {
        if ($element->id === null || $element->getIsDraft() || $element->getIsRevision() || $element->propagating) {
            return;
        }

        $element = $this->indexes->indexable($element);

        if ($element?->id === null || $element->getIsDraft() || $element->getIsRevision()) {
            return;
        }

        foreach ($this->indexes->for($element) as $index) {
            foreach ($this->indexer->siteHandles() as $site) {
                $key = "{$index->handle}|{$site}|{$element->id}";

                if (isset($this->started[$key]) && $this->settings->queueUpdates) {
                    continue; // already queued; the job reads the element when it runs
                }

                $this->started[$key] = true;
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
        return [
            ElementSaved::class => 'saved',
            ElementDeleted::class => 'deleted',
            UserAssignedToGroups::class => 'userGroupsChanged',
            UserActivated::class => 'userChanged',
            UserDeactivated::class => 'userChanged',
            UserSuspended::class => 'userChanged',
            UserUnsuspended::class => 'userChanged',
        ];
    }
}
