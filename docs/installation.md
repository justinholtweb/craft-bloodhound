---
title: Installation
slug: installation
order: 10
summary: Requirements, the Craft 6 beta caution, install, and your first index.
---

> **Craft 6 only, and still in beta.** Bloodhound is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Bloodhound (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

## Requirements

- Craft CMS **6.0.0-beta.1** or later (native Craft 6 — no Yii 2 adapter)
- PHP 8.5
- Laravel Scout 11 (installed with the plugin) and a search engine Scout supports.
  Meilisearch and Typesense are configured for you; see
  [Engines](/plugins/craft-bloodhound/docs/engines/).

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Price | Free | $49 |
| Indexes, sync, Twig + Blade search | ✓ | ✓ |
| Index health, Repair, Rebuild | ✓ | ✓ |
| Craft's own `.search()` answered by your engine | | ✓ |
| Synonyms and stop words | | ✓ |
| Zero-downtime rebuilds | | ✓ |

## Install

```sh
composer require justinholtweb/craft-bloodhound
php artisan craft:plugin:install bloodhound
```

## Point Scout at an engine

Bloodhound uses Laravel Scout's own configuration — set the driver and its connection in `.env`.

Meilisearch:

```sh
composer require meilisearch/meilisearch-php
```

```dotenv
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=your-master-key
```

Typesense:

```sh
composer require typesense/typesense-php
```

```dotenv
SCOUT_DRIVER=typesense
TYPESENSE_HOST=127.0.0.1
TYPESENSE_API_KEY=your-api-key
```

You don't write a Typesense collection schema or `query_by` in `config/scout.php` — Bloodhound
builds both for each index.

## Your first index

1. **Settings → Plugins → Bloodhound.** Add an index: a handle (`articles`), the element type
   (entries or assets), the sections or volumes it covers, and the custom fields to copy into
   each document.
2. Import it:

   ```sh
   php artisan craft:bloodhound:import articles
   ```

3. Search it from a template — see [Searching](/plugins/craft-bloodhound/docs/searching/).

After the import, saves and deletes keep the index in sync. Run Laravel's scheduler so
`craft:bloodhound:sync-scheduled` (every 15 minutes) catches entries whose post or expiry date
passes without anyone saving them.
