#!/bin/bash
# Live checks against the Craft 6 harness. Engine: SCOUT_DRIVER=harness (tests/Harness/HarnessEngine.php.fixture,
# registered in the harness's AppServiceProvider) or meilisearch. Templates: tests/Harness/templates/* →
# resources/views/bh/. Uses the `articles` section from Momentum's harness fixtures.
set -uo pipefail
exec < /dev/null
H=/Users/jholt/Sites/plugin-testing-craft6
U=https://plugin-testing-craft6.ddev.site
cd "$H" || exit 2
pass=0 fail=0
ok() { if [ "$2" = "$3" ]; then echo "✓ $1"; pass=$((pass+1)); else echo "✗ $1 (got '$2', want '$3')"; fail=$((fail+1)); fi; }
tk() { ddev artisan tinker --execute "$1" 2>/dev/null | grep -v WARNING | tail -1; }
ids() { curl -sk "$U/bh/$1?q=$2&page=${3:-1}" | grep -oE 'data-id="[0-9]+"' | grep -oE '[0-9]+' | tr '\n' ',' | sed 's/,$//'; }
total() { curl -sk "$U/bh/$1?q=$2" | grep -oE '<p id="total">[0-9]+' | grep -oE '[0-9]+$'; }
settings() { tk "\$p=CraftCms\\Cms\\Support\\Facades\\Plugins::getPlugin('bloodhound'); CraftCms\\Cms\\Support\\Facades\\Plugins::savePluginSettings(\$p, $1); echo 1;" >/dev/null; }
entry() { tk "\$e=CraftCms\\Cms\\Entry\\Elements\\Entry::find()->slug('$1')->status(null)->one(); $2 echo CraftCms\\Cms\\Support\\Facades\\Elements::saveElement(\$e) ? 'saved' : json_encode(\$e->errors()->all());"; }

settings "['queueUpdates' => false, 'indexPrefix' => 'harness_', 'indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers']]]"
entry second-article-2 '$e->enabled = true; $e->setFieldValue("summary", "The related one.");' >/dev/null
ddev artisan craft:bloodhound:import articles --fresh >/dev/null 2>&1

for e in twig blade; do
  ok "$e: finds by title" "$(ids $e momentum)" "8,2"
  ok "$e: finds by a related element's name" "$(ids $e johnson)" "8"
  ok "$e: pagination (page 2 of 2)" "$(ids $e article 2)" "8"
  ok "$e: no query → no results" "$(total $e '')" "0"
done
ok "Twig and Blade return the same hits" "$(ids twig article)" "$(ids blade article)"

ok "edit an entry (sync mode)" "$(entry second-article-2 '$e->setFieldValue("summary", "Now about zeppelins.");')" "saved"
ok "edit is searchable immediately" "$(ids twig zeppelins)" "10"
ok "old text no longer matches" "$(total twig 'related+one')" "0"

ok "disable the entry" "$(entry second-article-2 '$e->enabled = false;')" "saved"
ok "disabled entry leaves the index" "$(total twig zeppelins)" "0"
ok "re-enable the entry" "$(entry second-article-2 '$e->enabled = true;')" "saved"
ok "re-enabled entry is back" "$(ids blade zeppelins)" "10"

# A stale document (engine out of step) must never surface a non-live element.
tk '$e=CraftCms\Cms\Entry\Elements\Entry::find()->slug("second-article-2")->one(); Illuminate\Support\Facades\DB::table("elements_sites")->where("elementId",$e->id)->update(["enabled"=>false]); echo 1;' >/dev/null
ok "stale document for a disabled entry is filtered out on hydrate" "$(ids twig zeppelins)" ""
tk '$e=CraftCms\Cms\Entry\Elements\Entry::find()->slug("second-article-2")->status(null)->one(); Illuminate\Support\Facades\DB::table("elements_sites")->where("elementId",$e->id)->update(["enabled"=>true]); echo 1;' >/dev/null

# Scheduled publishing: Craft fires no event when a post date passes.
entry second-article-2 '$e->postDate = new DateTime("+1 day");' >/dev/null
ok "future post date → not searchable" "$(total twig zeppelins)" "0"
tk '$e=CraftCms\Cms\Entry\Elements\Entry::find()->slug("second-article-2")->status(null)->one(); Illuminate\Support\Facades\DB::table("entries")->where("id",$e->id)->update(["postDate"=>now()->subMinutes(5)->utc()->format("Y-m-d H:i:s")]); echo 1;' >/dev/null
ok "post date passes silently → still not indexed" "$(total twig zeppelins)" "0"
ddev artisan craft:bloodhound:sync-scheduled --since="-1 hour" >/dev/null 2>&1
ok "sync-scheduled picks it up" "$(ids twig zeppelins)" "10"

# Queued mode
settings "['queueUpdates' => true]"
tk 'Illuminate\Support\Facades\DB::table("jobs")->where("payload","like","%SyncElements%")->delete(); echo 1;' >/dev/null
entry second-article-2 '$e->setFieldValue("summary", "Queued about airships.");' >/dev/null
ok "queued mode: not indexed until the job runs" "$(total twig airships)" "0"
ok "queued mode: one job per site queued" "$(tk 'echo Illuminate\Support\Facades\DB::table("jobs")->where("payload","like","%SyncElements%")->count();')" "1"
ddev artisan queue:work --queue=default --stop-when-empty >/dev/null 2>&1
ok "queued mode: indexed after the job" "$(ids twig airships)" "10"
settings "['queueUpdates' => false]"

# Engine outage: searches degrade to "no results"; saves still succeed.
cp .env /tmp/bh-env.bak; sed -i '' 's/^SCOUT_DRIVER=.*/SCOUT_DRIVER=meilisearch/' .env; ddev exec "grep -q '^MEILISEARCH_HOST=' .env || true" >/dev/null
sleep 1
ok "engine down: search page still 200" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/bh/twig?q=airships")" "200"
ok "engine down: results are empty with an error" "$(curl -sk "$U/bh/blade?q=airships" | grep -c '<p id="error">[^<]')" "1"
ok "engine down: saving an entry still works" "$(entry second-article-2 '$e->setFieldValue("summary", "Queued about airships.");')" "saved"
cp /tmp/bh-env.bak .env; sleep 1

J=$("$H/bin/cp-session")
ok "settings page loads" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' "$U/admin/settings/plugins/bloodhound")" "200"

entry second-article-2 '$e->setFieldValue("summary", "The related one.");' >/dev/null
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
