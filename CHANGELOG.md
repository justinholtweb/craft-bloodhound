# Release Notes for Bloodhound

## 6.0.0-beta.1 - Unreleased

### Added
- Search indexes for entries and assets, defined in Settings → Bloodhound, kept per site in any Laravel Scout engine.
- Sync on save and delete (queued by default), plus `craft:bloodhound:sync-scheduled` for scheduled and expiring entries.
- `craft:bloodhound:import [index] [--site] [--fresh]` and `craft:bloodhound:flush`.
- Utilities → Bloodhound: per-index health (missing, orphaned, stale documents) with Repair and Rebuild.
- `craft:bloodhound:status [--fail-on-drift]` and `craft:bloodhound:repair [--dry-run]`.
- Meilisearch filterable and sortable attributes are declared on import.
- Typesense support: collection schemas and `query_by` are built per index, collections gain new index fields on import, and index health works on Typesense.
- Pro: Craft's own `.search()` answered by the engine for front-end queries an index fully covers, falling back to Craft's search otherwise.
- Pro: synonyms (pushed to Meilisearch and Typesense on settings save) and stop words.
- Pro: zero-downtime rebuilds — `craft:bloodhound:import --swap`, and the utility's Rebuild.
- Every engine searches the same fields (title, then the index's fields), so results don't depend on the engine.
- `bloodhound.search()` for Twig and `$bloodhound->search()` for Blade, returning live elements in rank order.
