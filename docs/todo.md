# nc_bot_webhooks — Todo

> Single source of truth for todos. The app-root `TODO.md` was merged into this
> file on 2026-10-03 (and removed) so there is one canonical todo. Format:
> **Open items** + **Recently finished** are the resume anchor; the sections
> below are durable reference (design notes / known limitations / security).

## Open items

(None.)

## Recently finished

- v1.3.0 — Room-type future-proofing + debug-endpoint auto-disable TTL.
  Status: deployed to the live server and confirmed working by the user
  (2026-10-04) — room filter lists all expected rooms; the debug TTL
  auto-disable + disable path verified against the live /debug endpoint.
  Committed in `2cdcc9a` (2026-10-04); pending push.
  - Room filter switched from the allow-list `type IN (1, 2, 3)` to a deny-list
    `type NOT IN (4, 5, 6)` at all four sites (TalkService primary + fallback
    queries; WebhookController debug-SQL mirror + room list), so new real room
    types are auto-included while the system types (4=changelog, 5=former
    one-to-one/orphaned, 6=note-to-self) stay excluded. Verified against the
    official Talk docs on 2026-10-04 (1=one-to-one, 2=group, 3=public,
    4=changelog, 5=former one-to-one, 6=note-to-self).
  - Fixed the wrong room-type labels in the room list (`1=public/2=private/
    3=password` → the verified `1=one-to-one, 2=group, 3=public, 4=changelog,
    5=former one-to-one, 6=note to self`).
  - Debug endpoint now auto-disables after a TTL: `occ nc_bot_webhooks:
    debug:toggle --enable [--ttl <seconds>]` (default 7200; `--ttl 0` = no
    auto-disable). The enable time + TTL are stored in appconfig; the `/debug`
    handler lazily checks them on each request and flips the flag off on
    expiry (no cron / background job). Pure `TalkService::isDebugExpired(int,
    int, int)` + `test/test_debug_ttl.php` (auto-discovered by
    `test/all_tests.py`); `--status` now shows the countdown.
  - `info.xml` → 1.3.0.
  - Synced `README.md`, `INSTALL.md`, `agent.md`, `architecture.md`, and
    `CHANGELOG.md` with the real `debug:toggle` command forms + `--ttl`, the
    `type NOT IN (4, 5, 6)` deny-list + corrected room-type labels, and the
    version bump; scrubbed two personal device names from the security-pass
    changelog/todo entries (now generic wording).
