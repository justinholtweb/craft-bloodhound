---
title: Index health
slug: index-health
order: 50
summary: See how the engine has drifted from Craft, and repair exactly that.
---

Search indexes drift: a queue worker dies, an engine is restored from an old snapshot, a deploy
skips a sync. **Utilities → Bloodhound** shows, for every index and site, live elements against
documents, and three kinds of drift:

- **Missing** — live elements with no document
- **Orphaned** — documents whose element isn't live any more
- **Stale** — documents older than their element's last edit

**Repair** re-syncs only those elements. **Rebuild** re-indexes everything as a queued job; clicking
it twice queues one job, not two.

The same from the command line:

```sh
php artisan craft:bloodhound:status                 # table of every index and site
php artisan craft:bloodhound:status --fail-on-drift # non-zero exit when anything drifted (CI, monitoring)
php artisan craft:bloodhound:repair articles --site=default --dry-run
php artisan craft:bloodhound:repair articles --site=default
```

Repair and Rebuild need the Bloodhound utility permission.
