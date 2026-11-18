# Bloodhound — plan

Laravel Scout for Craft elements — index entries, assets, users or products into Meilisearch, Typesense, Algolia or the database driver.

- Package: `justinholtweb/craft-bloodhound` · handle `bloodhound` · namespace `JustinHoltWeb\Bloodhound`
- Editions: Lite / Pro · pricing: Lite free / Pro $79 (provisional)
- Laravel integration: laravel/scout — Scout v11, Laravel 13 compatible. Engines: Meilisearch, Typesense, Algolia, database.
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
- Next (phase 2b): Typesense — needs the `kevinquillen/ddev-typesense` add-on in the harness (Justin runs it).
- Open: Typesense (needs a per-class
  collection schema in Scout config); Pro: take over Craft's own search, synonyms, zero-downtime reindex.

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
