<?php

declare(strict_types=1);

namespace JustinHoltWeb\Bloodhound\Http\Controllers;

use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\Bloodhound\Health\IndexHealth;
use JustinHoltWeb\Bloodhound\Indexes\IndexDefinition;
use JustinHoltWeb\Bloodhound\Indexes\Indexes;
use JustinHoltWeb\Bloodhound\Jobs\RebuildIndex;

use function CraftCms\Cms\cp_url;

/**
 * POST admin/bloodhound/{repair|rebuild} — only for people who can use the
 * Bloodhound utility (see routes/cp.php). Unknown indexes or sites 404.
 */
class HealthActionController
{
    public function repair(Request $request, Indexes $indexes, IndexHealth $health): RedirectResponse
    {
        [$index, $site] = $this->target($request, $indexes);
        $n = $health->repair($index, $site);

        return redirect(cp_url('utilities/bloodhound'))->with('success', "{$index->handle} ({$site}): re-synced {$n} elements.");
    }

    public function rebuild(Request $request, Indexes $indexes): RedirectResponse
    {
        [$index, $site] = $this->target($request, $indexes);
        RebuildIndex::dispatch($index->handle, $site);

        return redirect(cp_url('utilities/bloodhound'))->with('success', "{$index->handle} ({$site}): rebuild queued.");
    }

    /** @return array{IndexDefinition, string} */
    private function target(Request $request, Indexes $indexes): array
    {
        $index = $indexes->get((string) $request->input('index')) ?? abort(404);
        $site = (string) $request->input('site');
        abort_unless(Sites::getSiteByHandle($site) !== null, 404);

        return [$index, $site];
    }
}
