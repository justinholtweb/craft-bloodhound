#!/bin/bash
# Live checks against the Craft 6 harness. Engine: whatever the harness .env sets —
#   SCOUT_DRIVER=meilisearch (kevinquillen/ddev-meilisearch add-on; MEILISEARCH_HOST=http://meilisearch:7700, MEILISEARCH_KEY=ddev)
#   SCOUT_DRIVER=typesense   (kevinquillen/ddev-typesense add-on; TYPESENSE_HOST=typesense, TYPESENSE_API_KEY=ddev)
#   SCOUT_DRIVER=harness     (tests/Harness/HarnessEngine.php.fixture, registered in the harness's AppServiceProvider) Templates: tests/Harness/templates/* →
# resources/views/bh/. Uses the `articles` section from Momentum's harness fixtures.
set -uo pipefail
exec < /dev/null
H=/Users/jholt/Sites/plugin-testing-craft6
SRC=$(cd "$(dirname "$0")" && pwd)
cp "$SRC"/templates/find{.twig,-blade.blade.php} "$H/resources/views/bh/"
U=https://plugin-testing-craft6.ddev.site
cd "$H" || exit 2
pass=0 fail=0
DRIVER=$(grep '^SCOUT_DRIVER=' .env | cut -d= -f2)
ok() { if [ "$2" = "$3" ]; then echo "✓ $1"; pass=$((pass+1)); else echo "✗ $1 (got '$2', want '$3')"; fail=$((fail+1)); fi; }
tk() { ddev artisan tinker --execute "$1" 2>/dev/null | grep -v WARNING | tail -1; }
ids() { curl -sk "$U/bh/$1?q=$2&page=${3:-1}" | grep -oE 'data-id="[0-9]+"' | grep -oE '[0-9]+' | tr '\n' ',' | sed 's/,$//'; }
total() { curl -sk "$U/bh/$1?q=$2" | grep -oE '<p id="total">[0-9]+' | grep -oE '[0-9]+$'; }
settings() { tk "\$p=CraftCms\\Cms\\Support\\Facades\\Plugins::getPlugin('bloodhound'); CraftCms\\Cms\\Support\\Facades\\Plugins::savePluginSettings(\$p, $1); echo 1;" >/dev/null; }
settle() { ddev exec "for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -H 'Authorization: Bearer ${MEILI_KEY:-ddev}' 'http://meilisearch:7700/tasks?statuses=enqueued,processing&limit=1' 2>/dev/null | grep -q '\"total\":0' && break; sleep 0.3; done" >/dev/null 2>&1 || sleep 1; }
entry() { tk "\$e=CraftCms\\Cms\\Entry\\Elements\\Entry::find()->slug('$1')->status(null)->one(); $2 echo CraftCms\\Cms\\Support\\Facades\\Elements::saveElement(\$e) ? 'saved' : json_encode(\$e->errors()->all());"; }

settings "['queueUpdates' => false, 'indexPrefix' => 'harness_', 'indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers']]]"
entry second-article-2 '$e->enabled = true; $e->setFieldValue("summary", "The related one, from Craft.");' >/dev/null
entry hello-momentum '$e->title = "Momentum Notes"; $e->setFieldValue("summary", "A page about Craft.");' >/dev/null
ddev artisan craft:bloodhound:import articles --fresh >/dev/null 2>&1; settle

for e in twig blade; do
  ok "$e: finds by title" "$(ids $e momentum | tr ',' '\n' | sort -n | tr '\n' ' ')" "2 8 "
  ok "$e: finds by a related element's name" "$(ids $e johnson)" "8"
  # Bloodhound configures Meilisearch/Typesense's searched fields; a custom engine searches what it likes.
  [ "$DRIVER" != harness ] && ok "$e: URLs and type handles aren't searched" "$(total $e article)" "1"
  p1=$(ids $e craft 1); p2=$(ids $e craft 2)
  ok "$e: pagination (2 + 1 hits, no overlap, all 3 articles)" "$(echo "$p1" | tr ',' '\n' | wc -l | tr -d ' ')/$(echo "$p2" | tr ',' '\n' | wc -l | tr -d ' ')/$(echo "$p1,$p2" | tr ',' '\n' | sort -n | uniq | tr '\n' ' ')" "2/1/2 8 10 "
  ok "$e: no query → no results" "$(total $e '')" "0"
