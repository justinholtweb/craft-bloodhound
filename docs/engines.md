---
title: Engines
slug: engines
order: 40
summary: Meilisearch and Typesense are configured for you; any Laravel Scout engine works.
---

Bloodhound writes through **Laravel Scout**, so it works with any engine Scout does. Two get
extra care.

## Meilisearch

`import` declares each index's searchable attributes (title, then your fields), filterable
attributes (`siteId`, `section`, `type` or `siteId`, `volume`, `kind`) and sortable attributes
(`dateUpdated`, plus `postDate` for entries). Index health is built in.

## Typesense

Scout expects one collection schema and one `query_by` list per model class, but every Bloodhound
index shares a single document class — so Bloodhound builds both **per index** instead:

- a typed schema: `title`, `url`, `siteId`, `dateUpdated`, the entry or asset attributes, and your
  fields as string lists
- `query_by` = the title, then your fields, sent with every search

`import` creates the collection, or adds fields the index has gained since. Changing a field's
type needs `craft:bloodhound:import --fresh`. Index health is built in.

## Other engines

Algolia, the database driver and any custom engine work for indexing and searching. Index health
needs the engine to list its documents; implement
`JustinHoltWeb\Bloodhound\Engines\ListsDocuments` on the engine to enable it.
