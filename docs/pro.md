---
title: Pro features
slug: pro
order: 60
summary: Craft's own search answered by your engine, synonyms and stop words, and zero-downtime rebuilds.
---

Pro is **$49**. Everything in Lite, plus:

## Craft's own search, answered by your engine

Switch on **Replace Craft search** (`replaceCraftSearch`, off by default). Craft's own `.search()`
on front-end element queries — live entries, assets and Commerce products, or active users, all
covered by one index — is then
answered by your search engine. Existing templates get the engine's relevance and typo tolerance
with **no template changes**:

```twig
{% set results = craft.entries.section('news').search(q).orderBy('score desc').all() %}
```

```blade
@php($results = \CraftCms\Cms\Entry\Elements\Entry::find()->section('news')->search($q)->orderBy('score desc')->all())
```

> **Tip: sort by `score desc`.** In Craft 6, `.orderBy('score')` sorts ascending — least relevant
> first. Use `orderBy('score desc')` to get the best matches at the top.

This covers GraphQL too: the `search:` argument on `entries`, `assets`, `users` and `products`
queries goes through the same takeover (see [GraphQL](graphql)).

Bloodhound only answers a query it can answer completely. It falls back to Craft's built-in search
when:

- the query could return anything that isn't live in a single index — disabled entries, drafts,
  other sections, and the control panel's own search boxes
- the query spans more than one site
- the search uses Craft's search syntax: `title:foo`, `-word`, quoted phrases, `*` or `OR`
- the engine errors or can't be reached

The engine returns at most 1,000 hits per query to Craft's search.

## Synonyms and stop words

- **Synonyms** (`synonyms`) — a table with a `term` and its `synonyms` (comma-separated), e.g.
  `sofa` → `couch, settee`. Each row is a group that works in every direction: searching for
  `couch` finds `sofa` too. Pushed to Meilisearch and Typesense when you save the settings.
- **Stop words** (`stopWords`) — words to ignore, separated by commas or new lines. They're dropped
  from queries on every engine. A query made only of stop words is searched as typed, so it never
  comes back empty just because every word was ignored.

## Zero-downtime rebuilds

```sh
php artisan craft:bloodhound:import articles --swap
```

Builds a fresh index alongside the live one, then swaps it in — a Meilisearch index swap, or a
Typesense alias — so searches keep working, against complete results, for the whole reindex.
`--swap` can't be combined with `--fresh`. In Pro, the utility's **Rebuild** button uses the swap
too.