done
ok "filter on a filterable attribute (section=articles)" "$(curl -sk "$U/bh/twig?q=momentum&section=articles" | grep -oE '<p id="total">[0-9]+' | grep -oE '[0-9]+$')" "2"
ok "filter that matches nothing (section=nope)" "$(curl -sk "$U/bh/blade?q=momentum&section=nope" | grep -oE '<p id="total">[0-9]+' | grep -oE '[0-9]+$')" "0"
# Filter values are literals: quote/operator tricks in template input can't widen a filter on any engine.
err() { curl -sk "$U/bh/blade?q=momentum&section=$1" | grep -o '<p id="error">[^<]*' | sed 's/<p id="error">//'; }
for v in 'nope%22%20OR%20section%3D%22articles' 'nope%20%7C%7C%20section%3A%3Darticles' '%5Bnope%2Carticles%5D'; do
  ok "filter injection ($v) matches nothing, no error" "$(total twig "momentum&section=$v")/$(err "$v")" "0/"
done
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
inject() { tk "\$e=app(Laravel\\Scout\\EngineManager::class)->engine(); \$n=app(JustinHoltWeb\\Bloodhound\\Sync\\Indexer::class)->engineIndex(app(JustinHoltWeb\\Bloodhound\\Indexes\\Indexes::class)->get('articles'),'default'); \$c=fn(\$a)=>new Illuminate\\Database\\Eloquent\\Collection([JustinHoltWeb\\Bloodhound\\Documents\\SearchDocument::for(\$n,\$a,app(JustinHoltWeb\\Bloodhound\\Indexes\\Indexes::class)->get('articles'))]); \$e->update(\$c(['id'=>99999,'title'=>'ghost','dateUpdated'=>1])); \$e->delete(\$c(['id'=>10])); \$e->update(\$c(['id'=>8,'title'=>'Hello Momentum','dateUpdated'=>1])); echo 1;" >/dev/null; settle; }
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

# Typesense only: an existing collection gains fields added to the index without a rebuild.
if [ "$DRIVER" = typesense ]; then
  settings "['indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers, tagline']]]"
  ddev artisan craft:bloodhound:import articles >/dev/null 2>&1
  ok "typesense: new index field added to the live collection" "$(ddev exec "curl -s -H 'X-TYPESENSE-API-KEY: ddev' http://typesense:8108/collections/harness_articles_default" 2>/dev/null | grep -o '"name":"tagline"' | head -1)" '"name":"tagline"'
  ok "typesense: searching still works with the new field in query_by" "$(total twig momentum)" "2"
  settings "['indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers']]]"
fi

# ---- Users ----
# Fixture people (removed at the end): two in `staff`, one outside it, one whose username is an email address.
UE='CraftCms\Cms\User\Elements\User'
person() { tk "\$u=$UE::find()->username('$1')->status(null)->one() ?? new $UE; \$u->username='$1'; \$u->email='${4:-$1@bh.test}'; \$u->fullName='$2'; \$u->active=true; \$u->pending=false; CraftCms\Cms\Support\Facades\Elements::saveElement(\$u); CraftCms\Cms\Support\Facades\Users::assignUserToGroups(\$u->id, '$3' === '' ? [] : [CraftCms\Cms\Support\Facades\UserGroups::getGroupByHandle('$3')->id]); echo \$u->id;"; }
uid() { tk "echo $UE::find()->username('$1')->status(null)->one()?->id;"; }
ADA=$(person adal1815 'Ada Lovelace' staff); GRACE=$(person ghopper 'Grace Hopper' staff); person mcurie 'Marie Curie' '' >/dev/null
MAIL=$(person 'mailperson@bh.test' 'Mail Person' staff 'mailperson@bh.test')
# In the group but suspended: never indexed, and a query that could return them isn't the engine's to answer.
LINUS=$(person lpauling 'Linus Lovelace' staff); tk "CraftCms\Cms\Support\Facades\Users::suspendUser($UE::find()->id($LINUS)->one()); echo 1;" >/dev/null
settings "['indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers'], ['handle' => 'people', 'elementType' => 'users', 'sources' => 'staff', 'fields' => '']]]"
ddev artisan craft:bloodhound:import people --fresh >/dev/null 2>&1; settle
fids() { curl -sk "$U/bh/$1?index=${2}&q=$3${4:-}" | grep -oE 'data-id="[0-9]+"' | grep -oE '[0-9]+' | sort -n | tr '\n' ' '; }
for e in find find-blade; do
  ok "users $e: found by surname" "$(fids $e people lovelace)" "$ADA "
  ok "users $e: found by username" "$(fids $e people ghopper)" "$GRACE "
