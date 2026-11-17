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
