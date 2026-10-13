# MCP content editing

Editing ACF content on a live, hardened WP Engine-hosted site has two paths. **MCP is the
default for routine ACF field edits; SSH/wp-cli stays the fallback for everything else**
(plugin/option/user management, one-off diagnostics, anything not covered by a registered
ability). See `wpe-ssh-gateway` + `wp-content-edit` skills for the SSH/wp-cli path.

## Why MCP exists here

The SSH/SFTP/`wp eval-file` flow works but has sharp, silent edges — hit for real on Website
Navigator (FRICTION 2026-10-13):

- `update_field()` on a flexible-content sub-field key silently updates only ACF's internal
  value cache, never the real `postmeta` row. Every readback (`get_field()`, live GraphQL)
  looks correct. Nothing persisted.
- `update_sub_field()`'s row-position argument is 1-indexed; the stored meta-key suffix
  (`blocks_<N>_*`) is 0-indexed. An off-by-one silently writes into a **different block's**
  fields entirely, with no error.

`wp/mu-plugins/pod-acf-mcp-abilities.php` fixes both, once, behind a typed/validated tool call
(`pod/get-acf-block-field`, `pod/update-acf-block-field`), rather than every session re-deriving
the correct addressing by hand. The write ability verifies every change against a cache-busted
raw DB read before reporting success — never trusts ACF's own value cache.

## First-time setup, per site (NOT automated — these touch live secrets)

`provision.sh` installs the `mcp-adapter` plugin and creates the scoped `mcp_content_editor`
role (content-edit capabilities only — no plugin/theme/file/user-management). It does **not**
create the MCP-client WP user or its Application Password, since that's a live credential that
must never land in a provisioning log.

```bash
# 1. Create a dedicated, scoped user (one-time per site)
wp user create <site>-mcp-agent <site>-mcp-agent@poddigital.co.uk --role=mcp_content_editor --display_name=MCPAgent

# 2. Generate its Application Password, piped STRAIGHT to a secrets file — never echoed
wp user application-password create <site>-mcp-agent claude-mcp --porcelain > ~/.pod-secrets/<site>-mcp-app-password.txt
chmod 600 ~/.pod-secrets/<site>-mcp-app-password.txt

# 3. Register the MCP server at USER scope (not project — avoids the credential ever
#    landing in a committed/gitignored repo file)
PASS=$(cat ~/.pod-secrets/<site>-mcp-app-password.txt)
AUTH=$(printf '<site>-mcp-agent:%s' "$PASS" | base64)
claude mcp add --transport http <site>-wp "https://<wp-backend-host>/wp-json/mcp/mcp-adapter-default-server" \
  --header "Authorization: Basic ${AUTH}" --scope user
unset PASS AUTH
```

A newly-registered MCP server needs a **fresh Claude Code session** to appear as a callable
tool — it won't show up mid-session. Until then (or if you need to verify without restarting),
talk to it directly over HTTP — see "Manual verification" below.

## Using the abilities

```
pod/get-acf-block-field    { post_id, block_index, field_name }        -> { value }
pod/update-acf-block-field { post_id, block_index, field_name, value } -> { success, verified }
```

- `block_index` is **0-based**, matching what you see everywhere else (`wp post meta list`,
  the GraphQL `blocks` array position) — the ability converts to ACF's 1-indexed
  `update_sub_field()` internally. Never pass a 1-indexed value.
- `field_name` is the ACF sub-field name (e.g. `stats_row_items`, `columns`), not the full
  meta key.
- `verified` on a write is read back from the raw DB, not ACF's cache — compare it to what you
  sent before trusting the write happened.
- **`update_sub_field()` does not fire `save_post`**, same as the manual `update_field()` path —
  `pod-revalidate-webhook.php` will NOT fire automatically. Call `/api/revalidate` yourself after
  a write and verify against the CDN response (not its own `{revalidated:true}` claim) — see the
  `revalidation` skill.

## Manual verification (no MCP client, or testing straight after deploy)

```bash
PASS=$(cat ~/.pod-secrets/<site>-mcp-app-password.txt)
SID=$(curl -s -D - -u "<site>-mcp-agent:${PASS}" -X POST "<mcp-url>" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"probe","version":"1.0"}}}' \
  | grep -i mcp-session-id | awk '{print $2}' | tr -d '\r')

curl -s -u "<site>-mcp-agent:${PASS}" -X POST "<mcp-url>" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -H "Mcp-Session-Id: ${SID}" -H "MCP-Protocol-Version: 2025-11-25" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"pod/get-acf-block-field","parameters":{"post_id":123,"block_index":7,"field_name":"columns"}}}}'
unset PASS
```

## Extending the ability surface

Adding a new ability follows the same shape as `pod-acf-mcp-abilities.php` — two things that
are easy to get wrong and silently fail with **zero exception, zero warning**, confirmed by
reading WordPress core's own `wp-includes/abilities-api.php` and
`wp-includes/abilities-api/class-wp-ability*.php` directly:

1. **Ability categories register on a different hook than abilities.**
   `wp_abilities_api_categories_init` for `wp_register_ability_category()`,
   `wp_abilities_api_init` for `wp_register_ability()`. Mixing them up silently no-ops.
2. **`category` requires BOTH `label` and `description`** (category only — not optional like
   it reads). Missing either throws a caught `InvalidArgumentException`, silently discarded.
3. **`meta.public` defaults to `false`.** A perfectly successful registration still won't show
   up in MCP Adapter's `discover-abilities` without `'meta' => array( 'public' => true )`.

None of these produce a PHP error, a fatal, or anything in `wp_get_ability()`'s return beyond
`null` — the only way to catch them is reading core's validation source directly or comparing
against how `core`/`mcp-adapter`/`yoast-seo`'s own abilities register. Budget for that when
adding a new one; don't assume a clean run with no exceptions means it worked.