done
ok "users: a suspended user isn't indexed" "$(fids find people linus)" ""
ok "users: a user outside the index's groups isn't indexed" "$(fids find people curie)" ""
ok "users: shown by name" "$(curl -sk "$U/bh/find?index=people&q=lovelace" | grep -o '<li[^>]*>[^<]*' | sed 's/.*>//')" "Ada Lovelace"
ok "users: email addresses are never searchable" "$(fids find people bh.test)" ""
ok "users: an email used as username isn't indexed, the name is" "$(fids find people 'mail+person')/$(tk "echo json_encode(array_intersect_key(app(JustinHoltWeb\Bloodhound\Documents\DocumentBuilder::class)->build($UE::find()->id($MAIL)->one(), app(JustinHoltWeb\Bloodhound\Indexes\Indexes::class)->get('people')), array_flip(['title','username'])));")" "$MAIL /{\"title\":\"Mail Person\",\"username\":null}"
ok "users: filter on group" "$(fids find people lovelace '&key=groups&value=staff')/$(fids find people lovelace '&key=groups&value=gold')" "$ADA /"
ok "users: suspending removes the user" "$(tk "CraftCms\Cms\Support\Facades\Users::suspendUser($UE::find()->id($ADA)->one()); echo 1;" >/dev/null; settle; fids find people lovelace)" ""
ok "users: unsuspending brings them back" "$(tk "CraftCms\Cms\Support\Facades\Users::unsuspendUser($UE::find()->id($ADA)->status(null)->one()); echo 1;" >/dev/null; settle; fids find people lovelace)" "$ADA "
ok "users: leaving the group removes the user" "$(tk "CraftCms\Cms\Support\Facades\Users::assignUserToGroups($ADA, []); echo 1;" >/dev/null; settle; fids find people lovelace)" ""
ok "users: joining the group adds them" "$(person adal1815 'Ada Lovelace' staff >/dev/null; settle; fids find people lovelace)" "$ADA "
ok "users: a renamed user is found by the new name" "$(person ghopper 'Grace Brewster' staff >/dev/null; settle; fids find people brewster)" "$GRACE "
person ghopper 'Grace Hopper' staff >/dev/null

