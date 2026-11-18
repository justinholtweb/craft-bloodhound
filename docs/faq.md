---
title: FAQ
slug: faq
order: 90
summary: Craft 6 and the beta, editions, engines, and what gets indexed.
---

## Does Bloodhound work on Craft 5?

No. It is a native **Craft 6** plugin built on Laravel Scout. Craft 6 is still in beta.

## Is it safe to use in production?

Use caution. Craft 6 and Bloodhound (`6.0.0-beta.1`) are both in beta, so expect changes between
releases. Test on staging, keep backups, and only move to production once you're comfortable with
the beta.

## Is Bloodhound free?

Lite is free, and not a trial: indexes, sync, Twig and Blade search, and index health. Pro is $49
and adds Craft's own `.search()` answered by your engine, synonyms and stop words, and
zero-downtime rebuilds.

## Why are my `.search()` results least-relevant first?

In Craft 6, `.orderBy('score')` sorts ascending. Use `.orderBy('score desc')`.

## Does it work with GraphQL?

Yes, in both editions. Lite and Pro add a `bloodhoundSearch` query; each index is a permission in
the GraphQL schema editor, and results only include elements the schema can read. On Pro, GraphQL's
own `search:` argument is answered by your engine as well. See [GraphQL](graphql).

## Which engines does it support?

Meilisearch and Typesense, configured for you, including index health. Algolia should work but
hasn't been verified yet. Scout's `database` and `collection` drivers aren't supported: they search
your database tables rather than storing documents, which would only repeat what Craft's built-in
search already does. See [Engines](engines) for the details.

## Will a disabled or expired entry ever show up in results?

No. Only live elements are indexed, and every hit is re-read through a live-only element query
before it reaches your template.

## Does it replace the control panel's search?

No. The control panel searches disabled entries and drafts, and the index only holds live
elements, so CP search stays with Craft.
