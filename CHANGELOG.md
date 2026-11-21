# Release Notes for Bloodhound

## 6.0.0-beta.2 - 2026-11-21

### Changed
- The documentation link (Plugin Store and README) now points at the docs on justinholt.com.
- The docs FAQ now compares Bloodhound with Caffeine and explains why Holmes can't see its indexes.

## 6.0.0-beta.1 - 2026-11-18

### Added
- Search indexes for entries, assets, users and Craft Commerce products, defined in Settings → Bloodhound, kept per site in Meilisearch, Typesense or Algolia through Laravel Scout.
- User indexes hold name and username, never email addresses, and follow suspensions, activations and group changes.
- Product indexes search every variant's SKU, filter on product type and price, and re-sync when a variant changes. They need Craft Commerce; without it they're skipped.
- Sync on save and delete (queued by default), plus `craft:bloodhound:sync-scheduled` for scheduled and expiring entries and products.
- `craft:bloodhound:import [index] [--site] [--fresh]` and `craft:bloodhound:flush`.
- Utilities → Bloodhound: per-index health (missing, orphaned, stale documents) with Repair and Rebuild.
- `craft:bloodhound:status [--fail-on-drift]` and `craft:bloodhound:repair [--dry-run]`.
- Meilisearch filterable and sortable attributes are declared on import.
- Typesense support: collection schemas and `query_by` are built per index, collections gain new index fields on import, and index health works on Typesense.
- Pro: Craft's own `.search()` answered by the engine for front-end queries an index fully covers, falling back to Craft's search otherwise.
- Pro: synonyms (pushed to Meilisearch and Typesense on settings save) and stop words.
- Pro: zero-downtime rebuilds — `craft:bloodhound:import --swap`, and the utility's Rebuild.
- Scout's `database` and `collection` drivers are refused with a clear message: they search model tables and can't hold Bloodhound's documents.
- Multisite: one engine index per site; an unknown site handle finds nothing and never creates an engine index, and commands reject it.
- A sidebar link to the index health page, with an optional custom label and icon (Craft icons or custom icon sets); can be switched off.
- Typesense: builds left by an interrupted swap rebuild are removed by the next swap and by `flush`.
- Every engine searches the same fields (title, then the index's fields), so results don't depend on the engine.
- `bloodhound.search()` for Twig and `$bloodhound->search()` for Blade, returning live elements in rank order.
- GraphQL: a `bloodhoundSearch` query (Lite and Pro). Each index is a schema permission; hits, totals and pages only ever count elements the schema can read, and filters must name a filterable attribute.
- Pro: GraphQL's `search:` argument on entries, assets, users and products is answered by the engine, like `.search()` in templates.