- Release prep (docs + security): security-scanned every file that would be
  committed (no hardcoded secrets/tokens, instance IDs, personal usernames,
  paths, hostnames, or IPs) and fixed the two personal-identifier leaks found
  (a real Home Assistant camera entity name in the README example → generic
  `camera.front_door`; a personal device name in the v1.2.4 changelog → "a burst
  of near-simultaneous image uploads"). Applied targeted fixes so `README.md`,
  `INSTALL.md`, `architecture.md`, and `agent.md` no longer describe the removed
  v1.2.2 retention-cron and now describe the current quota-based `trimToQuota()`
  + `occ nc_bot_webhooks:trim [--dry-run] [--force]`; fixed the README's broken
  `./Future to-do.md` link. Added a minimal `.gitignore`. Docs are now
  setup-agnostic and current. Status: committed in `932582a` + pushed to
  GitHub and Gitea (2026-10-04).
- v1.2.4 single-flight quota trim + `--force`: `TalkService::trimToQuota()` now
  takes an exclusive `OCP\Lock\ILockingProvider` lock on a synthetic key for
  non-dry-run runs (released in `finally`), so ~9 concurrent webhook trims
  collapse to one instead of racing the delete loop and hitting `LockedException`
  (which left an older-image residual). New `trim_lock_ts` appconfig +
  `TRIM_LOCK_STALE_SECONDS` (5 min) force-clear a lock a killed worker left
  behind, so a crashed trim self-recovers rather than waiting the DB lock TTL
  (1 h). Concurrent losers report reason `busy`. `occ nc_bot_webhooks:trim --force`
  bypasses the 95% trigger and trims to the 85% target (clears the residual);
  the command handles `busy` and shows the forced mode. The trigger/target/bytes
  decision was extracted into a pure `TalkService::planTrim()` static and is
  unit-tested in `test/test_trim_plan.php` (force-bypass + never-trim-below-target).
  `info.xml` → 1.2.4.
  Deployed to the live server; `occ nc_bot_webhooks:trim --force` confirmed
  working by the user (2026-10-04).
- v1.2.3 manual quota trim: `occ nc_bot_webhooks:trim [--dry-run]` — new
  `lib/Command/TrimQuota.php`; `TalkService::trimToQuota()` is now public and
  returns a run summary; `purgeOldestToSize()` returns a `['bytes_freed',
  'count', 'files']` summary and takes a `$dryRun` flag; the command prints a
  plain-text report and lists each file pending deletion (path/size/date,
  oldest first, capped at 50); registered in `info.xml` `<commands>`.
- Merged the app-root `TODO.md` into this file (single canonical todo); the
  old file was removed. Its Future Ideas moved to Open items; its reference
  sections are preserved verbatim below.
- v1.2.2 quota-based image trimming: `TalkService::trimToQuota()` runs after
  every image upload (95% trigger → 85% target, 10 GB no-quota cap); new pure
  `lib/Service/OldestFirstPurgePlanner.php` holds the oldest-first selection.
  Removed the daily cron (`lib/Cron/ImageCleanup.php`, `appinfo/cron.php`,
  `<background-jobs>`), the "Image Retention" setting (admin UI + JS +
  `saveConfig` + debug `$knownKeys`), and `getRetentionDays` /
  `setRetentionDays` / `purgeOldImages` / `purgeFolder`; hardened with
  `catch (\Throwable)` + logging.
- Added `test/test_purge_planner.php` + `test/all_tests.py` (PHP planner test
  needs `php` on PATH to run; skipped otherwise).

---

## Inbound Messages (apprise-request) — possible future add

**Status:** Deferred (2026-10-04). No current use; kept as a design sketch for a
possible future add, not an active open item. No maintenance cost while
deferred. Revisit only if a real need appears.

### Concept

Add a new endpoint that polls Nextcloud Talk for new messages, returning them in an Apprise-compatible JSON format so external services can read from the chat and trigger actions.

```
POST /apps/nc_bot_webhooks/apprise-request/<room-token>/notify/<auth-token>
```

### Request

```json
{
  "last-seen": 42
}
```

### Response

```json
{
  "43": {
    "version": 0,
    "subject": "Alice",
    "title": "Message from Alice",
    "body": "Hello from the other side!",
    "type": "info"
  },
  "44": {
    "version": 0,
    "subject": "Bob",
    "title": "Message from Bob",
    "body": "Welcome back!",
    "type": "info"
  }
}
```

Each key is the Talk message ID. The keys are always in ascending order by ID. The client finds the largest key for the next request: `Math.max(...Object.keys(response))`.

> **Note:** JSON objects are technically unordered per spec, but all major implementations preserve insertion order. If strict ordering is required by a client, it can use `Object.keys(response).sort((a, b) => Number(a))` to guarantee sort.

### Design Decision: Message ID vs Timestamp

Use **message ID** (integer), not a timestamp.

Nextcloud Talk's Chat API (`GET ocs/v2.php/apps/spreed/api/v1/chat/{roomToken}/messages`) supports a `since` query parameter that takes a message ID. Message IDs are auto-incrementing integers, making them:

- **Monotonically ordered** — no clock skew issues between Talk server and webhook caller
- **Precise** — exact message boundary, no ambiguity about which messages fall between two timestamps
- **Efficient** — Talk's DB query can use the index directly; timestamps would require a range scan
- **Consistent** — matches the existing Talk API pattern (the same `since` parameter is used for the standard polling endpoint)

### Implementation sketch

1. **New route** in `appinfo/routes.php`:
   - `POST /apprise-request/{roomToken}/notify/{token}`

2. **New WebhookController method** (`receiveAppriseRequest`):
   - Validate auth token
   - Parse `last-seen` from request body (default: 0 for first poll)
   - Call `TalkService::getNewMessages($roomToken, $lastSeen)`

3. **New TalkService method** (`getNewMessages`):
   - Query Talk Chat API with `since={lastSeen}` parameter
   - Parse response and map each message to an Apprise-compatible object
   - Return array of Apprise-format notification objects

4. **Admin settings UI**:
   - Add a "Triggers" section for configuring inbound triggers
   - Per-trigger: room selection, trigger token, filter rules (optional)

5. **Config storage** (`appconfig`):
   - New key `triggers`: JSON object mapping trigger tokens to configuration

### Open questions

- Should the endpoint accept an Apprise-format request (like existing webhooks) with a `last-seen` field? Or a custom format?
- Should triggers support message filtering (e.g., only messages matching a regex, only @mentions)?
- Should triggers support one-way dispatch (call an external webhook when a message matches)?
- Rate limiting: how often should external services poll? Should we add a `X-Poll-After` header?

---

## Known Limitations

### Auth token generation is client-side
- **File:** `js/settings.js:245`
- **Issue:** Auth tokens use `btoa(Math.random() + Date.now())` — not cryptographically secure
- **Fix:** Switch to server-side `random_bytes(32)` generation
- **Priority:** Low (webhook URLs are not publicly discoverable; the threat model doesn't require it)

### Voice message MIME type fix is disabled
- **File:** `lib/Service/TalkService.php:1556`
- **Issue:** `fixMimeTypeOfVoiceMessage()` is commented out because it creates non-voice shares for voice messages
- **Fix:** Re-enable when upgrading to a Talk version that properly filters voice message shares

### No rate limiting on webhook endpoints
- **Issue:** Webhook endpoints have no rate limiting — vulnerable to abuse if URLs are exposed
- **Fix:** Add rate limiting via Nextcloud's `IRateLimitManager` or place behind reverse proxy

### Bot avatar not supported
- **Issue:** NCTalk doesn't support per-message avatars; the `avatar_url` field in Discord payloads and the `icon_url` field in Slack payloads are ignored
- **Workaround:** Sender name is prepended to message text as a bold line

### No per-message avatar support in Talk
- **Impact:** Visual identity of the sending service is conveyed via type icons + sender name, not avatars

### Bot user requires admin privileges
- **Issue:** The `talk-bot` user must be granted admin access to list all Talk rooms
- **Impact:** Security concern — bot has more privileges than strictly necessary

### Storage quota impact
- **Issue:** Images count toward the bot user's storage quota
- **Impact:** Large deployments with many image webhooks could exhaust the bot's quota
- **Mitigation (v1.2.2):** `TalkService::trimToQuota()` now trims oldest images back to 85% of quota after each upload (10 GB cap when the account has no quota), so images can no longer exhaust the bot's quota.

### No CSRF protection on webhook endpoints
- **By design:** Webhook endpoints are marked `#[PublicPage]` + `#[NoCSRFRequired]` — auth token is the sole access control

---

## Potential Future Features

### Message filtering / pattern matching
- Allow triggers to filter incoming messages by regex, keywords, or @mention
- Enables "only notify on specific messages" rather than polling everything

### Outbound webhook dispatch (trigger → external service)
- When a message matches a trigger, POST to an external webhook URL
- Would turn nc_bot_webhooks into a bidirectional bridge (not just a polling endpoint)

### Slash command support
- Recognize and execute slash commands (e.g., `/status`, `/ping`) sent to the bot
- Would require the inbound message system first

### Thread / conversation support
- Post messages within a specific thread (requires Talk thread API integration)

### Rich object support expansion
- Currently supports images only. Could extend to:
  - File shares (links to files in Nextcloud)
  - Deck cards
  - Calendar events
  - Custom rich objects

### Multi-format inbound support
- Discord, Apprise, and Slack webhook formats are all supported (v1.4.0)
- Could add support for:
  - Generic JSON (no wrapper)
  - Form-encoded payloads (already partially supported for Apprise)

### Read markers
- Track which messages have been "seen" by external services
- Could return `last-seen` in the response for convenience

### Typing indicators
- Forward typing indicators from external services to Talk
- Would require a new endpoint: `POST /typing/{roomToken}`

### System messages
- Currently the voice message MIME type fix is disabled to avoid creating non-voice system messages
- Could enable system messages in the future (e.g., "Bot started", "Room joined")

---

## Security Notes

- Auth tokens generated from the settings UI use client-side generation; for higher security, regenerate them via the server API
- The webhook endpoint has no rate limiting — consider placing it behind a reverse proxy rate limiter if exposing to untrusted sources
- Debug endpoint is disabled by default and should be re-enabled immediately after troubleshooting
