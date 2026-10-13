---
name: commit
description: Build, lint, typecheck, and verify LOCALLY before anything is staged, committed, pushed, or PR'd. Use when the user says "commit", "push", "ship this", "open a PR", or "/commit". Blocking pre-flight — never push red code and let CI/a human catch it first.
---

# commit — the standard ship sequence for this repo

Runs the full local pre-flight before anything touches git: lint → typecheck → build → (wp/ php-lint if relevant) → stage → commit → push → open PR. **Never merges** — that's always a separate, explicit human action (agency policy: agents don't push to main or merge PRs).

Adapted from the Website Avatar project's `commit` skill, which runs tests/build BEFORE push and catches breakage locally instead of discovering it later via CI or (worse) manual post-deploy testing. Encoded here after a session (FRICTION 2026-10-13) where several PHP/mu-plugin PRs were pushed with zero local validation — one later turned out to have a failed Vercel deployment that got merged anyway, caught only by chance.

**Safety bias:** never commits to `main`, never merges, never bypasses a failing pre-flight check without explicit user override.

---

## Step 0 — Pre-flight (BLOCKING)

Run these in order. **Stop at the first failure** and report it — don't proceed to staging/commit/push.

```bash
pnpm lint
pnpm typecheck
pnpm build
```

If `wp/**/*.php` has any changes (`git diff --name-only | grep '^wp/.*\.php$'`), also syntax-check them — this repo's CI has no PHP validation of any kind otherwise, and `wp/` changes run directly on production WordPress with no staging environment:

```bash
for f in $(git diff --name-only --diff-filter=ACM | grep '^wp/.*\.php$'); do php -l "$f" || exit 1; done
```

If a check fails: report the exact error, do not stage/commit/push. Ask the user to fix it or explicitly waive ("commit anyway, that failure is unrelated/pre-existing").

**Why `pnpm build`, not just `pnpm typecheck`:** typecheck proves the TYPES line up; build proves the app actually compiles end-to-end (route conflicts, bad imports, broken static generation). A PR has gone out typecheck-green and build-broken before (FRICTION 2026-10-13) — don't repeat it.

## Step 1 — Branch context

```bash
git branch --show-current
```

| Current branch | Action |
|---|---|
| `main` | **Refuse.** Create a branch first: `git checkout -b <type>/<short-description>`. Never commit directly to `main` — see [[by-the-book-discipline]]. |
| any other branch | Proceed. |

## Step 2 — Check what changed

```bash
git status --short
git diff --stat
```

Identify affected surfaces so the commit message and PR description are accurate:
- **Frontend (`src/`)** — needs a Vercel deploy (automatic on merge) to take effect.
- **`wp/mu-plugins/`, `wp/acf-fields/`** — needs a **manual** copy to the live WP Engine install after merge; this repo's WP is not git-deployed (see `wpe-ssh-gateway` skill). Flag this explicitly in the PR description and to the user — merging alone does NOT ship a `wp/` change.
- **`.github/workflows/`** — agents cannot edit this directly (agency policy). If a change is needed here, edit `docs/ci/ci.yml.proposed` instead and tell the user it needs a manual paste.
- **`docs/`, `.claude/skills/`** — no deploy needed.

## Step 3 — Stage

Stage specific files by name — never `git add -A`/`git add .` (risk of picking up stray/secret files). Never stage: `.next/`, `node_modules/`, anything matching `.gitignore`, or any file under `~/.pod-secrets/`.

```bash
git add <files>
```

If unsure which files belong in this change, ask the user.

## Step 4 — Commit message

Conventional commit style, matching this repo's existing history:

```bash
git commit -m "$(cat <<'EOF'
<type>(<scope>): <subject>

<body — the WHY, not a restatement of the diff>

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

`<type>`: `feat`, `fix`, `chore`, `docs`. Keep the Co-Authored-By trailer — this repo's convention (unlike some other Pod repos that omit it; check the target repo's own recent `git log` if unsure).

## Step 5 — Push

```bash
git push -u origin <branch>
```

## Step 6 — Open the PR (not merge)

```bash
gh pr create --title "<subject>" --body "$(cat <<'EOF'
## Summary
- <what changed and why>

## Verification
- [x] pnpm lint / typecheck / build — 0 errors
- [ ] <anything that still needs manual/live verification, e.g. "needs wp/mu-plugins copied to live WP Engine install after merge">

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

Report the PR URL. **Do not merge it** — wait for explicit instruction ("merge"). If the harness's own permission layer blocks the merge call even after explicit instruction, stop and tell the user rather than retrying blindly (it may require a one-time Bash permission-rule change on their end).

## Step 7 — Post-merge reminders (only once the user says it's merged, or you merge on explicit instruction)

- Frontend changes: Vercel deploys automatically. No further action.
- `wp/` changes: **remind the user (or do it, if asked) to copy the changed file(s) to the live WP Engine `mu-plugins`/`acf-fields` directory** via the `wp-content-edit`/`wpe-ssh-gateway` pattern — merging the repo PR alone does not update the live site.
- CI workflow changes (`docs/ci/ci.yml.proposed`): remind the user it still needs a manual paste into `.github/workflows/ci.yml`.

## Don't

- Don't skip Step 0 because "it's a small change" — the failed-Vercel-deploy-merged-anyway incident this skill exists to prevent was a one-line fix.
- Don't `git add -A` — name files explicitly.
- Don't open a PR with a pre-flight failure still unresolved.
- Don't merge, ever, without an explicit instruction naming that PR/merge specifically — a prior "go ahead" on a different action doesn't carry over.
- Don't assume a merged frontend PR means a paired `wp/` change is also live — they deploy through completely different, disconnected paths on this stack.
