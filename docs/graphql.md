---
title: GraphQL
slug: graphql
order: 35
summary: The bloodhoundSearch query for headless sites, its permissions, and Pro's engine-backed search argument.
---

Bloodhound works over Craft's GraphQL API in both editions. Lite adds a `bloodhoundSearch` query;
Pro also answers GraphQL's own `search:` argument from your engine.

## `bloodhoundSearch` (Lite and Pro)

```graphql
{
  bloodhoundSearch(index: "articles", query: "lantern", perPage: 10, page: 1) {
    total
    pages
    error
    elements {
      id
      title
      url
      ... on EntryInterface { sectionHandle postDate }
    }
  }
}
```

| Argument | Default | Notes |
| --- | --- | --- |
| `index` | required | The index handle. |
| `query` | required | What to search for. |
| `site` | the schema's primary site | A site handle the schema can read. |
| `page` | `1` | |
| `perPage` | `20` | Capped at 100. |
| `where` | none | Filters on the index's filterable attributes: `[{attribute: "section", value: "news"}]`. Values match literally; any other attribute is an error. |

`elements` are real Craft elements in rank order, typed as `ElementInterface`. Ask for their fields
with fragments: `... on EntryInterface`, `... on AssetInterface`, `... on UserInterface`, or
Commerce's product interface. If the engine is down, `elements` is empty and `error` says search
is temporarily unavailable; the request itself doesn't fail.

## Permissions

Each index is a permission in the GraphQL schema editor (**Settings → GraphQL → Schemas**), under
**Bloodhound**: “Search the “articles” index”. A schema without any of them doesn't have the
`bloodhoundSearch` query at all, and asking for an index the schema can't search gets the same
error as an index that doesn't exist.

**Results are still limited to what the schema can read.** Hits are passed through Craft's own
GraphQL rules for each element type — sections, volumes, user groups and Commerce product types,
and for token-authenticated requests, what that user may view. A schema allowed to search an index
of `news` entries but not to read the `news` section gets no elements back.

**Counts never include what the schema can't read.** When the schema can read everything in the
index, `total` and `pages` come straight from the engine. When it can read only part of it,
Bloodhound takes the top 1,000 matches, keeps the readable ones, and counts and pages those, so a
match in a section the schema can't see never shows up, not even as a number.

User indexes never contain email addresses, so a user search can't find anyone by email over
GraphQL either.

## Pro: GraphQL's own `search:` argument

With **Replace Craft search** switched on, the `search:` argument on `entries`, `assets`, `users`
and Commerce's `products` queries is answered by your engine, under the same rules as in templates:
only when every element the query could return is held by one index, on one site. Sort with
`orderBy: "score desc"` to get the best matches first.

```graphql
{
  entries(section: "news", search: "lantrn", orderBy: "score desc") { id title }
}
```

Queries that use `searchTermOptions`, or Craft search syntax like `title:lantern`, stay with Craft's
own search.
