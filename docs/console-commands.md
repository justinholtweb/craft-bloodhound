---
title: Console commands
slug: console-commands
order: 70
summary: Import, flush, scheduled sync, status and repair.
---

| Command | What it does |
| --- | --- |
| `craft:bloodhound:import [index] [--site=] [--fresh]` | Indexes every live element. `--fresh` deletes the engine index first. Pro: `--swap` rebuilds with zero downtime (not with `--fresh`). |
| `craft:bloodhound:flush <index> [--site=]` | Deletes an index from the engine. |
| `craft:bloodhound:sync-scheduled [--since=]` | Indexes entries and products that went live and removes those that expired since the last run. Scheduled every 15 minutes. |
| `craft:bloodhound:status [--fail-on-drift]` | Missing, orphaned and stale documents per index and site. |
| `craft:bloodhound:repair [index] [--site=] [--dry-run]` | Re-syncs only the drifted elements. |

Run them with `php artisan`. Omit the index to act on all of them, and `--site` to act on every
site.
