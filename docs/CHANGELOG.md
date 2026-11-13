# Changelog

## 2026-11-13

### v1.4.0 - Slack incoming-webhook endpoint

- **New:** a third webhook format: `POST /slack-webhook/{roomToken}/{token}`
  (`WebhookController::receiveSlack()` + `TalkService::mapSlackPayload()`, with
  the core mapping as pure static `TalkService::mapSlackContent()`). Accepts
  Slack incoming-webhook payloads: `text` (mrkdwn), Block Kit `blocks`
  (`section` incl. fields and accessory images, `header`, `divider`,
  `image`; unknown types skipped), and legacy `attachments` (pretext, title,
  text, fields, image_url / thumb_url). Block/attachment image URLs are
  downloaded, uploaded to bot storage, and shared inline via the same
  `processImageUrls()` path Apprise uses.
- **Minimal mrkdwn conversion** (`TalkService::convertSlackMrkdwn()`): HTML
  entities unescaped (reverses TrueNAS's `html.escape(quote=False)`),
  `<url|label>` links unwrapped to `label (url)`, `<@U...>` / `<@W...>`
  mentions dropped. `*bold*` / `_italic_` pass through unchanged (Talk markdown
  is limited; partial conversion would mangle more than it helps).
- **Display semantics follow Slack's own:** top-level `text` is the
  notification fallback; it is dropped when `blocks` / `attachments` render
  visible text and otherwise becomes the message body (image-only blocks keep
  the text).
- **Identity fields are accepted but ignored:** `username`, `icon_emoji`,
  `icon_url`, `thread_ts` have no effect. Slack incoming webhooks cannot
  override the username, and Talk has no per-message avatar; the sender name
  is always the configured default.
- **Primary use case:** TrueNAS SCALE's Slack alert service. Verified against
  the truenas/middleware source (`alert/service/slack.py`): it posts exactly
  `{"text": html.escape(html2text(...), quote=False)}`, so entity unescaping
  is part of the conversion and TrueNAS alerts arrive readable.
- **New:** unit test `test/test_map_slack.php` (21 checks, auto-discovered by
  `test/all_tests.py`); all four suites pass (44 checks).
- **Settings UI:** each room's token rows now include a read-only Slack
  webhook URL with a copy button, alongside the Discord and Apprise rows
  (`js/settings.js`).
- Version bumped 1.3.0 to 1.4.0 (`appinfo/info.xml`).
- **Verified live (2026-11-13):** a curl against the deployed endpoint
  returns `201 Created` + `X-Webhook-Status: ok` (entities unescaped in the
  posted body), and an end-to-end test through the TrueNAS SCALE Slack alert
  service posts the alert into the Talk room.

## 2026-10-04

### v1.3.0 — Room-type future-proofing + debug-endpoint auto-disable TTL

- **Breaking (behavioral):** The room-type filter flipped from an allow-list
  (`type IN (1,2,3)`) to a DENY-list (`type NOT IN (4,5,6)`), so new real room
  types are auto-included while system types stay excluded. Talk room types:
  1=one-to-one, 2=group, 3=public (kept); 4=changelog, 5=former one-to-one
  (orphaned rooms of deleted users), 6=note-to-self (excluded).
- **Fix:** The room-list API's per-room `type_label` values were corrected to the
  verified labels (they were previously wrong: 1=public/2=private/3=password).
- **New:** the debug endpoint now auto-disables after a TTL. It is enabled via
  `occ nc_bot_webhooks:debug:toggle --enable [--ttl <seconds>]` (default 7200 =
  2 h; `--ttl 0` = no auto-disable); `--status` shows a countdown. The single
  command is `debug:toggle` — there are no separate `debug:enable` /
  `debug:disable` / `debug:status` commands (docs previously referenced the
  non-existent ones; that doc-accuracy fix is part of this release).
- The TTL check is LAZY — evaluated on each `/debug` request, with no
  cron/background job. The enable time and TTL are stored in appconfig keys
  `debug_enabled_at` (int, unix ts) and `debug_ttl` (int, seconds); the handler
  flips `debug_enabled` off on expiry. It is fail-closed: a missing
  `debug_enabled_at` is treated as expired.
- **New:** pure helper `TalkService::isDebugExpired(int,int,int)` + new unit
  test `test/test_debug_ttl.php` (auto-discovered by `test/all_tests.py`).

## 2026-10-03

### Docs & security pass — prepared for public commit (no version bump)

- **Docs:** `README.md`, `INSTALL.md`, `architecture.md`, and `agent.md` no longer
  describe the removed v1.2.2 retention-cron (`ImageCleanup` / `purgeOldImages` /
  the "Image Retention (days)" setting); they now describe the current quota-based
  `trimToQuota()` and `occ nc_bot_webhooks:trim [--dry-run] [--force]`.
- **Security:** swept every file that would be committed for personal/infra
  leakage (no hardcoded secrets, tokens, instance IDs, usernames, hostnames, or
  IPs); fixed two leaks — a real Home Assistant camera entity name in the README
  example (→ generic `camera.front_door`) and a personal device name in the
  v1.2.4 entry (→ "a burst of near-simultaneous image uploads").
- **New:** minimal `.gitignore` (OS/editor artifacts, `*.log`, dev caches, `.env`).

### v1.2.4 — Single-flight quota trim + `occ nc_bot_webhooks:trim --force`

- **Fix:** Concurrent image uploads each triggered their own quota trim at the
  same instant (a burst of near-simultaneous image uploads produced ~9 overlapping trims), all planning
  the *same* oldest files and racing the delete loop. The losers hit the winner's
  transactional file lock (`LockedException`) and were skipped, leaving a residual
  of older images that would never re-trigger the 95% gate. `trimToQuota()` is now
  single-flight: a non-dry-run trim takes an exclusive lock on a stable synthetic
  key via `OCP\Lock\ILockingProvider` and releases it in a `finally`. A concurrent
  caller that can't take the lock reports reason `busy` instead of racing.
- **Fix:** A lock left behind by a *killed* worker is no longer a wait until the
  NC lock TTL (DB backend default is 1 h). The acquire time is recorded in appconfig
  (`trim_lock_ts`); a failed acquire whose timestamp is older than
  `TRIM_LOCK_STALE_SECONDS` (5 min) force-clears the stale lock and retries once,
  so a crashed trim self-recovers. A normal run releases the lock immediately in
  the `finally`, so nothing lingers.
- **New:** `occ nc_bot_webhooks:trim --force` bypasses the 95% trigger and trims
  straight to the 85% target, to clear a residual left by a partial
  (lock-blocked) trim. It never trims below the target.
- `trimToQuota()` now takes a `$force` flag and reports it (`'force'`) in the run
  summary; dry runs never take the lock, so they are never blocked. A new `busy`
  reason is handled by the command.
- The trigger/target/bytes decision was extracted into a pure, I/O-free
  `TalkService::planTrim(int $quota, int $used, bool $force)` static, unit-tested
  in `test/test_trim_plan.php` (force-bypass + the "never trim below the target"
  safety), aggregated by `test/all_tests.py`.

### v1.2.3 — Manual quota trim via `occ nc_bot_webhooks:trim`

- **New:** `occ nc_bot_webhooks:trim` runs the same quota-based trim on demand
  (`TalkService::trimToQuota()` is now public and returns a run summary), so the
  prune can be triggered and observed without posting an image.
- **New:** `--dry-run` reports the file count and total bytes that *would* be
  freed without deleting anything.
- **New:** the report lists every file that would be (or was) deleted — path,
  size, and last-modified date, oldest first — capped at 50 lines so a huge
  directory can't flood the terminal.
- `purgeOldestToSize()` now returns a `['bytes_freed', 'count', 'files']`
  summary and accepts a `$dryRun` flag; the auto-trigger (end of
  `uploadImage()`) is unchanged and ignores the return value.

### v1.2.2 — Quota-based image cleanup replaces the retention cron

- **Breaking (behavioral):** Removed the daily `ImageCleanup` background job.
  It was registered only during app upgrades (`AppManager::upgradeApp()`), so a
  file-deployed custom app never registered it and old images were never
  purged.
- **New:** `TalkService::trimToQuota()` runs after every image upload. It reads
  the bot account's current storage usage — the home folder's size, a single
  filecache row (O(1), no directory scan) — so the common path is cheap. If
  usage is over 95% of the quota it deletes the oldest files in
  `nc_bot_webhooks-images/` until usage is back to 85%. Accounts with no quota
  (unlimited) are capped at a 10 GB default (`NO_QUOTA_DEFAULT_BYTES`). Only the
  images directory is ever touched.
- New pure, dependency-free `lib/Service/OldestFirstPurgePlanner.php` holds the
  oldest-first selection logic, unit-tested in `test/test_purge_planner.php`
  (aggregated by `test/all_tests.py`).
- Removed the now-dead "Image Retention (days)" admin setting (UI, JS,
  `saveConfig`, debug `$knownKeys`) and the `getRetentionDays` /
  `setRetentionDays` / `purgeOldImages` / `purgeFolder` methods.
- Hardened error handling with `catch (\Throwable)` + logging so a trimming
  failure is visible and can never silently no-op (the original bug) or break a
  successful post.
