---
name: wp-content-edit
description: The STANDARD way to edit live content (ACF fields, flexible-content blocks) on a WP Engine-hosted headless client site, and get the change to actually show on the live frontend. Read before touching live WP content on any client site — don't improvise the SSH/SFTP dance each time.
---

# wp-content-edit — editing live WP content the same way every time

Every WP Engine-hosted client site needs the same three steps to change live content and see it on the frontend. This used to get re-derived from scratch per session (slow, error-prone). Don't — follow this.

## Step 1 — find what to change

Field values live in `wp_postmeta` as ACF serialized data. List them (single-token wp-cli commands work fine over the SSH gateway — see `wpe-ssh-gateway` below):

```bash
ssh -i <client-ssh-key> <install>@<install>.ssh.wpengine.net \
  "wp post meta list <post_id> --format=table"
```

Each ACF value has a paired `_<key>` row holding the **field key** (e.g. `field_wn_sr_items_wn`) — you need the field key, not the meta key, to write back with `update_field()`.

To find the right post, `wp post list --post_type=page --fields=ID,post_title,post_name --format=table`, and for the real live front page (not WP's `Settings > Reading`, which headless frontends usually ignore — check `src/app/page.tsx` for a hardcoded slug) confirm against the Next.js root route, not `wp option get page_on_front`.

## Step 2 — write the change via `wp eval-file`, not `wp option update` / `wp post meta update`

Any wp-cli argument containing a space breaks on WP Engine's SSH gateway (see `wpe-ssh-gateway` skill) — that rules out inline `wp post meta update` for real content (English has spaces). The reliable path: write a small PHP script using ACF's `update_field($field_key, $value, $post_id)`, then run it with `wp/cli/wpe-run-php.sh` (handles the SFTP-upload + `wp eval-file` + cleanup automatically):

```php
<?php
$post_id = 1159;
$ok = update_field('field_wn_sr_items_wn', [
    ['value' => '3x', 'label' => 'More leads captured outside office hours', 'description' => ''],
    // ...
], $post_id);
echo 'updated: ' . var_export($ok, true) . "\n";
```

```bash
WPE_INSTALL=podwebnav7945 \
WPE_SSH_KEY=~/.ssh/id_ed25519_pod \
WPE_SFTP_USER=podwebnav7945-jacob \
WPE_SFTP_PASSWORD_FILE=~/.pod-secrets/website-navigator-sftp-password.txt \
  ./wp/cli/wpe-run-php.sh ./my-update.php
```

`update_field()` does **not** fire `save_post`, so it will not trigger `pod-revalidate-webhook.php` on its own — that's Step 3, not optional.

## Step 3 — revalidate and VERIFY against the CDN, not the API response

```bash
curl -s -X POST "https://<site>/api/revalidate?secret=<REVALIDATE_SECRET>" \
  -H "Content-Type: application/json" -d '{"tags":["pages"]}'
```

`{"revalidated":true}` in the response is **not proof it worked** — see the `revalidation` skill's "max"-profile bug (fixed 2026-10-13, but re-verify on any site that predates the fix). Confirm the CDN actually purged:

```bash
curl -sI "https://<site>/" | grep -i "age:\|x-vercel-cache"
```

`age` must be low/reset after the revalidate call. If it keeps climbing across repeated requests, the purge didn't happen — check `src/app/api/revalidate/route.ts` uses `revalidateTag(tag, { expire: 0 })`, not a string profile like `"max"`.

## Credentials

Per-client WP Engine SSH key + SFTP user/password live outside any repo — see the client's project memory (e.g. `[[website-navigator-sftp-credential]]`) for where they're stored (`~/.pod-secrets/`). Never print credential file contents to stdout/transcript; pass file paths to `sshpass -f`, never `-p`.

## Don't

- Don't hand-write the SFTP-upload-then-eval-file sequence again — use `wp/cli/wpe-run-php.sh`.
- Don't trust `{revalidated:true}` as proof of a live change — check the CDN `age` header.
- Don't use `wp option update`/`wp post meta update` with any value containing a space over the SSH gateway — it silently breaks (see `wpe-ssh-gateway` skill).
- Don't leave the uploaded PHP file on the server — the script's cleanup trap handles this; if you bypass the script, delete it yourself over the same SFTP session.