# ---- GraphQL (Lite and Pro): `bloodhoundSearch` ----
cp "$SRC"/gql-fixtures.php "$H/storage/bh-gql-fixtures.php"
tk "require 'storage/bh-gql-fixtures.php';" >/dev/null
# gq <schema> <query> <python expression over the response `r`>
gq() { curl -sk -H "Authorization: Bearer $(printf 'bh%s' "$1" | awk '{ s=$0; while (length(s) < 32) s=s "0"; print s }')" -H 'Content-Type: application/json' \
  --data "$(python3 -c 'import json,sys; print(json.dumps({"query": sys.argv[1]}))' "$2")" "$U/actions/graphql/api" \
  | python3 -c 'import json,sys
try: r=json.load(sys.stdin)
except Exception: print("not json"); sys.exit()
if r.get("errors"): print("ERR:" + "; ".join(e.get("message","") for e in r["errors"])); sys.exit()
d=r.get("data") or {}; b=d.get("bloodhoundSearch") or {}; j=lambda *v: "/".join(str(x) for x in v)
print(eval(sys.argv[1]))' "$3"; }
# In expressions: r = response, d = data, b = data.bloodhoundSearch, j(a, b, …) = "a/b/…"
ids_of='",".join(e["id"] for e in r["data"]["bloodhoundSearch"]["elements"])'
ok "gql: search returns live entries in rank order (same as Twig)" "$(gq full '{ bloodhoundSearch(index: "articles", query: "momentum") { elements { id } } }' "$ids_of")" "$(ids twig momentum)"
ok "gql: element fields through fragments" "$(gq full '{ bloodhoundSearch(index: "articles", query: "momentum") { elements { ... on EntryInterface { sectionHandle } } } }' 'r["data"]["bloodhoundSearch"]["elements"][0]["sectionHandle"]')" "articles"
ok "gql: total, pages, page" "$(gq full '{ bloodhoundSearch(index: "articles", query: "momentum", perPage: 1, page: 2) { total pages page elements { id } } }' 'j(b["total"], b["pages"], b["page"], len(b["elements"]))')" "2/2/2/1"
ok "gql: where filters" "$(gq full '{ a: bloodhoundSearch(index: "articles", query: "momentum", where: [{attribute: "section", value: "articles"}]) { total } b: bloodhoundSearch(index: "articles", query: "momentum", where: [{attribute: "section", value: "nope\" OR section=\"articles"}]) { total } }' 'j(d["a"]["total"], d["b"]["total"])')" "2/0"
ok "gql: users index, fields through UserInterface" "$(gq full '{ bloodhoundSearch(index: "people", query: "lovelace") { elements { ... on UserInterface { fullName } } } }' 'r["data"]["bloodhoundSearch"]["elements"][0]["fullName"]')" "Ada Lovelace"
ok "gql: no email over GraphQL search either" "$(gq full '{ bloodhoundSearch(index: "people", query: "bh.test") { total } }' 'r["data"]["bloodhoundSearch"]["total"]')" "0"
ok "gql: schema without the index has no bloodhoundSearch query" "$(gq noindex '{ bloodhoundSearch(index: "articles", query: "momentum") { total } }' 'r["data"]')" "ERR:Cannot query field \"bloodhoundSearch\" on type \"Query\"."
ok "gql: an index the schema lacks reads the same as an unknown one" "$(gq nosection '{ a: bloodhoundSearch(index: "people", query: "x") { total } }' '0')/$(gq nosection '{ bloodhoundSearch(index: "nosuch", query: "x") { total } }' '0')" "ERR:This schema can’t search the “people” index./ERR:This schema can’t search the “nosuch” index."
ok "gql: hits the schema can't read are filtered out, and not counted" "$(gq nosection '{ bloodhoundSearch(index: "articles", query: "momentum") { total pages elements { id } } }' 'j(b["total"], b["pages"], len(b["elements"]))')" "0/0/0"
# Partly readable index: `mixed` holds articles and notes; the `full` schema reads articles only.
NOTE=$(tk "echo CraftCms\Cms\Entry\Elements\Entry::find()->section('notes')->one()->id;")
NOTE_SUMMARY=$(tk "echo json_encode(CraftCms\Cms\Entry\Elements\Entry::find()->id($NOTE)->one()->getFieldValue('summary'));")
note() { tk "\$e=CraftCms\Cms\Entry\Elements\Entry::find()->id($NOTE)->status(null)->one(); \$e->setFieldValue('summary', json_decode('$1')); echo CraftCms\Cms\Support\Facades\Elements::saveElement(\$e, false) ? 'saved' : 'failed';"; }
settings "['indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers'], ['handle' => 'people', 'elementType' => 'users', 'sources' => 'staff', 'fields' => ''], ['handle' => 'mixed', 'elementType' => 'entries', 'sources' => 'articles, notes', 'fields' => 'summary']]]"
note '"A momentum journal."' >/dev/null
ddev artisan craft:bloodhound:import mixed --fresh >/dev/null 2>&1; settle; tk "require 'storage/bh-gql-fixtures.php';" >/dev/null
tk "\$s=collect(CraftCms\Cms\Support\Facades\Gql::getSchemas())->first(fn(\$s)=>\$s->name==='bh_full'); \$s->scope=[...\$s->scope, 'bloodhound.mixed:read']; CraftCms\Cms\Support\Facades\Gql::saveSchema(\$s); CraftCms\Cms\Support\Facades\Gql::invalidateCaches(); echo 1;" >/dev/null
ok "gql: partly readable index — Twig sees all 3 matches" "$(fids find mixed momentum | wc -w | tr -d ' ')" "3"
ok "gql: partly readable index — total, pages and elements count only readable hits" "$(gq full '{ bloodhoundSearch(index: "mixed", query: "momentum", perPage: 1) { total pages elements { id } } }' 'j(b["total"], b["pages"], len(b["elements"]))')" "2/2/1"
ok "gql: partly readable index — page 2 is the second readable hit, never the note" "$(gq full '{ a: bloodhoundSearch(index: "mixed", query: "momentum", perPage: 1, page: 1) { elements { id } } b: bloodhoundSearch(index: "mixed", query: "momentum", perPage: 1, page: 2) { elements { id } } }' '",".join(sorted([d["a"]["elements"][0]["id"], d["b"]["elements"][0]["id"]]))')" "2,8"
ok "gql: partly readable index — the note alone matches nothing for this schema" "$(gq full '{ bloodhoundSearch(index: "mixed", query: "journal") { total elements { id } } }' 'j(b["total"], len(b["elements"]))')" "0/0"
ok "gql: a filter on a non-filterable attribute is an error, not a wider search" "$(gq full '{ bloodhoundSearch(index: "articles", query: "momentum", where: [{attribute: "url", value: "x"}]) { total } }' '0' | grep -c 'url” isn’t a filterable attribute')" "1"
note "$NOTE_SUMMARY" >/dev/null
ddev artisan craft:bloodhound:flush mixed >/dev/null 2>&1
settings "['indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers'], ['handle' => 'people', 'elementType' => 'users', 'sources' => 'staff', 'fields' => '']]]"
ok "gql: a site the schema can't read is refused" "$(gq full '{ bloodhoundSearch(index: "articles", query: "momentum", site: "nope") { total } }' '0')" "ERR:This schema can’t read the “nope” site."
ok "gql: an edit shows up (GraphQL cache invalidated)" "$(gq full '{ bloodhoundSearch(index: "articles", query: "aerostats") { total } }' 'r["data"]["bloodhoundSearch"]["total"]')/$(entry second-article-2 '$e->setFieldValue("summary", "Now about aerostats.");' >/dev/null; settle; gq full '{ bloodhoundSearch(index: "articles", query: "aerostats") { elements { id } } }' "$ids_of")" "0/10"
entry second-article-2 '$e->setFieldValue("summary", "Now about zeppelins.");' >/dev/null; settle
cp .env /tmp/bh-env.bak; sed -i '' 's/^SCOUT_DRIVER=.*/SCOUT_DRIVER=meilisearch/; s#^MEILISEARCH_HOST=.*#MEILISEARCH_HOST=http://127.0.0.1:1#' .env; grep -q '^MEILISEARCH_HOST=' .env || echo 'MEILISEARCH_HOST=http://127.0.0.1:1' >> .env
ok "gql: engine down → empty results with an error, not a failed request" "$(gq full '{ bloodhoundSearch(index: "articles", query: "momentumx") { error elements { id } } }' 'j(b["error"], len(b["elements"]))')" "Search is temporarily unavailable./0"
cp /tmp/bh-env.bak .env; sleep 1

