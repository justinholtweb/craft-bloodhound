# Bloodhound — plan

Laravel Scout for Craft elements — index entries, assets, users or Commerce products into Meilisearch, Typesense or Algolia.

- Package: `justinholtweb/craft-bloodhound` · handle `bloodhound` · namespace `JustinHoltWeb\Bloodhound`
- Editions: Lite / Pro · pricing: Lite free / Pro $49
- Laravel integration: laravel/scout — Scout v11, Laravel 13 compatible. Engines: Meilisearch, Typesense, Algolia (unverified). Not `database`/`collection`.
- Craft 6 only, native Laravel (no `craftcms/yii2-adapter`, ever). Follow
  `~/Sites/plugin-testing-craft6/porting/COOKBOOK.md` for Craft 6 idioms.

## Why it exists
Algolia Sync ($99) covers one engine; Craft's built-in search is MySQL keyword matching. Scout gives Craft
every modern engine through one interface. Holmes (search-index insight) shares a lot of the thinking.

## Core idea
Scout's `Searchable` expects Eloquent models; Craft elements aren't. Bloodhound talks to Scout's
`EngineManager` directly with a thin adapter that presents an element as a searchable record, so every Scout
engine works unchanged.

## Status
- **2026-11-18 — phase 1 done (pending a live Meilisearch run).** CP-defined indexes (entries/assets,
  sources, fields), per-site engine indexes, sync on save/delete (queued or inline, failures never block a
  save), `sync-scheduled` for silent go-live/expiry, `import [--fresh]` / `flush`, and one search API for
  Twig and Blade with live-only hydration. `SearchDocument` implements the engine-facing methods itself
  rather than Scout's `Searchable` trait (which registers observers + global collection macros).
- **Verified on real Meilisearch** (harness add-on `kevinquillen/ddev-meilisearch`, client
  `meilisearch/meilisearch-php` 1.17): 30 checks incl. filters, paging, queue, scheduled publishing,
  stale-document filtering, outage — and the same 30 pass on the harness-only file engine, so the suite
  is engine-neutral. `import` declares Meilisearch filterable/sortable attributes (`prepare()`); engines
  without `updateIndexSettings()` skip it.
- Relations index as the related elements' UI labels, so Wingman/Eloquent elements (no titles) read as
  their names.
- **2026-11-18 — phase 2a (index health) done.** Utilities → Bloodhound + `status [--fail-on-drift]` /
  `repair [--dry-run]`: missing / orphaned / stale per index+site, comparing live element ids and
  `dateUpdated` with what the engine holds (Meilisearch lister built in; `ListsDocuments` contract for any
  other engine). Repair re-syncs only drifted ids; Rebuild is a queued unique job (`uniqueFor` so a cleared
  job can't block rebuilds forever). 9 Pest + 46 harness checks, green on Meilisearch and the file engine.
- Gotcha: a stale PHPStan result cache in the container's /tmp (after repos moved) made Larastan's
  bootstrap fail only in parallel mode — `phpstan clear-result-cache`.
- **2026-11-18 — phase 2b (Typesense) done.** Scout reads Typesense's collection schema and `query_by` per
  model class, but every index shares `SearchDocument`, so `TypesenseSchema` derives both from the index
  definition (`typesenseCollectionSchema()` / `typesenseSearchParameters()` on the document). `prepare()`
  creates the collection or adds fields the index has gained. Documents get string ids and custom fields
  flattened to `string[]`; health lists documents via `export`; string filter values are backtick-quoted.
  Every engine now searches the same fields (`IndexDefinition::searchableAttributes()` → Meilisearch
  `searchableAttributes` too), so results no longer depend on the engine. 13 Pest; harness green on
  Meilisearch (51), Typesense (53) and the file engine (49), back to back.
- Gotchas: Typesense `string*` stays unresolved until a document has a value, and `query_by` can't name an
  unresolved field — one always-empty custom field broke every search. Declare `string[]`. Typesense also
  rejects integer ids and a document missing a non-optional field. Harness entry 2 had an empty title
  (fails validation on save); the Bloodhound checks now title it.
- **2026-11-18 — Pro done** (Lite free / Pro $49). `CraftSearch` extends Craft's `Search` service (swapped
  in via `app->extend` + facade reset, Pro only): `.search()` goes to the engine only when Craft's candidate
  ids (query minus search) are all live members of one index on one site — checked with a count, never
  inferred from params — else `parent::searchElements()`. Synonyms (mutual groups; Meilisearch
  `synonyms`, Typesense collection synonyms `bloodhound_*`) pushed on `PluginSettingsSaved`; stop words
  stripped from query text (engine-neutral). Swap rebuilds: Meilisearch `__next` + `swapIndexes`,
  Typesense timestamped collection + alias; re-sync of elements changed during the build. Harness:
  61 / 74 / 79 checks green (file engine / Meilisearch / Typesense).
- Gotchas: Craft 6 `orderBy('score')` is **ascending** (least relevant first) — templates need
  `'score desc'`. Craft 6 reads `icon.svg` from src/ then resources/, but `icon-mask.svg` only from
  resources/.
- Branding: icon traced from Justin's artwork (src/icon.jpg) with potrace onto the family tile, #572261.
  Marketing site: justinholt.com/plugins/craft-bloodhound (staged, `showOnSite` false), docs in docs/,
  promos in promos/.
