---
title: Engines
slug: engines
order: 40
summary: Meilisearch and Typesense are configured for you; Algolia should work; Scout's database driver can't.
---

Bloodhound writes through **Laravel Scout** to an engine that stores documents. Meilisearch and
Typesense are tested and configured for you.

## Meilisearch

`import` declares each index's searchable, filterable and sortable attributes for its element
type (see [Configuration](configuration)). Index health is built in.

## Typesense

Scout expects one collection schema and one `query_by` list per model class, but every Bloodhound
index shares a single document class — so Bloodhound builds both **per index** instead:

- a typed schema: `title`, `url`, `siteId`, `dateUpdated`, the element type's attributes, and your
  fields as string lists
- `query_by` = the title, then your fields, sent with every search

`import` creates the collection, or adds fields the index has gained since. Changing a field's
type needs `craft:bloodhound:import --fresh`. Index health is built in.

## Algolia and custom engines

Algolia goes through Scout's standard engine and should work for indexing and searching, but it
**hasn't been verified yet** — test it on a staging site first. A custom Scout engine that stores
documents works the same way. Index health
needs the engine to list its documents; implement
`JustinHoltWeb\Bloodhound\Engines\ListsDocuments` on the engine to enable it.

## Not supported: Scout's `database` and `collection` drivers

**Why they can't work.** Most Scout engines keep their own copy of your content and search that.
The `database` and `collection` drivers don't: they store nothing, and search the database table
behind each Laravel model, treating the document's keys as column names. That suits a `posts`
table. Bloodhound's documents aren't rows anywhere. They're built for each element as it's
indexed (rich text as plain text, relations as their titles, every variant's SKU, and so on) and
handed to the engine to keep. With these drivers there's nothing to search: an import would report
success while storing nothing, and every search would fail.

**Why we didn't make them work.** Bloodhound could keep its own documents table for these drivers
to search. But what you'd get is keyword matching inside your database — no typo tolerance, no
relevance ranking, no synonyms — which is what Craft's built-in search already does, from its own
search index. That would be a third copy of your content for no better results, so Bloodhound
refuses these drivers instead. If you don't want to run a search server, Craft's own search is the
right tool. Typesense and Meilisearch are both free and open source, and run happily in DDEV or on
a small server.

**What happens if one is set.** `import` stops with a message telling you to set `SCOUT_DRIVER` to
another engine, searches show "Search is temporarily unavailable", and saving content keeps
working.
