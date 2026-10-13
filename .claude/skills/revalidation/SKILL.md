---
name: revalidation
description: How a WordPress publish becomes fresh content — the cache-tag convention, the /api/revalidate webhook endpoint, and revalidateTag. Read before changing cache tags, the revalidate route, or wiring a new content type's freshness.
---

# revalidation — WP publish → live site (on-demand ISR)

Pages are static (see `page-templates`). They refresh **on demand** when WordPress fires a webhook — not on a timer.

## The flow

```
WP "post saved"  →  POST /api/revalidate?secret=<REVALIDATE_SECRET>   (body: { "tags": [...] })
                 →  revalidateTag(tag, { expire: 0 }) for each tag
                 →  tagged pages purge, regenerate on next request
```

## Cache tags — set on every read

`cmsRequest` tags each GraphQL fetch via `next: { tags }`. The reader functions in `src/lib/cms/index.ts` set the convention:

| Reader | Tags attached |
|---|---|
| `getPage(slug)` | `["pages", "page:<slug>"]` |
| `getPages()` | `["pages"]` |

So `revalidateTag("page:home")` refreshes just the home page; `revalidateTag("pages")` refreshes everything that read the list. `PAGES_TAG` is exported from `lib/cms` — reuse it, don't hardcode the string.

## The endpoint — `src/app/api/revalidate/route.ts`

```ts
export async function POST(request: NextRequest) {
  const secret = request.nextUrl.searchParams.get("secret");
  if (!process.env.REVALIDATE_SECRET || secret !== process.env.REVALIDATE_SECRET)
    return NextResponse.json({ revalidated: false }, { status: 401 });

  // body { tags: string[] } → revalidate those; omitted/invalid → default ["pages"] (purge all)
  tags.forEach((tag) => revalidateTag(tag, { expire: 0 }));
  return NextResponse.json({ revalidated: true, tags });
}
```

- **Auth**: the `?secret=` must equal `REVALIDATE_SECRET` (env). Missing env or mismatch → `401`. Configure the secret in the WP webhook URL.
- **Default**: if the body omits `tags`, it revalidates `["pages"]` — coarse but always-correct. Prefer sending precise tags (`["page:<slug>"]`) from the webhook so one edit doesn't purge the whole site.
- **`{ expire: 0 }`**, never a named string profile like `"max"`. This project has no `cacheLifeProfiles` configured (Cache Components is off) — a string profile silently resolves to `undefined` inside Next's `revalidate()`, which means the flag that purges the Full Route Cache/CDN artifact (`pathWasRevalidated`) never gets set. The Data Cache entry looks "revalidated" but the actual static HTML never regenerates — content changes never reach the live site, with no error anywhere. **Confirmed live** on Website Navigator 2026-10-13 (FRICTION): `revalidateTag(tag, "max")` returned `{revalidated:true}` every time, but CDN `Age` kept climbing for 5+ minutes. Always use the object form.

## Adding freshness for a new content type

1. Tag its reads in the new `lib/cms` reader (e.g. `["posts", "post:<slug>"]`).
2. Configure the WP webhook for that post type to POST those tags.
3. Test (below). Tag names are a contract between the reader and the webhook — keep them in sync.

## Testing

```bash
# valid (returns {revalidated:true,tags:[...]})
curl -XPOST "$SITE/api/revalidate?secret=$REVALIDATE_SECRET" -H 'content-type: application/json' -d '{"tags":["page:home"]}'
# wrong/absent secret → 401
curl -XPOST "$SITE/api/revalidate?secret=nope" -i
```

Revalidation only does something against a **real cached deployment** (Vercel preview/prod or a built `next start`) — `CMS_MODE=mock`/`pnpm dev` has nothing meaningful to purge. Test it where pages are actually statically cached.

**`{revalidated:true}` in the response is NOT proof the purge worked** — the route can return that even when `pathWasRevalidated` silently never got set (the exact "max" bug above). Confirm against the actual CDN response, not just the route's own reply:

```bash
curl -sI "$SITE/" | grep -i "age:\|x-vercel-cache"   # before
# ...POST to /api/revalidate...
curl -sI "$SITE/" | grep -i "age:\|x-vercel-cache"   # after — Age must reset near 0
```

## Don't

- Don't drop the secret check or read it from the body — it must come from the query string and match the env.
- Don't default to revalidating nothing — the current default (`["pages"]`) is intentional so a misconfigured webhook still refreshes.
- Don't pass a string cache-life profile (`"max"`, `"days"`, etc.) as the second argument to `revalidateTag` on this stack — there are no `cacheLifeProfiles` configured, so it silently no-ops the CDN purge. Always `{ expire: 0 }`.