- **2026-11-18 — Users and Commerce products.** Per-type text/filter/sort lists live on `IndexDefinition`
  (`builtInText`, `filterableAttributes`, `sortableAttributes`) and feed Meilisearch settings and Typesense
  schemas. Users: active only, scoped by group; title = full name (else username); email never indexed, nor a
  username containing `@`. Suspend/unsuspend/activate/deactivate and group assignment don't save the
  element, so `UserSuspended`, `UserUnsuspended`, `UserActivated`, `UserDeactivated` and
  `UserAssignedToGroups` re-sync too. Products: `CraftCms\Commerce\Product\Elements\Product` (Commerce 6.x
  is native, no yii2), live only, scoped by product type; `sku` = every enabled variant's SKU, `price` =
  default price. A variant save resolves to its product; the listener is `scoped` and dedupes per
  request so a product save's variant saves don't queue N jobs. Without Commerce, product indexes are
  skipped with one log warning. `craft/commerce` 6.x-dev is require-dev only (Larastan).
- Harness: Commerce 6.x-dev needs `craftcms/cms` 6.x-dev, so products were verified on a temporary
  upgrade (snapshot `bh-pre-commerce`, then restored to beta.1). Product checks run only when Commerce is
  enabled; `tests/Harness/commerce-fixtures.php` seeds `gear`/`books` types and four products. With Commerce:
  file engine 91, Meilisearch 110, Typesense 115 (+2 CP 500s from Commerce dev's unbuilt Vite assets —
  environment). On beta.1 without Commerce: 79 / 95 / 100, all green.
- **2026-11-18 — GraphQL (Lite and Pro).** `Gql\SearchQuery` registered via `GqlQueries::register()`;
  `bloodhoundSearch(index, query, site, page, perPage, where)` → `BloodhoundSearchResults` with
  `elements: [ElementInterface]`. Permission per index: schema component `bloodhound.{handle}:read`
  (`GqlSchemaComponentsResolving`); no component → query absent; unknown and disallowed indexes give the same
  error. Hits are filtered through Craft's/Commerce's own GraphQL resolvers (`Entry/Asset/User/Product
  ::prepareQuery(null, [])`, all public) so section/volume/group/product-type scope and token-user `editable()`
  apply. Counts: engine totals only when the schema reads the whole index (count check); otherwise top 1,000 ranked ids → readable subset → counted and paged locally, so unreadable matches never leak as numbers (security review finding). `where` must name a filterable attribute (UserError). Settings save invalidates GraphQL caches. Pro's
  `CraftSearch` takeover already covers GraphQL `search:` (verified incl. `orderBy: "score desc"`;
  `searchTermOptions` turns search into an array → Craft). Harness: `gql-fixtures.php` (3 schemas/tokens,
  cleaned up at the end); with Commerce, products over GraphQL verified too. 114 / 119 / 97 green on beta.1.
- **2026-11-18 — Multisite verified, sidebar link.** Harness: `tests/Harness/multisite-fixtures.php` adds an `fr`
  site on a snapshot (Craft's ResaveElements job fails to serialize a Closure while propagating — Craft beta bug;
  entries propagate anyway). Per-site indexes, per-site disable, GraphQL `site`, Pro takeover on fr all verified on
  three engines (142 / 147 / 120). Found + fixed: an unknown site handle in search reached Scout's Typesense
  engine, which **created** `…_nope` collections — `Indexer::engineIndex()` now rejects unknown sites, search
  returns empty, commands error. Found + fixed: interrupted Typesense swaps left orphan `{name}_YmdHis`
  collections forever — `TypesenseSchema::dropBuilds()` after each swap and on flush. Harness gotchas: the
  harness's custom fields aren't translatable (a French value overwrites English); `ddev snapshot restore` can
  leave MySQL's FULLTEXT index on `searchindex` returning nothing — `ALTER TABLE searchindex ENGINE=InnoDB`.
  Sidebar: `showInCpNav` (default on) sets `hasCpSection`; `getCpNavItem()` → utilities/bloodhound, hidden
  without `utility:bloodhound`; `cpNavLabel`; `cpNavIcon` via `IconPicker->sets('*')`, regex-validated, and
  `Settings::isIcon()` (system icon file or icon-set SVG) at render. Not done: per-site language hints to
  engines (Typesense `locale`, Meilisearch `localizedAttributes`) — matters for CJK/Thai.
- Open: Algolia (needs a paid account to verify). - **2026-11-18 — Scout `database` driver tested and dropped.** `DatabaseEngine::update()` stores nothing
  and searches query the model's own table (`search_documents`, which doesn't exist): import reported
  "3 documents", every search failed. `CollectionEngine` works the same way. `SearchDocument::engine()` now
  refuses both with a clear `LogicException`; import prints it and exits 1, searches show "unavailable",
  saves still work. Harness checks it on every run.

## Phases
1. **Indexes (Lite).** CP-defined indexes: element type, sources (sections/volumes/groups), condition builder,
   per-site, and a field → document mapping (or a transformer class). Sync on save/delete/status change,
   queued; `craft:bloodhound:import|flush|sync`.
2. **Querying (Lite).** Twig/PHP: `bloodhound.search('articles', q, {filters, page})` returning hydrated
   elements (eager-loaded, order preserved) plus facets/highlights where the engine has them.
3. **Take over Craft search (Pro).** Hook `SearchStarting`/`SearchResultsResolving` so `.search()` on element
   queries — including the CP's own search boxes — is answered by the engine.
4. **Pro extras.** Synonyms/stop-words/ranking settings pushed to engines that support them, index health page
   (drift vs source, like Holmes), zero-downtime reindex via index aliases.

## Risks / open questions
- Confirm Craft 6 search events allow replacing results entirely (not only re-scoring).
- Multi-site documents: one index per site vs a site attribute.

## Definition of done (every phase)
`bin/port-check craft-bloodhound` green · Pest suite green · Pint + Larastan level 6 clean · installs, uninstalls and
reinstalls cleanly in the harness · CP pages smoke-tested with `bin/cp-session`.
