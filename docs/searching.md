---
title: Searching (Twig and Blade)
slug: searching
order: 30
summary: bloodhound.search() in Twig, $bloodhound->search() in Blade, results, filters and pagination.
---

Craft 6 renders both Twig and Blade, and Bloodhound gives both the same API — the `bloodhound`
template global.

## Twig

```twig
{% set results = bloodhound.search('articles', app('request').query('q'), {
    page: app('request').query('page', 1),
    perPage: 20,
}) %}

{% for entry in results %}
    <a href="{{ entry.url }}">{{ entry.title }}</a>
{% endfor %}

<p>{{ results.total }} results, page {{ results.page }} of {{ results.pages() }}</p>
```

## Blade

```blade
@php($results = $bloodhound->search('articles', request('q'), ['page' => request('page', 1), 'perPage' => 20]))

@foreach ($results as $entry)
    <a href="{{ $entry->url }}">{{ $entry->title }}</a>
@endforeach

<p>{{ $results->total }} results, page {{ $results->page }} of {{ $results->pages() }}</p>
```

## Options

| Option | Default | Notes |
| --- | --- | --- |
| `page` | `1` | |
| `perPage` | `20` | Capped at 100. |
| `site` | current site | A site handle. Each site has its own index. |
| `where` | none | Attribute → value, e.g. `{section: 'news'}`. |

Filterable attributes are `siteId` plus `section` and `type` for entries, `volume` and `kind` for
assets, `groups` for users (matches users in that group) and `productType` and `price` for
products. `import` declares them to Meilisearch and Typesense for you. Filter values are
always matched literally, so passing a visitor's input straight into `where` can't widen the
filter.

## Results

`results` is iterable and countable, and has:

- `total`, `page`, `perPage`, `query`
- `pages()`, `hasNext()`, `hasPrevious()`
- `error` — `null`, or a generic message when the engine couldn't be reached

Hits come back as **real Craft elements in the engine's rank order**. They are re-read through the
index's live-only element query, so a stale document can never surface a disabled, expired or
deleted element.

## What a query matches

The title first, then the index's fields in the order you listed them (assets also match their
filename and alt text, users their username, products every variant's SKU). URLs and handles are not searched, on any engine — so results don't depend
on which engine you run.

## When the engine is down

The search returns no results and sets `results.error` to "Search is temporarily unavailable."
Details go to the log, never the page, and the page still renders. An empty query returns no
results without contacting the engine.
