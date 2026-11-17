# Bloodhound

Laravel Scout for Craft elements — index entries, assets, users or products into Meilisearch, Typesense, Algolia or the database driver.

> **Status:** scaffold — nothing user-facing yet. See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later (native Craft 6 plugin, no Yii 2 adapter)
- PHP 8.5
- Laravel integration: laravel/scout. Scout v11, Laravel 13 compatible. Engines: Meilisearch, Typesense, Algolia, database.

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
