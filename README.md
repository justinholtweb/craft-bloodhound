# Bloodhound

Laravel Scout for Craft elements — index entries, assets, users or products into Meilisearch, Typesense, Algolia or the database driver.

> **Status:** phase 1 (indexes, sync, search from Twig + Blade) verified against Meilisearch in the Craft 6 harness. See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later (native Craft 6 plugin, no Yii 2 adapter)
- PHP 8.5
- Laravel integration: laravel/scout. Scout v11, Laravel 13 compatible. Engines: Meilisearch, Typesense, Algolia, database.

## Setup

1. Configure Scout as you would in any Laravel app (`SCOUT_DRIVER=meilisearch`, `MEILISEARCH_HOST`, `MEILISEARCH_KEY`,
   and `composer require meilisearch/meilisearch-php` — or Algolia's client).
2. Settings → Bloodhound: add an index (handle, entries or assets, which sections/volumes, which fields).
3. `php artisan craft:bloodhound:import` — after that, saves and deletes keep it in sync. Schedule Laravel's
   scheduler: `craft:bloodhound:sync-scheduled` runs every 15 minutes to catch entries that go live or expire.

Only **live** elements are indexed, and hits are re-read through a live-only element query, so stale
documents can never surface disabled, expired or deleted content.

## Searching: Twig and Blade

```twig
{% set results = bloodhound.search('articles', app('request').query('q'), {page: 1, perPage: 20}) %}
{% for entry in results %}<a href="{{ entry.url }}">{{ entry.title }}</a>{% endfor %}
{{ results.total }} results, page {{ results.page }} of {{ results.pages() }}
```

```blade
@php($results = $bloodhound->search('articles', request('q'), ['page' => 1, 'perPage' => 20]))
@foreach ($results as $entry)<a href="{{ $entry->url }}">{{ $entry->title }}</a>@endforeach
{{ $results->total }} results, page {{ $results->page }} of {{ $results->pages() }}
```

Options: `page`, `perPage` (max 100), `site` (handle), `where` (attribute → value). Filterable attributes are
`siteId`, `section`, `type` (entries) or `siteId`, `volume`, `kind` (assets); `import` declares them to
Meilisearch for you. If the engine is unreachable, results are empty and `results.error` says why —
the page still renders.

## Editions and pricing

Lite / Pro — Lite free / Pro $79 (provisional).

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
```

Tested in the Craft 6 harness at `~/Sites/plugin-testing-craft6` (`bin/plugin-wire craft-bloodhound --install`).
