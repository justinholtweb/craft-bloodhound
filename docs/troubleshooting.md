---
title: Troubleshooting
slug: troubleshooting
order: 80
summary: Empty results, filters that error, scheduled entries, and the Craft 6 beta.
---

## "Search is temporarily unavailable."

The engine couldn't be reached or rejected the query. The real error is in the log
(`storage/logs`). Check `SCOUT_DRIVER` and the engine's host and key.

## No results at all

- Has the index been imported? `php artisan craft:bloodhound:status` shows live elements against
  documents.
- Is `queueUpdates` on with no queue worker running? Jobs wait in the queue until a worker runs.
- Is the field you're searching in **Fields to index**? Only listed fields (and the title) are
  searched.

## A `where` filter returns nothing

Only `siteId`, `section`, `type` (entries) or `siteId`, `volume`, `kind` (assets) are filterable.
After changing engines, re-run `import` so the engine is told about them.

## An entry went live on schedule but isn't searchable

Craft fires no event when a post date passes. Make sure Laravel's scheduler is running —
`craft:bloodhound:sync-scheduled` runs every 15 minutes.

## Something broke after a Craft 6 update

Craft 6 is still in beta, and its APIs can change between betas. Check the Bloodhound changelog for
a matching release, and keep Bloodhound off production sites until you've tested the combination
on staging.
