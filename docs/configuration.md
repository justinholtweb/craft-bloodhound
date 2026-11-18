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
| **Elements** | `Entries`, `Assets`, `Users` or `Products (Commerce)`. |
| **Sections / volumes / groups / product types** | Section handles (entries), volume handles (assets), user group handles (users) or product type handles (products), comma-separated. Empty means all of them. Nested entries (Matrix) are never indexed on their own. |
| **Fields to index** | Custom field handles copied into each document, in ranking order. |

Every index is kept **per site** in the engine, named `{scout prefix}{index prefix}{handle}_{site}`
— for example `production_articles_default`.

Run `craft:bloodhound:import` after adding or changing an index.

### What goes into a document

Every document carries `id`, `title`, `url`, `siteId` and `dateUpdated`. Then, by element type:

| Elements | Searched as text | Filter with `where` | Sort |
| --- | --- | --- | --- |
| Entries | title | `section`, `type` | `postDate` |
| Assets | title, `filename`, `alt` | `volume`, `kind` | — |
| Users | full name (as the title), `username` | `groups` | — |
| Products | title, `sku` (every enabled variant's SKU) | `productType`, `price` | `postDate`, `price` |

Then each field you list:

- rich text and HTML become plain text (capped at 20,000 characters)
- relations become the related elements' names
- dates become Unix timestamps

**Only live elements are indexed** — enabled, for that site, with a post date in the past and no
expiry date passed. Disabled, pending, expired and deleted elements are removed from the index.
For users that means **active** accounts: pending, inactive and suspended users are left out.

### Users

A user index holds each user's full name and username, plus the custom fields you list. **Email
addresses are never indexed**, and neither is a username that is an email address (sites with
`useEmailAsUsername`); those users are found by their name. If you search users from a public page,
list only fields you'd show publicly.

Users stay in step when they're saved or deleted, and also when they're suspended, unsuspended,
activated, deactivated or moved between groups — Craft doesn't save the user for those, so
Bloodhound listens for them separately.

### Products

Product indexes need **Craft Commerce** installed and enabled. Without it, a product index is skipped
(with a warning in the log) and every other index keeps working. A product's `price` is its default
price, and saving a single variant re-syncs its product. Scheduled and expiring products are caught
by `craft:bloodhound:sync-scheduled`, like entries.

## Index prefix

Prepended to every engine index name, so environments don't share indexes. Accepts an environment
variable, e.g. `$CRAFT_ENVIRONMENT`. Letters, numbers, `_` and `-` only.

## Queue index updates

On (the default): saves and deletes push a job, and the engine is written from the queue.
Off: the engine is written during the save request. Either way, a failing engine never stops an
entry from saving.
