# Release Notes for Bloodhound

## 6.0.0-beta.1 - Unreleased

### Added
- Search indexes for entries and assets, defined in Settings → Bloodhound, kept per site in any Laravel Scout engine.
- Sync on save and delete (queued by default), plus `craft:bloodhound:sync-scheduled` for scheduled and expiring entries.
- `craft:bloodhound:import [index] [--site] [--fresh]` and `craft:bloodhound:flush`.
- `bloodhound.search()` for Twig and `$bloodhound->search()` for Blade, returning live elements in rank order.
