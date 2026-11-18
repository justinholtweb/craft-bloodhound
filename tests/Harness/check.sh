#!/bin/bash
# Live checks against the Craft 6 harness. Engine: SCOUT_DRIVER=meilisearch (kevinquillen/ddev-meilisearch add-on,
# MEILISEARCH_HOST=http://meilisearch:7700, MEILISEARCH_KEY=ddev) or SCOUT_DRIVER=harness (tests/Harness/HarnessEngine.php.fixture,
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
settle() { ddev exec "for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -H 'Authorization: Bearer ${MEILI_KEY:-ddev}' 'http://meilisearch:7700/tasks?statuses=enqueued,processing&limit=1' 2>/dev/null | grep -q '\"total\":0' && break; sleep 0.3; done" >/dev/null 2>&1 || sleep 1; }
entry() { tk "\$e=CraftCms\\Cms\\Entry\\Elements\\Entry::find()->slug('$1')->status(null)->one(); $2 echo CraftCms\\Cms\\Support\\Facades\\Elements::saveElement(\$e) ? 'saved' : json_encode(\$e->errors()->all());"; }

settings "['queueUpdates' => false, 'indexPrefix' => 'harness_', 'indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers']]]"
entry second-article-2 '$e->enabled = true; $e->setFieldValue("summary", "The related one.");' >/dev/null
ddev artisan craft:bloodhound:import articles --fresh >/dev/null 2>&1; settle

for e in twig blade; do
  ok "$e: finds by title" "$(ids $e momentum)" "8,2"
  ok "$e: finds by a related element's name" "$(ids $e johnson)" "8"
  p1=$(ids $e article 1); p2=$(ids $e article 2)
  ok "$e: pagination (2 + 1 hits, no overlap, all 3 articles)" "$(echo "$p1" | tr ',' '\n' | wc -l | tr -d ' ')/$(echo "$p2" | tr ',' '\n' | wc -l | tr -d ' ')/$(echo "$p1,$p2" | tr ',' '\n' | sort -n | uniq | tr '\n' ' ')" "2/1/2 8 10 "
  ok "$e: no query → no results" "$(total $e '')" "0"
done
ok "filter on a filterable attribute (section=articles)" "$(curl -sk "$U/bh/twig?q=momentum&section=articles" | grep -oE '<p id="total">[0-9]+' | grep -oE '[0-9]+$')" "2"
ok "filter that matches nothing (section=nope)" "$(curl -sk "$U/bh/blade?q=momentum&section=nope" | grep -oE '<p id="total">[0-9]+' | grep -oE '[0-9]+$')" "0"
ok "Twig and Blade return the same hits" "$(ids twig article)" "$(ids blade article)"

ok "edit an entry (sync mode)" "$(entry second-article-2 '$e->setFieldValue("summary", "Now about zeppelins.");')" "saved"
settle
ok "edit is searchable immediately" "$(ids twig zeppelins)" "10"
ok "old text no longer matches" "$(total twig 'related+one')" "0"

ok "disable the entry" "$(entry second-article-2 '$e->enabled = false;')" "saved"
settle
ok "disabled entry leaves the index" "$(total twig zeppelins)" "0"
ok "re-enable the entry" "$(entry second-article-2 '$e->enabled = true;')" "saved"
settle
ok "re-enabled entry is back" "$(ids blade zeppelins)" "10"

# A stale document (engine out of step) must never surface a non-live element.
tk '$e=CraftCms\Cms\Entry\Elements\Entry::find()->slug("second-article-2")->one(); Illuminate\Support\Facades\DB::table("elements_sites")->where("elementId",$e->id)->update(["enabled"=>false]); echo 1;' >/dev/null
ok "stale document for a disabled entry is filtered out on hydrate" "$(ids twig zeppelins)" ""
tk '$e=CraftCms\Cms\Entry\Elements\Entry::find()->slug("second-article-2")->status(null)->one(); Illuminate\Support\Facades\DB::table("elements_sites")->where("elementId",$e->id)->update(["enabled"=>true]); echo 1;' >/dev/null

# Scheduled publishing: Craft fires no event when a post date passes.
entry second-article-2 '$e->postDate = new DateTime("+1 day");' >/dev/null; settle
ok "future post date → not searchable" "$(total twig zeppelins)" "0"
tk '$e=CraftCms\Cms\Entry\Elements\Entry::find()->slug("second-article-2")->status(null)->one(); Illuminate\Support\Facades\DB::table("entries")->where("id",$e->id)->update(["postDate"=>now()->subMinutes(5)->utc()->format("Y-m-d H:i:s")]); echo 1;' >/dev/null
ok "post date passes silently → still not indexed" "$(total twig zeppelins)" "0"
ddev artisan craft:bloodhound:sync-scheduled --since="-1 hour" >/dev/null 2>&1; settle
ok "sync-scheduled picks it up" "$(ids twig zeppelins)" "10"

# Queued mode
settings "['queueUpdates' => true]"
tk 'Illuminate\Support\Facades\DB::table("jobs")->where("payload","like","%SyncElements%")->delete(); echo 1;' >/dev/null
entry second-article-2 '$e->setFieldValue("summary", "Queued about airships.");' >/dev/null; settle
ok "queued mode: not indexed until the job runs" "$(total twig airships)" "0"
ok "queued mode: one job per site queued" "$(tk 'echo Illuminate\Support\Facades\DB::table("jobs")->where("payload","like","%SyncElements%")->count();')" "1"
ddev artisan queue:work --queue=default --stop-when-empty >/dev/null 2>&1; settle
ok "queued mode: indexed after the job" "$(ids twig airships)" "10"
settings "['queueUpdates' => false]"

