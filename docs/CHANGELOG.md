# Changelog

## 2026-10-03

### Docs & security pass — prepared for public commit (no version bump)

- **Docs:** `README.md`, `INSTALL.md`, `architecture.md`, and `agent.md` no longer
  describe the removed v1.2.2 retention-cron (`ImageCleanup` / `purgeOldImages` /
  the "Image Retention (days)" setting); they now describe the current quota-based
  `trimToQuota()` and `occ nc_bot_webhooks:trim [--dry-run] [--force]`.
- **Security:** swept every file that would be committed for personal/infra
  leakage (no hardcoded secrets, tokens, instance IDs, usernames, hostnames, or
  IPs); fixed two leaks — a real Home Assistant camera entity name in the README
  example (→ generic `camera.front_door`) and a "bird-camera" mention in the
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
