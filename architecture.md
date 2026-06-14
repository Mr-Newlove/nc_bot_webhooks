# Architecture

Overview of how NCbotwebhooks functions, its components, data flow, design decisions, and security model.

## Table of Contents

- [High-Level Overview](#high-level-overview)
- [Component Map](#component-map)
- [Data Flow](#data-flow)
- [Design Decisions](#design-decisions)
- [Security Model](#security-model)
- [Storage Model](#storage-model)
- [Error Handling](#error-handling)
- [Extensibility](#extensibility)

---

## High-Level Overview

NCbotwebhooks is a Nextcloud app that acts as a webhook bridge. External services (CI/CD pipelines, monitoring tools, Apprise notification gateways) send webhook payloads to the app, which validates the request, maps the payload to Nextcloud Talk's Chat API format, and posts the message as the `talk-bot` user into the target Talk room.

```
External Service ──► /discord-webhook/{room}/{token} ──┐
                          /apprise-webhook/{room}/{token} ──► WebhookController
                                                        ──► TalkService
                                                                        ──► Nextcloud Talk (Chat API v1)
```

The app supports **two webhook formats**:
1. **Discord webhook-compatible** — embeds, fields, images, username/avatar
2. **Apprise notification** — subject/title/body/attachments wrapper format

Each Talk room gets its own webhook URL with a unique auth token. Multiple tokens per room are supported for key rotation or multi-service sharing.

---

## Component Map

### Controllers

| Class | Responsibility |
|---|---|
| `WebhookController` | HTTP request handlers — receives webhooks, validates auth tokens, parses payloads, calls `TalkService` |
| `AdminSettings` | Admin settings form handler — injects config data into the template |

### Service

| Class | Responsibility |
|---|---|
| `TalkService` | Core business logic — bot password management, room listing, auth token management, payload mapping, image download/upload, Talk Chat API posting, config persistence, image cleanup |

### Background Jobs

| Class | Responsibility |
|---|---|
| `ImageCleanup` | Cron job that runs `TalkService::purgeOldImages()` to remove images older than the retention period |

### CLI Commands

| Class | Responsibility |
|---|---|
| `DebugToggle` | CLI command to enable/disable the debug endpoint (`nc_bot_webhooks:debug:toggle`) |

### Settings / Navigation

| Class | Responsibility |
|---|---|
| `Admin` | ISettings implementation — loads template, injects config, declares CSS/JS assets |
| `NavigationProvider` | INavigationProvider — adds "NCbotwebhooks" entry to admin settings navigation |

### Frontend

| File | Responsibility |
|---|---|
| `js/settings.js` | Admin settings UI — fetch rooms, render checkboxes, manage auth tokens, save config |
| `templates/adminSettings.php` | Settings form HTML — frame layout with sections for password, sender name, retention, room selection |
| `css/adminSettings.css` | Styling — frame borders, token rows, status messages |

---

## Data Flow

### Webhook Ingestion (Discord)

```
POST /discord-webhook/{roomToken}/{token}
    │
    ├─► WebhookController::receive()
    │     │
    │     ├─ 1. Validate auth token (TalkService::validateAuthToken)
    │     ├─ 2. Parse JSON from php://input
    │     ├─ 3. Map to Talk format (TalkService::mapPayload)
    │     ├─ 4. Download images from embeds (TalkService::downloadImage)
    │     ├─ 5. Upload images to bot user storage (TalkService::uploadImage)
    │     ├─ 6. Build rich objects (TalkService::buildRichObject)
    │     ├─ 7. Prepend sender name (TalkService::prependDisplayName)
    │     └─ 8. Post to Talk room (TalkService::postToRoom)
    │
    └─► DataResponse with X-Webhook-Status header
```

### Webhook Ingestion (Apprise)

```
POST /apprise-webhook/{roomToken}/{token}
    │
    ├─► WebhookController::receiveApprise()
    │     │
    │     ├─ 1. Validate auth token
    │     ├─ 2. Detect content type (JSON / multipart/form-data / form-encoded)
    │     ├─ 3. Parse payload (try JSON → $_POST → parse_str)
    │     ├─ 4. Unwrap notifications array if present
    │     ├─ 5. Propagate wrapper-level subject/title to notification entry
    │     ├─ 6. Map to Talk format (TalkService::mapApprisePayload)
    │     ├─ 7. Download attachments (TalkService::downloadImage)
    │     ├─ 8. Upload images (TalkService::uploadImage)
    │     ├─ 9. Prepend sender name (TalkService::prependDisplayName)
    │     └─ 10. Post to Talk room (TalkService::postToRoom)
    │
    └─► DataResponse with X-Webhook-Status header
```

### Configuration Save

```
POST /save-config (admin only)
    │
    ├─► WebhookController::saveConfig()
    │     │
    │     ├─ 1. Validate bot password (TalkService::validateBotPassword)
    │     ├─ 2. Save bot password (TalkService::setBotPassword)
    │     ├─ 3. Save retention days (TalkService::setRetentionDays)
    │     ├─ 4. Save configured rooms (TalkService::setRooms)
    │     ├─ 5. Save auth tokens (TalkService::setAuthTokens)
    │     ├─ 6. Save sender name (TalkService::setSenderName)
    │     └─ 7. Ensure bot is participant in all rooms (TalkService::ensureBotParticipants)
    │
    └─► JSON response with status + auth_tokens
```

### Bot Password Save (standalone)

```
POST /save-bot-password (admin only)
    │
    ├─► WebhookController::saveBotPassword()
    │     │
    │     ├─ 1. Validate bot password (TalkService::validateBotPassword)
    │     └─ 2. Save bot password (TalkService::setBotPassword)
    │
    └─► JSON response with status
```

### Room Listing

```
GET /rooms (admin only)
    │
    ├─► WebhookController::getRooms()
    │     │
    │     ├─ 1. Query Talk DB directly (bypasses OCS API which rejects app passwords)
    │     ├─ 2. Detect correct Talk table name (TalkService::detectTalkTableFromCatalog)
    │     ├─ 3. Filter: public channels (type 1), group (type 2), public (type 3)
    │     ├─ 4. Exclude: deleted rooms, note-to-self, sample rooms, file shares
    │     └─ 5. Merge with configured rooms for checkbox state
    │
    └─► JSON array of [token, name, configured] objects
```

---

## Design Decisions

### Direct DB queries instead of TalkManager

The app queries the Talk database directly (`detectTalkTableFromCatalog`) rather than using `TalkManager::getRoomForToken` because:

1. **Talk 14+ removed `getRoomForToken`** — the method was deprecated and removed in newer Talk versions
2. **App passwords don't work with Talk's OCS API** — the OCS endpoints require user sessions, not app passwords
3. **Table name prefix varies** — different Nextcloud installations use different DB table prefixes; the catalog-based detection handles this dynamically

### Base URL resolution strategy

`TalkService::getBaseUrl()` resolves the server URL in this priority order:

1. `overwritehost` (hostname override) — highest priority, explicit override
2. `overwritewebroot` — if it's a full URL; otherwise treated as a path and falls through
3. **Non-loopback trusted domain** — iterates `trusted_domains`, skips `127.0.0.1` and private ranges (Docker compatibility)
4. `overwrite.cli.url` — last resort

This prioritization handles Docker/container deployments where `trusted_domains[0]` is often `127.0.0.1` (unreachable from within the container) and `overwrite.cli.url` points to a localhost:port.

### Bot display name resolution

In Talk 14+, the `actorDisplayName` field in the Chat API **must match** the bot's display name as registered in the room's participant record, or the message is silently dropped. The app resolves this by:

1. Querying the Talk rooms table for the room ID from the room token
2. Using `AttendeeMapper::findByActor()` to find the bot's attendee record
3. Using the attendee's display name as `actorDisplayName`
4. Falling back to `'talk-bot'` if the attendee record doesn't exist

### Sender name embedding

Since NCTalk doesn't support per-message avatars, the app prepends a type-icon (for Apprise) + emoji-prefixed sender name line to the message text:

```
🤖 **CI Bot**

Build #1234 passed
```

For Apprise with a `type` field:

```
⚠️ 🤖 **CI Bot**

Build #1234 failed
```

Icons: ✅ (success), ⚠️ (warning), ❌ (error). Info and image types have no icon.

This preserves the visual identity of the sending service within Talk's message rendering.

### Auth token storage

Auth tokens are stored as a JSON object in the Nextcloud `appconfig` table:

```json
{
  "roomToken1": ["tokenA", "tokenB"],
  "roomToken2": ["tokenC"]
}
```

Each room can have multiple tokens (array), supporting key rotation and multi-service sharing without reconfiguring all consumers.

### Client-side token generation

Auth tokens in the settings UI are generated client-side using `btoa(Math.random() + Date.now())`. This is noted as a security limitation in the docs — server-side generation (e.g., `random_bytes(32)`) would be more secure. The trade-off is acceptable for the current threat model: webhook URLs are not publicly discoverable, and the auth token is the sole access control.

### `ensureBotParticipants()`

When rooms are configured, the app automatically adds the `talk-bot` user as a participant in each room via direct `AttendeeMapper` insertion. This is necessary because:

1. The Chat API requires the `actorId` to be a participant in the room
2. The bot user is created separately and isn't automatically in any rooms
3. Running this on every save ensures consistency when rooms are added/removed

### `validateBotPassword()` — round-trip encryption test

Bot password validation uses a round-trip encrypt→decrypt test rather than attempting an API call. This checks:

1. The password is non-empty
2. The password doesn't contain characters that break the crypto layer
3. The crypto layer is functional

This is faster and more reliable than trying to authenticate with the Talk API, which would fail for many other reasons (network, room state, etc.).

---

## Security Model

### Authentication

| Layer | Mechanism |
|---|---|
| Webhook access | Auth token in URL path (e.g., `/discord-webhook/{room}/{token}`) |
| Admin settings | Nextcloud session auth + admin check |
| Bot → Talk | Basic auth with `talk-bot` app password |

### Authorization

- Admin-only endpoints (`/save-config`, `/save-bot-password`, `/rooms`) require an admin session
- Webhook endpoints are public (`#[PublicPage]`, `#[NoCSRFRequired]`) — auth token is the sole access control
- The bot must be a participant in the target room (enforced by `isBotEnabledForRoom()` + Talk's own participant check)

### Data isolation

- Bot password is encrypted at rest using Nextcloud's `ICrypto` layer
- Images are stored in the bot user's personal storage (`nc_bot_webhooks-images/`)
- Image cleanup only operates within the bot user's storage directory
- Each room's images are isolated in a subdirectory (`nc_bot_webhooks-images/<room-token>/`)

### Image download safety

- Uses Nextcloud's `IClientService` which includes built-in local address blocking (blocks `127.0.0.0/8`, `10.0.0.0/8`, `192.168.0.0/16`, `172.16.0.0/12`, `::1`, `fc00::/7`)
- `allow_local_address: true` is set to allow internal Nextcloud URLs (needed for Docker deployments where the server URL resolves to a container-internal address)
- Filenames are sanitized via `basename()` to prevent path traversal

### Debug endpoint

The `/debug` endpoint exposes sensitive data (DB schema, bot credentials, config). It is:

- **Disabled by default**
- Enabled only via CLI (`php occ nc_bot_webhooks:debug:enable`)
- Stored in `appconfig` (not hardcoded)
- Documented with explicit warnings

---

## Storage Model

### AppConfig table (`oc_appconfig`)

| Key | Type | Description |
|---|---|---|
| `bot_password` | encrypted string | Bot user's app password |
| `rooms` | JSON object | Room token → display name mapping |
| `retention_days` | string (int) | Image retention period |
| `sender_name` | string | Default sender display name |
| `auth_tokens` | JSON object | Room token → auth token array mapping |

### Bot user storage (`talk-bot` personal files)

```
nc_bot_webhooks-images/
├── <room-token-1>/
│   ├── screenshot.png
│   └── diagram.jpg
└── <room-token-2>/
    └── photo.png
```

### Public link shares

Each uploaded image gets a public link share (`SHARE_TYPE_LINK`) created via `ShareManager`. The share token is embedded in the Talk message's `rich_object` rich object so Talk can resolve and display the inline image.

---

## Error Handling

### Webhook endpoints

All webhook endpoints return structured `DataResponse` with appropriate HTTP status codes and `X-Webhook-Status` headers:

| Condition | HTTP Status | X-Webhook-Status |
|---|---|---|
| Invalid auth token | 401 | `unauthorized` |
| Invalid JSON / payload | 400 | `bad_request` |
| No message content | 200 | `no_content` |
| Success | 200 | `ok` |
| Server error | 500 | `error` |

### Talk API failures

When `postToRoom()` fails (non-2xx response or network error), the error is logged with room token and response details, and `false` is returned up the call chain. The webhook endpoint returns 500 `error`.

### Config save failures

`saveConfig()` throws an `Exception` on bot password validation failure. The controller catches this and returns a JSON error response. Other failures (e.g., DB errors) propagate as 500.

---

## Extensibility

### Adding a new webhook format

To support a new webhook format:

1. Add a new route in `appinfo/routes.php`
2. Add a new method to `WebhookController` with `#[PublicPage]` and `#[NoCSRFRequired]`
3. Implement payload parsing and validation in the controller
4. Add a mapping method to `TalkService` (e.g., `mapNewFormat()`)
5. The image upload, rich object building, and Talk posting paths are already implemented

### Adding a new storage backend

Images are currently stored in the bot user's personal files. To support external storage:

1. Replace `uploadImage()` to write to an external service (S3, etc.)
2. Update `buildRichObject()` to generate URLs for the external service
3. Update `purgeOldImages()` / `purgeFolder()` to clean up external storage
4. Add per-file attributes or a separate index table for ownership tracking

### Adding rate limiting

Rate limiting is not built in (by design). To add it:

1. Implement a middleware or decorator on `receive()` / `receiveApprise()`
2. Use Nextcloud's rate limiting infrastructure (`IRateLimitManager`)
3. Apply per-IP or per-room-token limits
