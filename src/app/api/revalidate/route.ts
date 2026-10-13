import { revalidateTag } from "next/cache";
import { NextRequest, NextResponse } from "next/server";
import { PAGES_TAG, POSTS_TAG, CHROME_TAG, CASE_STUDIES_TAG } from "@/lib/cms";

// On-demand ISR (workflow/02): WP "post saved" webhook → this route → revalidateTag.
// Configure the webhook to POST .../api/revalidate?secret=<REVALIDATE_SECRET>
// with an optional JSON body: { "tags": ["pages", "page:home"] }.

// Health check (no secret required): lets monitoring / the WP webhook setup confirm the
// endpoint is live and whether REVALIDATE_SECRET is wired in this environment. Reports only
// a boolean — never the secret value.
export async function GET() {
  return NextResponse.json({
    ok: true,
    endpoint: "revalidate",
    knownTags: [PAGES_TAG, POSTS_TAG, CHROME_TAG, CASE_STUDIES_TAG],
    secretConfigured: Boolean(process.env.REVALIDATE_SECRET),
  });
}

export async function POST(request: NextRequest) {
  const secret = request.nextUrl.searchParams.get("secret");
  if (!process.env.REVALIDATE_SECRET || secret !== process.env.REVALIDATE_SECRET) {
    return NextResponse.json({ revalidated: false }, { status: 401 });
  }

  const body: unknown = await request.json().catch(() => ({}));
  const requested =
    typeof body === "object" && body !== null && "tags" in body ? body.tags : null;
  const tags =
    Array.isArray(requested) && requested.every((t) => typeof t === "string")
      ? requested
      : ["pages"];

  // { expire: 0 }, not a named profile like "max": this project has no `cacheLifeProfiles`
  // configured (cacheComponents is off), so a string profile resolves to `undefined` inside
  // Next's revalidate() and SKIPS setting pathWasRevalidated — the flag that actually purges
  // the Full Route Cache/CDN artifact (confirmed in next/dist/.../revalidate.js: the purge
  // only fires when `!profile || cacheLife?.expire === 0`). An explicit { expire: 0 } object
  // satisfies that check directly, with no dependency on named cache-life profiles. "max" was
  // a silent no-op — content updates never actually purged the CDN (FRICTION 2026-10-13).
  tags.forEach((tag) => revalidateTag(tag, { expire: 0 }));
  return NextResponse.json({ revalidated: true, tags });
}