# Engine outage: searches degrade to "no results"; saves still succeed.
cp .env /tmp/bh-env.bak; sed -i '' 's/^SCOUT_DRIVER=.*/SCOUT_DRIVER=meilisearch/; s#^MEILISEARCH_HOST=.*#MEILISEARCH_HOST=http://127.0.0.1:1#' .env; grep -q '^MEILISEARCH_HOST=' .env || echo 'MEILISEARCH_HOST=http://127.0.0.1:1' >> .env
sleep 1
ok "engine down: search page still 200" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/bh/twig?q=airships")" "200"
ok "engine down: results are empty with a generic error" "$(curl -sk "$U/bh/blade?q=airships" | grep -o '<p id="error">[^<]*' | sed 's/<p id="error">//')" "Search is temporarily unavailable."
ok "engine down: no engine internals in the page" "$(curl -sk "$U/bh/blade?q=airships" | grep -ciE 'meilisearch|exception|127\.0\.0\.1|class ')" "0"
ok "engine down: saving an entry still works" "$(entry second-article-2 '$e->setFieldValue("summary", "Queued about airships.");')" "saved"
settle
cp /tmp/bh-env.bak .env; sleep 1

# ---- Phase 2: index health + repair (engine-neutral: injects drift through Bloodhound's own engine) ----
ddev artisan craft:bloodhound:import articles --fresh >/dev/null 2>&1; settle
inject() { tk "\$e=app(Laravel\\Scout\\EngineManager::class)->engine(); \$n=app(JustinHoltWeb\\Bloodhound\\Sync\\Indexer::class)->engineIndex(app(JustinHoltWeb\\Bloodhound\\Indexes\\Indexes::class)->get('articles'),'default'); \$c=fn(\$a)=>new Illuminate\\Database\\Eloquent\\Collection([JustinHoltWeb\\Bloodhound\\Documents\\SearchDocument::for(\$n,\$a)]); \$e->update(\$c(['id'=>99999,'title'=>'ghost','dateUpdated'=>1])); \$e->delete(\$c(['id'=>10])); \$e->update(\$c(['id'=>8,'title'=>'Hello Momentum','dateUpdated'=>1])); echo 1;" >/dev/null; settle; }
counts() { ddev artisan craft:bloodhound:status 2>/dev/null | grep "_articles_default" | awk -F'|' '{gsub(/ /,""); print $5"/"$6"/"$7}'; }
ok "fresh import: no drift" "$(counts)" "0/0/0"
inject
ok "status reports missing/orphaned/stale" "$(counts)" "1/1/1"
ddev artisan craft:bloodhound:status --fail-on-drift >/dev/null 2>&1; ok "--fail-on-drift exits non-zero on drift" "$?" "1"
ok "repair --dry-run counts the drift" "$(ddev artisan craft:bloodhound:repair articles --site=default --dry-run 2>/dev/null | grep -oE '[0-9]+ would')" "3 would"
ok "dry run changes nothing" "$(counts)" "1/1/1"
ddev artisan craft:bloodhound:repair articles --site=default >/dev/null 2>&1; settle
ok "repair fixes exactly the drift" "$(counts)" "0/0/0"
ddev artisan craft:bloodhound:status --fail-on-drift >/dev/null 2>&1; ok "--fail-on-drift exits zero when clean" "$?" "0"
ok "repaired orphan no longer searchable" "$(total twig ghost)" "0"

J=$("$H/bin/cp-session")
XSRF=$(grep XSRF-TOKEN "$J" | awk '{print $7}' | python3 -I -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
post() { curl -sk -b "$J" -c "$J" -X POST -H "X-XSRF-TOKEN: $XSRF" -o /dev/null -w '%{http_code}' "$@"; }
ok "utility page loads" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' "$U/admin/utilities/bloodhound")" "200"
inject
ok "CP Repair action" "$(post -d index=articles -d site=default "$U/admin/bloodhound/repair")" "302"
settle
ok "CP Repair fixed the drift" "$(counts)" "0/0/0"
ok "CP action: unknown index 404s" "$(post -d index=nope -d site=default "$U/admin/bloodhound/repair")" "404"
ok "CP action: unknown site 404s" "$(post -d index=articles -d site=nope "$U/admin/bloodhound/repair")" "404"
ok "CP action: logged-out request refused" "$(curl -sk -X POST -o /dev/null -w '%{http_code}' -d index=articles -d site=default "$U/admin/bloodhound/repair")" "419"
# Run (never delete) queued rebuilds: deleting a unique job's row leaves its lock held.
ddev artisan queue:work --queue=default --stop-when-empty >/dev/null 2>&1
post -d index=articles -d site=default "$U/admin/bloodhound/rebuild" >/dev/null; post -d index=articles -d site=default "$U/admin/bloodhound/rebuild" >/dev/null
ok "CP Rebuild queues one (unique) job" "$(tk 'echo Illuminate\Support\Facades\DB::table("jobs")->where("payload","like","%RebuildIndex%")->count();')" "1"
ddev artisan queue:work --queue=default --stop-when-empty >/dev/null 2>&1; settle
ok "queued rebuild ran and released its lock (index clean, can rebuild again)" "$(counts)/$(post -d index=articles -d site=default "$U/admin/bloodhound/rebuild" >/dev/null; tk 'echo Illuminate\Support\Facades\DB::table("jobs")->where("payload","like","%RebuildIndex%")->count();')" "0/0/0/1"
ddev artisan queue:work --queue=default --stop-when-empty >/dev/null 2>&1; settle

ok "settings page loads" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' "$U/admin/settings/plugins/bloodhound")" "200"

entry second-article-2 '$e->setFieldValue("summary", "The related one.");' >/dev/null
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
