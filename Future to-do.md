# Future To-Do

Planned features, known limitations, and architectural notes for nc_bot_webhooks.

---

## Inbound Messages (apprise-request)

**Status:** Planning phase

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

## Known Limitations & Fixes

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
- **Issue:** NCTalk doesn't support per-message avatars; the `avatar_url` field in Discord payloads is ignored
- **Workaround:** Sender name is prepended to message text as a bold line

### No per-message avatar support in Talk
- **Impact:** Visual identity of the sending service is conveyed via type icons + sender name, not avatars

### Bot user requires admin privileges
- **Issue:** The `talk-bot` user must be granted admin access to list all Talk rooms
- **Impact:** Security concern — bot has more privileges than strictly necessary

### Storage quota impact
- **Issue:** Images count toward the bot user's storage quota
- **Impact:** Large deployments with many image webhooks could exhaust the bot's quota

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
- Currently only Apprise-format payloads are accepted as inbound
- Could add support for:
  - Discord webhook format (same as outbound, for symmetry)
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