# ---- Products (only when Craft Commerce is installed and enabled in the harness) ----
COMMERCE=$(tk "echo JustinHoltWeb\Bloodhound\Indexes\Indexes::commerceAvailable() ? 'yes' : 'no';")
if [ "$COMMERCE" = yes ]; then
  cp "$SRC"/commerce-fixtures.php "$SRC"/templates/craft-products{.twig,-blade.blade.php} "$H/resources/views/bh/"
  P=$(tk "require 'resources/views/bh/commerce-fixtures.php';")
  pid() { echo "$P" | grep -oE "\"$1\":[0-9]+" | grep -oE '[0-9]+$'; }
  LAMP=$(pid lamp); TENT=$(pid tent); BOOK=$(pid book); OFF=$(pid off)
  settings "['indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers'], ['handle' => 'people', 'elementType' => 'users', 'sources' => 'staff', 'fields' => ''], ['handle' => 'shop', 'elementType' => 'products', 'sources' => 'gear', 'fields' => '']]]"
  ddev artisan craft:bloodhound:import shop --fresh >/dev/null 2>&1; settle
  for e in find find-blade; do
    ok "products $e: found by title, only live products of the index's types" "$(fids $e shop lantern)" "$LAMP "
    ok "products $e: found by a non-default variant's SKU" "$(fids $e shop LAMP-200)" "$LAMP "
  done
  ok "products: filter on product type" "$(fids find shop tent '&key=productType&value=gear')/$(fids find shop tent '&key=productType&value=books')" "$TENT /"
  ok "products: filter on price" "$(fids find shop '' '&key=price&value=320')$(fids find shop alpine '&key=price&value=320')/$(fids find shop alpine '&key=price&value=1')" "$TENT /"
  ok "products: document carries type, price and every SKU" "$(tk "\$d=app(JustinHoltWeb\Bloodhound\Documents\DocumentBuilder::class)->build(CraftCms\Commerce\Product\Elements\Product::find()->id($LAMP)->one(), app(JustinHoltWeb\Bloodhound\Indexes\Indexes::class)->get('shop')); echo \$d['productType'].'/'.\$d['price'].'/'.implode('|', \$d['sku']);")" "gear/49.5/LAMP-100|LAMP-200"
  variant() { tk "\$v=CraftCms\Commerce\Product\Variant\Elements\Variant::find()->sku('$1')->status(null)->one(); \$v->setSku('$2'); echo CraftCms\Cms\Support\Facades\Elements::saveElement(\$v) ? 'saved' : json_encode(\$v->errors()->all());"; }
  ok "products: saving just a variant re-syncs its product" "$(variant LAMP-200 LAMP-250; settle)/$(fids find shop LAMP-250)" "saved/$LAMP "
  variant LAMP-250 LAMP-200 >/dev/null; settle
  prod() { tk "\$p=CraftCms\Commerce\Product\Elements\Product::find()->id($1)->status(null)->one(); $2 echo CraftCms\Cms\Support\Facades\Elements::saveElement(\$p) ? 'saved' : json_encode(\$p->errors()->all());"; }
  ok "products: disabling removes the product" "$(prod $TENT '$p->enabled = false;'; settle)/$(fids find shop alpine)" "saved/"
  ok "products: re-enabling brings it back" "$(prod $TENT '$p->enabled = true;'; settle)/$(fids find shop alpine)" "saved/$TENT "
  pdate() { tk "Illuminate\Support\Facades\DB::table('commerce_products')->where('id',$TENT)->update($1); echo 1;" >/dev/null; }
  # Expiry and go-live save nothing, so no event fires: sync-scheduled has to notice.
  tk "require 'storage/bh-gql-fixtures.php';" >/dev/null
  ok "products gql: bloodhoundSearch finds a product by variant SKU" "$(gq full '{ bloodhoundSearch(index: "shop", query: "LAMP-200") { elements { id title } } }' '",".join(e["id"] for e in b["elements"])')" "$LAMP"
  ok "products gql: index allowed but product type not → products filtered out" "$(gq nosection '{ bloodhoundSearch(index: "shop", query: "LAMP-200") { total elements { id } } }' 'j(b["total"], len(b["elements"]))')" "1/0"
  ok "products: an expired product is removed by sync-scheduled" "$(pdate "['expiryDate'=>now()->utc()->subMinute()->format('Y-m-d H:i:s')]"; ddev artisan craft:bloodhound:sync-scheduled --since='-10 minutes' >/dev/null 2>&1; settle; fids find shop alpine)" ""
  ok "products: a product that just went live is added by sync-scheduled" "$(pdate "['expiryDate'=>null,'postDate'=>now()->utc()->subMinute()->format('Y-m-d H:i:s')]"; ddev artisan craft:bloodhound:sync-scheduled --since='-10 minutes' >/dev/null 2>&1; settle; fids find shop alpine)" "$TENT "
