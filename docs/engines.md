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

These drivers don't store documents. They search a model's own database table, and Bloodhound's
documents have no table, so nothing could ever be found. Bloodhound refuses them: `import` stops
with a message telling you to set `SCOUT_DRIVER` to another engine, searches show "Search is
temporarily unavailable", and saving content keeps working.
