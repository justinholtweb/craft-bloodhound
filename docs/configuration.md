---
title: Configuration
slug: configuration
order: 20
summary: Indexes, the index prefix, and queued updates.
---

Everything lives in **Settings → Plugins → Bloodhound**. The engine connection is not a
Bloodhound setting: it is Laravel Scout's own (`SCOUT_DRIVER`, `MEILISEARCH_HOST`,
`TYPESENSE_HOST`, …).

## Indexes

Each row is one index:

| Column | What it does |
| --- | --- |
| **Handle** | Lower-case letters, numbers and underscores, starting with a letter (`articles`). Used in templates and commands. |
| **Elements** | `Entries` or `Assets`. |
| **Sections / volumes** | Section handles (entries) or volume handles (assets), comma-separated. Empty means all of them. Nested entries (Matrix) are never indexed on their own. |
| **Fields to index** | Custom field handles copied into each document, in ranking order. |

Every index is kept **per site** in the engine, named `{scout prefix}{index prefix}{handle}_{site}`
— for example `production_articles_default`.

Run `craft:bloodhound:import` after adding or changing an index.

### What goes into a document

Every document carries `id`, `title`, `url`, `siteId` and `dateUpdated`. Entries add `section`,
`type` and `postDate`; assets add `volume`, `kind`, `filename` and `alt`. Then each field you list:

- rich text and HTML become plain text (capped at 20,000 characters)
- relations become the related elements' names
- dates become Unix timestamps

**Only live elements are indexed** — enabled, for that site, with a post date in the past and no
expiry date passed. Disabled, pending, expired and deleted elements are removed from the index.

## Index prefix

Prepended to every engine index name, so environments don't share indexes. Accepts an environment
variable, e.g. `$CRAFT_ENVIRONMENT`. Letters, numbers, `_` and `-` only.

## Queue index updates

On (the default): saves and deletes push a job, and the engine is written from the queue.
Off: the engine is written during the save request. Either way, a failing engine never stops an
entry from saving.