fi

# ---- Pro ----
cp "$(dirname "$0")/templates/craft.twig" "$(dirname "$0")/templates/craft-blade.blade.php" "$H/resources/views/bh/" 2>/dev/null \
  || cp /Users/jholt/Sites/craft-bloodhound/tests/Harness/templates/craft{.twig,-blade.blade.php} "$H/resources/views/bh/"
cids() { curl -sk "$U/bh/$1?q=$2${3:-}" | grep -o '<p id="ids">[^<]*' | sed 's/<p id="ids">//'; }
edition() { tk "CraftCms\\Cms\\Support\\Facades\\Plugins::switchEdition('bloodhound', '$1'); echo 1;" >/dev/null; }

edition lite; settings "['replaceCraftSearch' => false]"
ok "lite: Craft's search can't take a typo" "$(cids craft momentm)" ""
ok "lite gql: entries(search:) stays with Craft (no typo match)" "$(gq full '{ entries(section: "articles", search: "momentm") { id } }' 'len(r["data"]["entries"])')" "0"
ddev artisan craft:bloodhound:import articles --swap >/dev/null 2>&1; ok "lite: import --swap is refused" "$?" "1"
edition pro
settings "['replaceCraftSearch' => false]"
ok "pro, takeover off: still Craft's search" "$(cids craft momentm)" ""
settings "['replaceCraftSearch' => true]"
for e in craft craft-blade; do
  [ "$DRIVER" != harness ] && ok "pro $e: .search() answered by the engine (typo tolerant)" "$(cids $e momentm | tr ',' '\n' | sort -n | tr '\n' ' ')" "2 8 "
  ok "pro $e: engine rank order kept with orderBy('score desc')" "$(cids $e momentum | cut -d, -f1)" "$(ids twig momentum | cut -d, -f1)"
