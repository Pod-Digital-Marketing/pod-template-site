---
name: wpe-ssh-gateway
description: WP Engine's git-push SSH gateway (the key-based wp-cli access) is a forced-command dispatcher, not a real shell — any multi-word argument breaks it, and it has no SFTP/SCP. Read before running any wp-cli command with a space in an argument, or before uploading a file to a WP Engine site.
---

# wpe-ssh-gateway — what the WP Engine SSH access actually is

WP Engine gives each install a git-push SSH gateway: `<install>@<install>.ssh.wpengine.net`, keyed by an SSH key added in the WP Engine portal. It looks like a normal SSH host. It is not a real shell.

**Confirmed by direct testing:** it's a forced-command dispatcher that naively splits the remote command string on whitespace with **no quote-awareness**. `wp eval "echo 1+1;"` fails with "Too many positional arguments: 1+1" — and that's not an eval-specific quirk, it breaks on any argument with a space, including read-only commands like `wp option get 'x y'`. SFTP and SCP subsystem requests are rejected outright on this same host/key.

**What works fine over this gateway:** single-token wp-cli commands — `wp plugin list`, `wp option get some_key`, `wp post list --post_type=page --format=table`, `wp config path`, `wp eval-file /absolute/no-space/path.php`. That covers most read/list/status work. It only breaks when a value needs real text with spaces in it — `wp eval` with actual code, or any field write containing English.

## The fix — WP Engine's separate SFTP feature

Unrelated to the SSH key. WP Engine User Portal → the install → **SFTP** tab → "Add SFTP user" gives a different hostname (`<install>.sftp.wpengine.com`), port `2222`, its own username/password.

1. Upload a file: `sftp -P 2222 <sftp-user>@<install>.sftp.wpengine.com` → `put local remote`. Script it with `sshpass -f <password-file>` — **never `-p <password>`** on the command line (leaks into process listings/shell history).
2. Run it: back over the git-push gateway, `wp eval-file <absolute-path>` — one argument, no spaces, works fine. Get the absolute site root via `wp config path` (don't assume a relative cwd — it isn't the site root).
3. Delete the uploaded file immediately after, over the same SFTP session. Don't leave arbitrary PHP sitting in a hardened production `wp-content/`.

The `wp-content-edit` skill's `wp/cli/wpe-run-php.sh` script automates this whole sequence — use it instead of hand-rolling steps 1–3 again.

## Don't

- Don't re-test quoting variants when a multi-word command fails — the parser doesn't honor quotes at all, no amount of escaping fixes it.
- Don't try SCP or SFTP over the git-push SSH key/host — it's rejected at the protocol level, not a permissions issue.
- Don't assume the gateway's cwd is the site root for relative paths — resolve via `wp config path` first.