done
ok "pro: query covering disabled entries falls back to Craft (no typo match)" "$(cids craft momentm '&all=1')" ""
ok "pro: fallback still answers exact words" "$(cids craft momentum '&all=1' | tr ',' '\n' | sort -n | tr '\n' ' ')" "2 8 "
ok "pro: Craft search syntax goes to Craft" "$(cids craft 'title:momentm')" ""
ok "pro: disabled entry never comes back from the engine path" "$(entry second-article-2 '$e->enabled = false;')/$(cids craft second)" "saved/"
entry second-article-2 '$e->enabled = true;' >/dev/null; settle
cp .env /tmp/bh-env.bak; sed -i '' 's/^SCOUT_DRIVER=.*/SCOUT_DRIVER=meilisearch/; s#^MEILISEARCH_HOST=.*#MEILISEARCH_HOST=http://127.0.0.1:1#' .env; grep -q '^MEILISEARCH_HOST=' .env || echo 'MEILISEARCH_HOST=http://127.0.0.1:1' >> .env
ok "pro: engine down → Craft's search answers instead" "$(cids craft momentum | tr ',' '\n' | sort -n | tr '\n' ' ')" "2 8 "
cp /tmp/bh-env.bak .env; sleep 1

if [ "$DRIVER" != harness ]; then
  ok "pro: no synonym yet" "$(total twig framework)" "0"
  settings "['synonyms' => [['term' => 'framework', 'synonyms' => 'craft, toolkit']]]"; settle
  ok "pro: synonym pushed on settings save (framework → craft)" "$(total twig framework)" "$(total twig craft)"
  ok "pro: synonyms work in every direction (toolkit)" "$(total twig toolkit)" "$(total twig craft)"
  settings "['synonyms' => []]"; settle
  ok "pro: removed synonym is gone from the engine" "$(total twig framework)" "0"
fi
settings "['stopWords' => \"the, a\nof\"]"
ok "pro: stop words dropped from queries" "$(tk 'echo app(JustinHoltWeb\Bloodhound\Search\Bloodhound::class)->queryText("The history of  Craft");')" "history Craft"
ok "pro: all-stop-word query searched as typed" "$(tk 'echo app(JustinHoltWeb\Bloodhound\Search\Bloodhound::class)->queryText("the a");')" "the a"
settings "['stopWords' => null]"

if [ "$DRIVER" != harness ]; then
  # Zero-downtime rebuild: search keeps answering while the swap runs.
  ( ddev artisan craft:bloodhound:import articles --site=default --swap >/dev/null 2>&1; echo done > /tmp/bh-swap.done ) &
  rm -f /tmp/bh-swap.done; seen=""
  for i in $(seq 1 40); do seen="$seen$(total twig momentum)"; [ -f /tmp/bh-swap.done ] && break; done
  wait; settle
  ok "pro swap: search answered throughout the rebuild ($(echo -n "$seen" | wc -c | tr -d ' ') probes)" "$(echo "$seen" | tr -d '2')" ""
  ok "pro swap: index complete after the swap" "$(counts)" "0/0/0"
  ok "pro swap: searching works after the swap" "$(total twig momentum)" "2"
  ddev artisan craft:bloodhound:import articles --site=default --swap >/dev/null 2>&1; settle
  if [ "$DRIVER" = typesense ]; then
    ts() { ddev exec "curl -s -H 'X-TYPESENSE-API-KEY: ddev' http://typesense:8108/$1" 2>/dev/null; }
    ok "typesense swap: index is an alias now" "$(ts aliases/harness_articles_default | grep -o 'collection_name' | head -1)" "collection_name"
    ok "typesense swap: second swap removed the old collection (one left)" "$(ts collections | grep -o '"name":"harness_articles_default[^"]*"' | wc -l | tr -d ' ')" "1"
    ok "typesense swap: health reads through the alias" "$(counts)" "0/0/0"
    ddev artisan craft:bloodhound:flush articles --site=default >/dev/null 2>&1
    ok "typesense: flush removes alias and collection" "$(ts collections | grep -c 'harness_articles_default')/$(ts aliases | grep -c 'harness_articles_default')" "0/0"
  else
    ok "meilisearch swap: temp index cleaned up" "$(ddev exec "curl -s -H 'Authorization: Bearer ddev' http://meilisearch:7700/indexes/harness_articles_default__next" 2>/dev/null | grep -o 'index_not_found' | head -1)" "index_not_found"
  fi
  ddev artisan craft:bloodhound:import articles >/dev/null 2>&1; settle
  ddev artisan queue:work --queue=default --stop-when-empty >/dev/null 2>&1
  ok "pro: CP Rebuild (swap) queued and ran" "$(post -d index=articles -d site=default "$U/admin/bloodhound/rebuild")/$(ddev artisan queue:work --queue=default --stop-when-empty >/dev/null 2>&1; settle; counts)" "302/0/0/0"
fi
# Pro takeover for users: covered when every candidate is an active user of the index's groups.
cp "$SRC"/templates/craft-users{.twig,-blade.blade.php} "$H/resources/views/bh/"
for e in craft-users craft-users-blade; do
  [ "$DRIVER" != harness ] && ok "pro $e: user .search() answered by the engine (typo tolerant)" "$(cids $e lovelce)" "$ADA"
  ok "pro $e: exact name" "$(cids $e lovelace)" "$ADA"
done
[ "$DRIVER" != harness ] && ok "pro: user query including inactive users falls back to Craft" "$(cids craft-users lovelce '&all=1')" ""
# GraphQL's own `search:` argument goes through the same takeover.
[ "$DRIVER" != harness ] && ok "pro gql: entries(search:) answered by the engine (typo tolerant)" "$(gq full '{ entries(section: "articles", search: "momentm", orderBy: "score desc") { id } }' '",".join(sorted(e["id"] for e in r["data"]["entries"]))')" "2,8"
ok "pro gql: rank order kept with orderBy: \"score desc\"" "$(gq full '{ entries(section: "articles", search: "momentum", orderBy: "score desc") { id } }' 'r["data"]["entries"][0]["id"]')" "$(ids twig momentum | cut -d, -f1)"
if [ "$COMMERCE" = yes ]; then
  for e in craft-products craft-products-blade; do
    [ "$DRIVER" != harness ] && ok "pro $e: product .search() answered by the engine (typo tolerant)" "$(cids $e lantrn)" "$LAMP"
    ok "pro $e: exact SKU" "$(cids $e LAMP-200)" "$LAMP"
  done
  [ "$DRIVER" != harness ] && ok "pro gql: products(search:) answered by the engine (typo tolerant)" "$(gq full '{ products(type: "gear", search: "lantrn", orderBy: "score desc") { id } }' '",".join(e["id"] for e in d["products"])')" "$LAMP"
  [ "$DRIVER" != harness ] && ok "pro: product query including disabled products falls back to Craft" "$(cids craft-products lantrn '&all=1')" ""
fi
settings "['replaceCraftSearch' => false]"
edition lite

# Scout's database/collection drivers search model tables and store nothing: refused, never a silent "success".
cp .env /tmp/bh-env.bak; sed -i '' 's/^SCOUT_DRIVER=.*/SCOUT_DRIVER=database/' .env
ok "database driver: import refused with a clear message" "$(ddev artisan craft:bloodhound:import articles >/tmp/bh-import.out 2>&1; echo $?)/$(grep -c 'needs a search engine that stores documents' /tmp/bh-import.out)/$(grep -c 'documents$' /tmp/bh-import.out)" "1/1/0"
ok "database driver: search shows unavailable, page still renders" "$(curl -sk "$U/bh/find?index=articles&q=momentum" | grep -o '<p id="error">[^<]*' | sed 's/<p id="error">//')" "Search is temporarily unavailable."
ok "database driver: saving an entry still works" "$(entry hello-momentum '')" "saved"
cp /tmp/bh-env.bak .env; sleep 1

ok "gql: CP schema editor offers each index as a permission" "$(curl -sk -b "$J" "$U/admin/graphql/schemas/new" | grep -c 'bloodhound.articles:read' | awk '{print ($1>0)?"yes":"no"}')" "yes"
ok "settings page loads" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' "$U/admin/settings/plugins/bloodhound")" "200"

entry second-article-2 '$e->setFieldValue("summary", "The related one.");' >/dev/null
for n in adal1815 ghopper mcurie lpauling mailperson@bh.test; do tk "\$u=$UE::find()->username('$n')->status(null)->one(); if (\$u) CraftCms\Cms\Support\Facades\Elements::deleteElement(\$u, true); echo 1;" >/dev/null; done
ok "users: deleted users leave the index" "$(settle; fids find people lovelace)" ""
tk 'foreach (CraftCms\Cms\Support\Facades\Gql::getTokens() as $t) if (str_starts_with((string) $t->name, "bh_")) CraftCms\Cms\Support\Facades\Gql::deleteTokenById($t->id); foreach (CraftCms\Cms\Support\Facades\Gql::getSchemas() as $s) if (str_starts_with((string) $s->name, "bh_")) CraftCms\Cms\Support\Facades\Gql::deleteSchemaById($s->id); echo 1;' >/dev/null
ddev artisan craft:bloodhound:flush people >/dev/null 2>&1
[ "$COMMERCE" = yes ] && ddev artisan craft:bloodhound:flush shop >/dev/null 2>&1
settings "['indexes' => [['handle' => 'articles', 'elementType' => 'entries', 'sources' => 'articles', 'fields' => 'summary, customers']]]"
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
