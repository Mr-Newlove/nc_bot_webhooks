# nc_bot_webhooks — Maintenance Reference

Complete maintenance reference for the `nc_bot_webhooks` Nextcloud app. Covers every file, API interaction, data flow, design decision, and operational detail.

---

## Table of Contents

- [Project Structure](#project-structure)
- [App Identity & Configuration](#app-identity--configuration)
- [Every File — Purpose & Key Details](#every-file--purpose--key-details)
- [API Interactions](#api-interactions)
- [Data Flow](#data-flow)
- [Configuration Model](#configuration-model)
- [Security Model](#security-model)
- [Known Issues & Limitations](#known-issues--limitations)
- [Operational Procedures](#operational-procedures)
- [Debugging Guide](#debugging-guide)
- [Migration Notes](#migration-notes)
- [Testing](#testing)

---

## Project Structure

```
nc_bot_webhooks/
├── appinfo/
│   ├── routes.php          # HTTP route definitions
│   ├── info.xml            # App metadata (id, version, dependencies, classes)
│   └── cron.php            # Background job registration
├── lib/
│   ├── Controller/
│   │   └── WebhookController.php   # HTTP handlers (webhooks + admin endpoints)
│   ├── Service/
│   │   └── TalkService.php         # Core business logic (1904 lines)
│   ├── Command/
│   │   └── DebugToggle.php         # CLI command for debug endpoint
│   ├── Settings/
│   │   └── Admin.php               # Admin settings form handler (ISettings)
│   ├── NavigationProvider.php      # App navigation (INavigationProvider)
│   └── Cron/
│       └── ImageCleanup.php        # Background job (IJob)
├── js/
│   └── settings.js         # Admin settings UI (351 lines)
├── templates/
│   └── adminSettings.php   # Settings form HTML
├── css/
│   └── adminSettings.css   # Settings UI styles
├── img/
│   └── app.svg             # App icon (used in navigation + admin)
├── composer.json           # Package name + PSR-4 mapping (dev tooling only)
├── README.md               # User-facing documentation
├── INSTALL.md              # Installation guide
├── architecture.md         # Architecture documentation
└── agent.md                # This file
```

**Key paths at runtime:**
- Web URL base: `/apps/nc_bot_webhooks/`
- Admin settings path: `Settings → Admin → nc_bot_webhooks`
- Bot user: `talk-bot` (must exist and be admin)
- Image storage: `/nc_bot_webhooks-images/<room-token>/` under `talk-bot`'s personal files

---

## App Identity & Configuration

### Constants

| Constant | Value | Location |
|---|---|---|
| `APP_ID` | `'nc_bot_webhooks'` | `TalkService.php:24`, `WebhookController.php:33`, `DebugToggle.php:48` |
| `IMAGES_DIR` | `'nc_bot_webhooks-images'` | `TalkService.php:25`, `ImageCleanup.php:12` |
| `DEBUG_KEY` | `'debug_enabled'` | `DebugToggle.php:49` |

### AppConfig keys (stored in `oc_appconfig` table, `appid = 'nc_bot_webhooks'`)

| Key | Type | Purpose |
|---|---|---|
| `bot_password` | encrypted string | Bot user's app password (encrypted via `ICrypto`) |
| `rooms` | JSON object | Room token → display name mapping for configured rooms |
| `retention_days` | string (int) | Image retention period in days |
| `sender_name` | string | Default sender display name |
| `auth_tokens` | JSON object | Room token → auth token array mapping |
| `debug_enabled` | bool | Whether the `/debug` endpoint is active |

**Critical:** After the rename from `ncdiscordhook` to `nc_bot_webhooks`, old config data under the old app ID is inaccessible. Users upgrading must run:
```sql
UPDATE oc_appconfig SET appid = 'nc_bot_webhooks' WHERE appid = 'ncdiscordhook';
```

### Namespace

```
OCA\Ncbotwebhooks\
├── Controller\WebhookController
├── Service\TalkService
├── Command\DebugToggle
├── Settings\Admin
├── NavigationProvider
└── Cron\ImageCleanup
```

---

## Every File — Purpose & Key Details

### lib/Service/TalkService.php (1904 lines)

**The single most important file.** Contains all business logic.

#### Constructor / Dependencies

```php
public function __construct(
    IClientService $clientService,    // HTTP client factory
    IConfig $config,                  // App + system config
    IDBConnection $db,                // Database connection
    IRootFolder $rootFolder,          // Filesystem root
    IRequest $request,                // HTTP request
    IURLGenerator $urlGenerator,      // URL generation
    IUserManager $userManager,        // User lookup
    IUserSession $userSession,        // User session management
    LoggerInterface $logger,          // PSR-3 logger
    TalkManager $talkManager,         // Talk room manager
    ICrypto $crypto,                  // Encryption (bot password)
    AttendeeMapper $attendeeMapper,   // Talk attendee queries
    IShareManager $shareManager,      // File share management
    ParticipantService $participantService, // Talk participant management
    ChatManager $chatManager,         // Talk chat management
    TalkSession $talkSession,         // Talk session persistence
)
```

#### Bot Password Methods

| Method | Lines | Purpose |
|---|---|---|
| `hasBotPassword()` | ~28-34 | Checks if `bot_password` exists in AppConfig |
| `getBotPassword()` | ~36-48 | Retrieves and decrypts bot password via `ICrypto`. Returns `null` if not set. |
| `setBotPassword(string)` | ~50-58 | Encrypts and stores bot password via `ICrypto` → `IAppConfig` |
| `validateBotPassword(string)` | ~60-80 | **Round-trip encryption test**: encrypt → decrypt → compare. Checks: non-empty, no crypto-breaking chars, crypto layer functional. **Does NOT validate against Nextcloud server.** |

**Why round-trip test?** The original code used `TalkManager::getRemoteServer()` which requires a valid remote URL. The round-trip test is faster and more reliable — it verifies the password works with the crypto layer without making a network call.

#### Room Methods

| Method | Lines | Purpose |
|---|---|---|
| `getRooms()` | ~270-274 | Returns JSON-decoded `rooms` from AppConfig — `array<string, string>` mapping room token → display name. No `type`/`type_label`/`object_type` fields; those come from `getAvailableTalkRooms()`. |
| `setRooms(array)` | ~92-98 | Stores room token → display name mapping as JSON in AppConfig |
| `getAvailableTalkRooms()` | ~291-360 | **Queries Talk DB directly** (bypasses TalkManager). Filters: `type IN (1,2,3)`, excludes deleted/note-to-self/sample/file rooms. Returns `array<string, string>` mapping room token → display name (or token if name is empty). Note: does not return `type`, `type_label`, or `object_type` fields — only `token` and `name` are selected. |
| `isBotEnabledForRoom(string)` | ~996-999 | Checks if room token exists in configured rooms |

**Why direct DB queries?** Talk 14+ removed `TalkManager::getRoomForToken`. Talk's OCS API requires user sessions, not app passwords. Direct queries bypass both issues.

**Room type filter:** `type IN (1, 2, 3)`
- Type 1: public channel
- Type 2: group direct message
- Type 3: public direct message
- **Excluded:** type 4 (deleted), type 6 (note-to-self), sample rooms (`object_type = 'sample'`), file share rooms (`object_type = 'file'`), private DM rooms (`name LIKE '["%'`)
- **Known gap:** type 6 (note-to-self) is explicitly excluded above, but future room types are also missed by the hardcoded `IN` list.
- **Future consideration:** If Nextcloud adds new room types, this query silently excludes them. Switching from an inclusive model (`IN (1,2,3)`) to an exclusive model (`NOT IN (4) AND type != 'note_to_self' AND object_type != 'file' ...`) would be more forward-compatible.

**Room name resolution:** In NC33, room display name is in the `name` column. Falls back to `token` if `name` is empty.

#### Base URL Resolution

`getBaseUrl()` resolves the server URL in this priority order:

1. `overwritehost` (+ `overwriteproto`, default `https`) — explicit hostname override
2. `overwritewebroot` — if full URL (starts with `http://`/`https://`) returns as-is; if path → falls through
3. **Non-loopback trusted domain** — iterates `trusted_domains`, skips `127.0.0.1`, `::1`, `localhost`, and private ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, fc00::/7). Returns first valid domain. If **none pass the filter**, falls through.
4. **`trusted_domains[0]` fallback** — first trusted domain regardless of range (may be localhost). Caller may fail if it's unreachable.
5. `overwrite.cli.url` — last resort

**Why this order?** Docker/container deployments often have `trusted_domains[0] = 127.0.0.1` (unreachable from within the container) and `overwrite.cli.url` pointing to `localhost:port`. The non-loopback filter handles this.

#### Auth Token Methods

| Method | Lines | Purpose |
|---|---|---|
| `getAuthTokens()` | ~474-478 | Returns JSON-decoded `auth_tokens` from AppConfig |
| `setAuthTokens(array)` | ~483-485 | Stores auth tokens as JSON in AppConfig |
| `validateAuthToken(roomToken, authToken)` | ~490-496 | Checks if authToken exists in the array for roomToken |
| `generateAuthToken(roomToken)` | ~502-511 | **Server-side**: `bin2hex(random_bytes(24))` → 48-char hex token |
| `revokeAuthToken(roomToken, authToken)` | ~516-529 | Removes token from array, cleans up empty room entries |

**Note:** The settings UI generates tokens client-side (`btoa(Math.random() + Date.now())`) — this is a known security limitation. Use `generateAuthToken()` for server-side generation.

#### Payload Mapping

| Method | Lines | Purpose |
|---|---|---|
| `mapPayload(array)` | ~548-583 | Maps Discord webhook JSON to Talk message text. Extracts: `content`, embed `title` (bold), embed `description`, embed `fields` (name: value). Joins with `\n\n`. |
| `mapApprisePayload(array, roomToken)` | ~608-766 | Maps Apprise JSON to internal format. Handles: `body`, `title`/`subject` as message, `type` → icon (✅ success, ⚠️ warning, ❌ error; none for info/image), `attachments` (remote URL, local file, base64), `type=image` special case. Returns `{message, senderName, displayName, richObjects, typeIcon}`. |
| `getSenderName(array)` | ~588-593 | Returns `username` from Discord payload, or config default |
| `getSenderNameDefault()` | ~598-600 | Returns config `sender_name` default |
| `prependDisplayName(string, string, string)` | ~601-620 | Prepends `{typeIcon}🤖 **{name}**\n\n` to message. `typeIcon` is ✅/⚠️/❌ for success/warning/error types, empty for info/image. Since Talk doesn't support per-message avatars. |

**Apprise attachment formats handled:**
1. **Remote URL**: `attachment['url']` → download → upload → rich object
2. **Local file**: `attachment['path']` starting with `file://` → read file → upload → rich object
3. **Base64**: `attachment['base64']` → decode → upload → rich object
4. **type=image**: special case — uses `attachment` (string URL) or `attachments` (array) as image URLs directly

**Type icon mapping:**
- `success` → ✅
- `warning` → ⚠️
- `error` → ❌
- `info` / `image` / missing → no icon (empty string)

**Base64 attachment format** (from Apprise library JSON method):
```json
{
  "base64": "<base64-encoded-data>",
  "filename": "image.png",
  "mimetype": "image/png"
}
```

**type=image handling** (lines 622-667): When Apprise sends `type: "image"`, the body may be empty. The code uses `title` as the message text if body is empty, and processes all image URLs from `attachment` or `attachments` fields.

#### Image Handling

| Method | Lines | Purpose |
|---|---|---|
| `downloadImage(string)` | ~781-803 | HTTP GET via Nextcloud `IClientService`. Timeout: 15s. `allow_local_address: true` (needed for Docker). Returns `['data' => binary, 'mimeType' => string]` or null. |
| `uploadImage(roomToken, filename, data, mimeType)` | ~809-829 | Writes to `nc_bot_webhooks-images/<roomToken>/<filename>` under `talk-bot`'s personal storage. Uses `basename()` for path traversal protection. Returns relative path or null. |
| `buildRichObject(filePath, mimeType, roomToken)` | ~1082-1170 | Creates a TYPE_ROOM share for the uploaded file so Talk's SystemMessage parser can resolve the rich object inline. Uses PDO directly to bypass DBConnection lazy init. Resolves bot's home storage from `storages` table (avoids hardcoded '1'). Returns rich object data with shareId, fileId, fileCachePath, downloadUrl, publicUrl, shareToken, filename, mimeType. |

**Image flow for Discord:**
1. Extract `embed[].image.url` or `embed[].thumbnail.url`
2. `downloadImage()` — HTTP GET
3. Derive filename from URL path (or use `webhook-image.<ext>`)
4. `uploadImage()` — write to bot user storage
5. `buildRichObject()` — create TYPE_ROOM share in bot user's storage
6. Pass rich object to `postToRoom()` as `richObjects`

**Image flow for Apprise:**
1. Parse `attachments` array (supports URL, local file, base64)
2. For each attachment: download/read → upload → build rich object
3. Collect all rich objects → pass to `postToRoom()`

#### Talk Posting

| Method | Lines | Purpose |
|---|---|---|
| `postToRoom(roomToken, message, senderName, richObjects)` | ~1335-1530 | Posts message to Talk Chat API v1. Uses query params (`?message=...&actorDisplayName=...`) with empty JSON body (`{}`). Basic auth: `talk-bot:botPassword`. Endpoint: `/ocs/v2.php/apps/spreed/api/v1/chat/{roomToken}`. Also sends a file_shared system message for rich objects. |

**Chat API v1 query params:**
```
?message=🤖 **CI Bot**%0A%0ABuild%20%231234%20passed&actorDisplayName=<bot's display name from room participant>
```

**Rich objects:** Sent separately as a `file_shared` system message so Talk's SystemMessage parser can resolve the file inline. The system message body contains the share ID and metadata.

**Critical: `actorDisplayName` must match the bot's display name in the room's participant record.** In Talk 14+, if it doesn't match, the message is silently dropped. Resolved via `getBotDisplayNameForRoom()` which queries AttendeeMapper.

**Critical: `actorDisplayName` must match the bot's display name in the room's participant record.** In Talk 14+, if it doesn't match, the message is silently dropped. Resolved via `getBotDisplayNameForRoom()` which queries AttendeeMapper.

**`getBotDisplayNameForRoom()`** (line 1619):
1. Queries `talk_rooms` table for room ID from token
2. Uses `AttendeeMapper::findByActor(roomId, ACTOR_USERS, 'talk-bot')` to get attendee record
3. Returns attendee's display name, or `'talk-bot'` as fallback

#### Config Save

`saveConfig(array $config)` (lines 1720-1757):
1. Validate bot password (if provided) via round-trip test
2. Set bot password (if provided)
3. Set retention days (if provided)
4. Merge rooms: use provided `rooms` array, then remove disabled rooms from `disabled_rooms` (token → unused)
5. Set rooms
6. Set auth tokens (if provided)
7. Set sender name (if provided)
8. **`ensureBotParticipants()`** — adds talk-bot as participant in all configured rooms

**`ensureBotParticipants()`** (line 1764, `private` — called at end of every `saveConfig()` call):
- Queries configured rooms
- For each room, checks if talk-bot is already a participant
- If not, inserts an attendee record via `AttendeeMapper`
- Ensures bot is in all rooms for Chat API to accept messages

#### Image Cleanup

| Method | Lines | Purpose |
|---|---|---|
| `purgeOldImages()` | ~1042-1059 | Gets retention days, computes cutoff time, gets bot user folder, calls `purgeFolder()` |
| `purgeFolder(Folder, int)` | ~1064-1084 | **Recursively** deletes files/folders by mtime only. Cleans up empty subdirectories. |

**⚠️ SECURITY CONCERN:** `purgeFolder()` deletes by mtime only — no file ownership verification. It operates on the `nc_bot_webhooks-images/` directory under the bot user's personal storage. This is safe because:
1. It only operates within the bot user's personal folder
2. The bot user is isolated from other users
3. The directory is specifically for webhook images

**User requested per-file attributes for additional safety — NOT YET IMPLEMENTED.**

#### Debug Helpers

| Method | Purpose |
|---|---|
| `detectTalkTableFromCatalog(newName, oldName)` | Queries `information_schema.tables` for Talk table. Tries both `talk_rooms`/`spreed_room` with prefix candidates from `spreed.databaseprefix` and `dbtableprefix`. |
| `getTalkTableColumns(tableName)` | Returns column names + types for a table |
| `getTalkTableSample(tableName, limit)` | Returns sample rows from a table |
| `getAllTalkRoomsDebug(limit)` | Returns all rooms with id, token, type, readable_name, label, name, object_type, object_id |
| `getRoomTypeBreakdown()` | Returns count of rooms per type |

---

### lib/Controller/WebhookController.php

**HTTP request handlers.** Extends `Controller`, constructor takes `appName`, `request`, `TalkService`, `IAppConfig`, `LoggerInterface`.

#### Discord Webhook Handler: `receive()`

**Route:** `POST /discord-webhook/{roomToken}/{token}`

**Flow:**
1. Validate auth token via `TalkService::validateAuthToken()`
2. Parse JSON from `php://input`
3. `mapPayload()` — convert to Talk message text
4. If no message content → return 400 `no_content`
5. `getSenderName()` — resolve sender name
6. `prependDisplayName()` — embed sender name in message
7. Iterate embeds → extract image/thumbnail URLs → `downloadImage()` → `uploadImage()` → `buildRichObject()`
8. `postToRoom()` — post to Talk Chat API
9. Return 201 `ok` or 500 `error`

**Response headers:** `X-Webhook-Status: ok|unauthorized|bad_request|no_content|error`

**HTTP status codes:** 401 (unauthorized), 400 (bad_request/no_content), 201 (ok), 500 (error)

#### Apprise Webhook Handler: `receiveApprise()`

**Route:** `POST /apprise-webhook/{roomToken}/{token}`

**Flow:**
1. Validate auth token
2. Detect content type: JSON → `$_POST` → form-encoded
3. Parse payload (try JSON → `$_POST` → `parse_str`)
4. **Unwrap `notifications` array** if present (Apprise API wrapper)
5. **Propagate wrapper-level `subject`/`title`** to notification entry (preserves original data before extraction)
6. `mapApprisePayload()` — convert to internal format
7. Handle attachments (remote URL, local file, base64)
8. `prependDisplayName()` — embed sender name
9. `postToRoom()` — post to Talk
10. Return response with `X-Webhook-Status`

**Key difference from Discord handler:** Apprise sends a different payload structure with `notifications` array wrapper. The code handles:
- Single notification (no wrapper)
- Wrapped notification (extract from `notifications[0]`)
- `type=image` notifications (special image handling)
- Multiple attachment formats (URL, local file, base64)

**Wrapper-level subject/title propagation fix:** When the wrapper has `subject` or `title` but the inner notification doesn't, these values are propagated to the notification entry so the sender name is preserved.

#### Apprise Notify Handler: `receiveAppriseNotify()`

**Route:** `POST /apprise-webhook/{roomToken}/notify/{token}`

Simply delegates to `receiveApprise()`. Apprise's `apprises://` URL scheme inserts `notify` in the path automatically.

#### Admin Endpoints

| Method | Route | Auth | Purpose |
|---|---|---|---|
| `saveConfig()` | POST `/save-config` | Admin session | Bulk config save (rooms, auth tokens, retention, sender name, bot password). Validates bot password, saves all config, ensures bot participants. |
| `saveBotPassword()` | POST `/save-bot-password` | Admin session | Standalone bot password save with validation. |
| `getRooms()` | GET `/rooms` | Admin session | Returns available Talk rooms and configured rooms for JS. |
| `debug()` | GET `/debug` | Admin session (gated by debug_enabled flag) | Exposes DB schema, bot credentials, config (disabled by default). Admin gate re-enabled — see TODO for auto-disable timer. |

**`saveConfig()` flow:**
1. `TalkService::saveConfig()` — validates, saves, ensures participants
2. Return JSON: `{'status': 'ok', 'auth_tokens': <server state>}`
3. If validation fails → throw Exception → catch → return JSON error

**`getRooms()` flow:**
1. `TalkService::getAvailableTalkRooms()` → available rooms
2. `TalkService::getRooms()` → configured rooms
3. Merge: for each available room, check if configured → `{'token', 'name', 'configured'}`
4. Return JSON array

**`debug()` endpoint** (when enabled):
1. Get bot password from AppConfig
2. Get rooms from AppConfig
3. Get auth tokens from AppConfig
4. Get Talk table columns via `detectTalkTableFromCatalog()` → `getTalkTableColumns()`
5. Get room type breakdown
6. Return all as JSON

---

### lib/Command/DebugToggle.php

CLI command for managing the debug endpoint. Uses `IAppConfig` with key `debug_enabled` under app ID `nc_bot_webhooks`.

**Commands:**
- `php occ nc_bot_webhooks:debug:enable` — enable
- `php occ nc_bot_webhooks:debug:disable` — disable
- `php occ nc_bot_webhooks:debug:toggle` — toggle
- `php occ nc_bot_webhooks:debug:status` — show status

**Default:** disabled. **Warning:** enables public access to sensitive data (DB schema, bot credentials, config).

---

### lib/Settings/Admin.php

Admin settings form handler. Implements `ISettings`.

**`getForm()`:**
1. Loads CSS (`adminSettings`) and JS (`settings`)
2. Injects config data into template params:
   - `hasBotPassword` — whether bot password is configured
   - `retentionDays` — current retention value
   - `rooms` — configured rooms (for checkbox state)
   - `authTokens` — current auth tokens
   - `configuredRooms` — configured rooms (for JS state)
   - `serverUrl` — resolved base URL
   - `senderName` — current sender name
   - `l10n` — translated strings
3. Returns `TemplateResponse('nc_bot_webhooks', 'adminSettings', $params)`

**`getPriority(): 10`** — higher number = displayed lower in settings list
**`getSection(): 'additional'`** — places under "Additional" section
**`getIcons(): [imagePath('nc_bot_webhooks', 'app.svg')]`** — app icon

---

### lib/NavigationProvider.php

Implements `INavigationProvider`. Adds "nc_bot_webhooks" entry to admin settings navigation.

**Admin-only:** returns empty array if user is not admin.

**Navigation entry:**
```php
[
    'id' => 'nc_bot_webhooks',
    'app_id' => 'nc_bot_webhooks',
    'type' => 'settings',
    'name' => 'nc_bot_webhooks',
    'href' => linkToRoute('settings.AdminSettings#index'),
    'icon' => imagePath('nc_bot_webhooks', 'app.svg'),
    'order' => 0,
]
```

---

### lib/Cron/ImageCleanup.php

Background job. Implements `IJob`.

**`run($argument)`:**
1. Get `talk-bot` user
2. Get `nc_bot_webhooks-images` folder from bot's personal storage
3. Call `TalkService::purgeOldImages()`
4. Log purge count

**Registration:** via `appinfo/info.xml` `<background-jobs>` and `appinfo/cron.php`.

**⚠️ NOTE:** Only deletes by mtime — no per-file attribute check. User requested per-file attributes for safety (NOT YET IMPLEMENTED).

---

### js/settings.js (351 lines)

Admin settings UI. All logic in `DOMContentLoaded` event handler.

**State variables:**
- `configuredRoomsState` — current room configuration
- `authTokensState` — current auth tokens (client-side)
- `serverAuthTokens` — canonical auth tokens from server (synced after save)

**Data sources:** Read from DOM data attributes on `#nc-config-data`:
- `data-configured-rooms` — JSON of configured rooms
- `data-auth-tokens` — JSON of auth tokens
- `data-server-url` — server base URL
- `data-sender-name` — current sender name
- `data-has-bot-password` — 1 or 0
- `data-retention` — current retention days
- `data-l10n` — JSON of translated strings

**Key functions:**

| Function | Lines | Purpose |
|---|---|---|
| `parseData(el, key)` | 5-11 | Safely parse JSON from data attribute |
| `showStatus(msg, type)` | 31-35 | Show transient status message (success/error) |
| `fetchRooms()` | 59-122 | GET `/rooms`, render checkboxes + token divs |
| `renderTokens(roomToken, container)` | 131-252 | Render auth tokens with webhook URLs + generate/revoke |
| `savePasswordBtn handler` | 255-282 | POST `/save-bot-password` with validation |
| `saveBtn handler` | 285-349 | POST `/save-config` with rooms, tokens, retention, sender name |

**Token generation** (line 245-246):
```javascript
var raw = Math.random().toString(36).substring(2) + Date.now().toString(36);
var token = btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
```
Uses URL-safe base64 encoding of `Math.random() + Date.now()`. **Known security limitation** — server-side `random_bytes(32)` would be more secure.

**URL generation** (lines 137-140):
```javascript
var discordPath = OC.generateUrl('/apps/' + APP_ID + '/discord-webhook/') + roomToken + '/{token}';
var apprisePath = OC.generateUrl('/apps/' + APP_ID + '/apprise-webhook/') + roomToken + '/notify/{token}';
var discordUrl = window.location.protocol + '//' + window.location.host + discordPath;
var appriseUrl = window.location.protocol + '//' + window.location.host + apprisePath;
```
Uses `window.location` for protocol/host, `{token}` placeholder replaced when token is generated.

**Save flow** (bulk config):
1. Collect checked checkboxes → rooms
2. Track disabled rooms (previously checked, now unchecked)
3. Build payload: rooms, disabled_rooms, auth_tokens, retention_days, sender_name
4. Include bot_password only if input is non-empty
5. POST to `/save-config`
6. On success: sync `authTokensState` from server response, re-render token divs

**Auto-fetch:** If configured rooms exist on load, automatically fetches room list.

---

### templates/adminSettings.php

Settings form HTML. Single `.nc-app-frame` wrapper containing:

1. **Bot App Password** — password input + conditional save button (shown only if no password configured)
2. **Default Sender Name** — text input
3. **Image Retention** — number input (1-365)
4. **Room Selection** — Fetch Rooms button + checkbox list + Generate Auth Token + Save Configuration + status

**Config data passed to JS** via `#nc-config-data` data attributes (JSON-encoded).

---

### css/adminSettings.css

Styles for the settings UI:
- `.nc-app-frame` — bordered frame around entire settings
- `.nc-settings-section` — bordered sections for each config group
- `.nc-room-item` — checkbox row layout
- `.nc-room-tokens` — token container with dark background
- `.nc-token-row` — token input + button row
- `.nc-token-url-input` — monospace URL display
- `.nc-token-copy` / `.nc-token-revoke` — action buttons
- `.nc-generate-token` — generate button
- `.nc-status-success` / `.nc-status-error` — status colors
- `.nc-hint` — helper text

---

### appinfo/routes.php

Route definitions. All webhook routes use `{roomToken}` and `{token}` path parameters.

```php
[
    'webhook#receive',           // POST /discord-webhook/{roomToken}/{token}
    'webhook#saveConfig',        // POST /save-config
    'webhook#getRooms',          // GET /rooms
    'webhook#saveBotPassword',   // POST /save-bot-password
    'webhook#debug',             // GET /debug (noCsrf)
    'webhook#receiveApprise',    // POST /apprise-webhook/{roomToken}/{token}
    'webhook#receiveAppriseNotify', // POST /apprise-webhook/{roomToken}/notify/{token}
]
```

---

### appinfo/info.xml

```xml
<id>nc_bot_webhooks</id>
<name>nc_bot_webhooks</name>
<summary>Discord webhook bridge for Nextcloud Talk with image support</summary>
<description>Accepts Discord webhook-style JSON payloads and posts them into Nextcloud Talk rooms, preserving image attachments.</description>
<version>1.2.1</version>
<licence>AGPL-3.0-or-later</licence>
<namespace>Ncbotwebhooks</namespace>
<dependencies>
    <nextcloud min-version="33" max-version="35"/>
    <app>spreed</app>
</dependencies>
```

**Dependencies:**
- Nextcloud 33 only (explicit version range)
- `spreed` app (Talk) must be enabled

**Class registrations:**
- Background job: `OCA\Ncbotwebhooks\Cron\ImageCleanup`
- Admin settings: `OCA\Ncbotwebhooks\Settings\Admin`
- CLI command: `OCA\Ncbotwebhooks\Command\DebugToggle`

---

### composer.json

```json
{
    "name": "nc_bot_webhooks/app",
    "autoload": {
        "psr-4": {
            "OCA\\Ncbotwebhooks\\": "../lib/"
        }
    }
}
```

Note: The `../lib/` path is for local dev tooling only. Nextcloud apps use their own autoloader (registered via the app framework) — this file is not used in production.

---

## API Interactions

### Nextcloud Talk Chat API v1

**Endpoint:** `POST /ocs/v2.php/apps/spreed/api/v1/chat/{roomToken}`

**Authentication:** Basic auth with `talk-bot:bot_password`

**Headers:**
```
OCS-Expect-Formatted: json
OCS-APIRequest: 1
Authorization: Basic <base64('talk-bot:bot_password')>
Content-Type: application/json
```

**Request body:**
```json
{
    "message": "Message text (supports rich text formatting)",
    "actorType": "users",
    "actorId": "talk-bot",
    "actorDisplayName": "Display name from room participant",
    "richObjects": {
        "file-0": {
            "rich_object": {
                "id": "",
                "elements": [{
                    "type": "file",
                    "id": "<share token>",
                    "name": "filename.png",
                    "mimetype": "image/png",
                    "thumbnailReady": true,
                    "fileTarget": "/nc_bot_webhooks-images/roomToken/filename.png",
                    "path": "filename.png"
                }]
            },
            "source": "file"
        }
    },
    "richObjectsEnd": {
        "file-0": true
    }
}
```

**Response:** 200 on success, error on failure. The app checks `statusCode >= 200 && statusCode < 300`.

**Critical constraint:** `actorDisplayName` MUST match the bot's display name in the room's participant record. Mismatch → message silently dropped.

### Apprise API

Apprise sends webhook payloads in several formats depending on the transport:

**JSON format (primary):**
```json
{
    "version": 0,
    "type": "info|success|warning|error|image",
    "title": "Title",
    "subject": "Subject",
    "body": "Message body",
    "notifications": [
        {
            "subject": "Subject",
            "title": "Title",
            "body": "Body",
            "type": "type",
            "attachments": [...]
        }
    ],
    "attachments": [...]
}
```

**Wrapper behavior:** Apprise wraps individual notifications in a `notifications` array. The code handles both wrapped (extract from array) and unwrapped (use directly) formats.

**Wrapper-level subject/title propagation:** When the wrapper has `subject` or `title` but the inner notification doesn't, these are propagated down to preserve sender name context.

**Content types handled:**
1. `application/json` — direct JSON parse
2. `multipart/form-data` — `$_POST` (files in `$_FILES['file01']`)
3. `application/x-www-form-urlencoded` — `parse_str()`

**Apprise URL schemes:**
- `discord://` → uses Discord webhook format
- `apprises://` → uses Apprise format with `notify` in path: `/apprise-webhook/{room}/notify/{token}`

**Type field:** The `type` field maps to an icon displayed before the bot name:
- `success` → ✅
- `warning` → ⚠️
- `error` → ❌
- `info` / `image` / missing → no icon

### Discord Webhook API

Discord webhooks send JSON with these fields:
```json
{
    "content": "Message text",
    "embeds": [
        {
            "title": "Embed title",
            "description": "Embed description",
            "color": 3066993,
            "fields": [{"name": "Field", "value": "Value"}],
            "image": {"url": "https://..."},
            "thumbnail": {"url": "https://..."}
        }
    ],
    "username": "Sender name",
    "avatar_url": "https://..."
}
```

**Mapping to Talk:**
- `content` → message text
- `embeds[].title` → bold line `**Title**`
- `embeds[].description` → message body
- `embeds[].fields` → `name: value` lines
- `embeds[].image.url` / `thumbnail.url` → download → upload → inline image
- `username` → sender display name (prepended as `🤖 **username**`)
- `avatar_url` → ignored (Talk doesn't support per-message avatars)

---

## Data Flow

### Webhook Ingestion (Discord)

```
POST /discord-webhook/{roomToken}/{token}
    │
    ├─► WebhookController::receive()
    │     │
    │     ├─ 1. validateAuthToken(roomToken, token)
    │     ├─ 2. json_decode(file_get_contents('php://input'))
    │     ├─ 3. mapPayload(data) → message text
    │     ├─ 4. getSenderName(data) → sender name
    │     ├─ 5. prependDisplayName(senderName, message)
    │     ├─ 6. For each embed image/thumbnail:
    │     │     ├─ downloadImage(url)
    │     │     ├─ uploadImage(roomToken, filename, data, mimeType)
    │     │     └─ buildRichObject(filePath, mimeType, roomToken)
    │     ├─ 7. postToRoom(roomToken, message, senderName, richObjects)
    │     │     ├─ getBotPassword()
    │     │     ├─ isBotEnabledForRoom(roomToken)
    │     │     ├─ getBaseUrl()
    │     │     ├─ getBotDisplayNameForRoom(roomToken)
    │     │     └─ HTTP POST with Basic auth
    │     └─► DataResponse with X-Webhook-Status
    │
    └─► HTTP 201/400/401/500 + X-Webhook-Status header
```

### Webhook Ingestion (Apprise)

```
POST /apprise-webhook/{roomToken}/{token}
    │
    ├─► WebhookController::receiveApprise()
    │     │
    │     ├─ 1. validateAuthToken(roomToken, token)
    │     ├─ 2. Detect content type (JSON → $_POST → parse_str)
    │     ├─ 3. Parse payload
    │     ├─ 4. If 'notifications' in data: extract first entry, propagate wrapper subject/title
    │     ├─ 5. mapApprisePayload(data, roomToken)
    │     │     ├─ Resolve displayName from title/subject
    │     │     ├─ Build message from body (or title if body empty)
    │     │     ├─ Add type prefix ([Info], [Warning], etc.)
    │     │     └─ Process attachments (URL → download, file:// → read, base64 → decode)
    │     ├─ 6. prependDisplayName(displayName, message)
    │     ├─ 7. postToRoom(roomToken, message, senderName, richObjects)
    │     └─► DataResponse with X-Webhook-Status
    │
    └─► HTTP 201/400/401/500 + X-Webhook-Status header
```

### Configuration Save

```
POST /save-config (admin)
    │
    ├─► WebhookController::saveConfig()
    │     │
    │     ├─► TalkService::saveConfig(config)
    │     │     ├─ validateBotPassword() if provided
    │     │     ├─ setBotPassword() if provided
    │     │     ├─ setRetentionDays() if provided
    │     │     ├─ setRooms() with disabled rooms removed
    │     │     ├─ setAuthTokens() if provided
    │     │     ├─ setSenderName() if provided
    │     │     └─ ensureBotParticipants()
    │     └─► JSON {status: 'ok', auth_tokens: ...}
    │
    └─► JSON response
```

### Room Listing

```
GET /rooms (admin)
    │
    ├─► WebhookController::getRooms()
    │     │
    │     ├─► TalkService::getAvailableTalkRooms()
    │     │     ├─ detectTalkTableFromCatalog('talk_rooms', 'spreed_room')
    │     │     ├─ Query: SELECT token, COALESCE(NULLIF(name,''),token) FROM talk_rooms
    │     │     │   WHERE type IN (1,2,3) AND object_type NOT IN ('sample','note_to_self','file')
    │     │     └─ Return token → display name
    │     │
    │     ├─► TalkService::getRooms() (configured rooms)
    │     │
    │     └─► Merge: for each available room, add 'configured' flag
    │
    └─► JSON array of {token, name, configured}
```

---

## Configuration Model

### AppConfig (oc_appconfig table)

All config is stored in the `oc_appconfig` table with `appid = 'nc_bot_webhooks'`.

| appid | config_key | config_value |
|---|---|---|
| nc_bot_webhooks | bot_password | `<encrypted>` |
| nc_bot_webhooks | rooms | `{"roomToken1": "Room Name", ...}` |
| nc_bot_webhooks | retention_days | `"90"` |
| nc_bot_webhooks | sender_name | `"Webhook Bot"` |
| nc_bot_webhooks | auth_tokens | `{"roomToken1": ["token1", "token2"], ...}` |
| nc_bot_webhooks | debug_enabled | `0` or `1` |

### Bot User Storage

```
talk-bot/
└── nc_bot_webhooks-images/
    ├── <room-token-1>/
    │   ├── screenshot.png
    │   └── diagram.jpg
    └── <room-token-2>/
        └── photo.png
```

Each image has a public link share (`SHARE_TYPE_LINK`) created via `ShareManager`. The share token is embedded in the Talk message's `rich_object` so Talk resolves and displays the inline image.

### Base URL Resolution Priority

1. `overwritehost` — explicit hostname override
2. `overwritewebroot` — if full URL
3. Non-loopback `trusted_domains` — skips 127.0.0.1, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, ::1, fc00::/7
4. `overwrite.cli.url` — last resort

### Talk Table Detection Priority

1. `spreed.databaseprefix` (app config) + `talk_rooms`
2. `dbtableprefix` (system config) + `talk_rooms`
3. `spreed.databaseprefix` + `spreed_room`
4. `dbtableprefix` + `spreed_room`
5. `talk_rooms` (no prefix)
6. `spreed_room` (no prefix)

Each candidate is verified via `information_schema.tables` + test query.

---

## Security Model

### Authentication Layers

| Layer | Mechanism |
|---|---|
| Webhook access | Auth token in URL path (`/discord-webhook/{room}/{token}`) |
| Admin settings | Nextcloud session auth + `#[AdminRequired]` attribute |
| Bot → Talk | Basic auth (`talk-bot:bot_password`) |

### Authorization

- Admin-only endpoints: `/save-config`, `/save-bot-password`, `/rooms` require admin session
- Webhook endpoints: `#[PublicPage]` + `#[NoCSRFRequired]` — auth token is sole access control
- Bot must be participant in target room (enforced by `isBotEnabledForRoom()` + Talk's own check)

### Data Isolation

- Bot password: encrypted at rest via `ICrypto`
- Images: stored in `talk-bot`'s personal storage under `nc_bot_webhooks-images/<room-token>/`
- Image cleanup: only operates within `nc_bot_webhooks-images/` under bot user's folder
- Per-file attributes: **NOT YET IMPLEMENTED** (user requested for extra safety)

### Image Download Safety

- Uses Nextcloud `IClientService` with built-in local address blocking
- `allow_local_address: true` — needed for Docker (container-internal addresses)
- Filenames sanitized via `basename()` — prevents path traversal

### Debug Endpoint

- `/apps/nc_bot_webhooks/debug` exposes DB schema, bot credentials, config
- **Disabled by default**
- Enabled only via CLI (`php occ nc_bot_webhooks:debug:enable`)
- Stored in `appconfig` (not hardcoded)
- **Should be disabled after troubleshooting**

---

## Known Issues & Limitations

### Critical

1. **Apprise 500 error after rename:** Config data still under old app ID `ncdiscordhook`. Fix:
   ```sql
   UPDATE oc_appconfig SET appid = 'nc_bot_webhooks' WHERE appid = 'ncdiscordhook';
   ```

2. **Version pinning to NC33:** `<nextcloud min-version="33" max-version="33"/>` — app only works on NC33. If upgrading Nextcloud, remove max-version or update it.

### Security

3. **Client-side token generation:** Settings UI uses `btoa(Math.random() + Date.now())`. Server-side `random_bytes(32)` would be more secure. Use `TalkService::generateAuthToken()` for server-side generation.

4. **No rate limiting:** Webhook endpoints have no rate limiting. Handle at web server / reverse proxy level.

5. **Image cleanup by mtime only:** No per-file ownership verification. Safe because scoped to bot user's folder, but user requested per-file attributes for extra safety (NOT YET IMPLEMENTED).

### Functional

3. **Link preview suppression removed:** Nextcloud Talk generates link previews server-side. URL suppression via angle brackets (`<url>`) does not work. This feature was removed.

### Functional (continued)

6. **avatar_url ignored:** NCTalk doesn't support per-message avatars. `username` is embedded as sender name instead.

7. **actorDisplayName mismatch:** If bot's display name changes in the room, messages will be silently dropped. `getBotDisplayNameForRoom()` resolves this on each message.

8. **Table name detection failure:** If `information_schema` is unavailable or Talk table uses an unusual name, `detectTalkTableFromCatalog()` returns null and room listing fails.

9. **Bot not in room:** If talk-bot is removed from a configured room, `postToRoom()` will fail. `ensureBotParticipants()` runs on every save to prevent this.

---

## Operational Procedures

### Enabling the App

```bash
# 1. Create bot user
php occ user:add --password-from-env --display-name="Webhook Bot" talk-bot

# 2. Make bot admin (via web UI: Settings → Users → talk-bot → Admin)

# 3. Generate app password (via web UI: Settings → talk-bot → Devices & sessions → Add device)

# 4. Copy app to Nextcloud apps directory
cp -r nc_bot_webhooks /path/to/nextcloud/apps/

# 5. Enable app
php occ app:enable nc_bot_webhooks

# 6. Configure via web UI (Settings → Admin → nc_bot_webhooks)
```

### Configuring Rooms

1. Go to Settings → Admin → nc_bot_webhooks
2. Enter bot app password
3. Click "Fetch Rooms" to list available Talk rooms
4. Check rooms to enable webhooks for
5. Generate auth tokens for each room
6. Click "Save Configuration"

### Managing Auth Tokens

**Via UI:**
- Click "+ Generate Auth Token" for a room
- Click "Revoke All" to remove all tokens for a room
- Click "Copy" to copy webhook URLs

**Via CLI / direct DB:**
- Tokens stored as JSON in `oc_appconfig` under `auth_tokens`
- Format: `{"roomToken": ["token1", "token2"], ...}`

### Debugging Webhook Issues

1. Check `X-Webhook-Status` header in response
2. Enable debug endpoint: `php occ nc_bot_webhooks:debug:enable`
3. Visit `/apps/nc_bot_webhooks/debug` to inspect config
4. Check Nextcloud log: `data/nextcloud.log` or Settings → Admin → Logging
5. Disable debug: `php occ nc_bot_webhooks:debug:disable`

### Migrating from ncdiscordhook

```sql
UPDATE oc_appconfig SET appid = 'nc_bot_webhooks' WHERE appid = 'ncdiscordhook';
```

### Image Cleanup

**Manual trigger:**
```bash
# The cron job runs automatically. To trigger manually:
php occ background:cron
```

**Adjust retention:** Settings → Admin → nc_bot_webhooks → Image Retention (1-365 days)

**Check images directory:**
```bash
ls /path/to/nextcloud/data/talk-bot/nc_bot_webhooks-images/
```

---

## Debugging Guide

### Common Error Patterns

| Symptom | Cause | Fix |
|---|---|---|
| Apprise 500 error | Config under old app ID | Run migration SQL above |
| "talk-bot user not found" | Bot user doesn't exist | Re-create bot user |
| "Bot password not configured" | No bot password in settings | Enter bot password |
| Messages not appearing | Bot not in room / actorDisplayName mismatch | Check admin settings, re-save |
| Image upload fails | Bot storage quota exceeded | Check bot user storage |
| "No Talk rooms found" | Talk not enabled / table detection failed | Verify Talk app, check debug endpoint |
| Room listing empty | Bot not admin / Talk version incompatible | Grant admin, check NC version |

### Debug Endpoints

**CLI:**
```bash
php occ nc_bot_webhooks:debug:enable    # Enable
php occ nc_bot_webhooks:debug:disable   # Disable
php occ nc_bot_webhooks:debug:status    # Check
php occ nc_bot_webhooks:debug:toggle    # Toggle
```

**Web (when enabled):** `GET /apps/nc_bot_webhooks/debug` returns JSON with:
- Bot password (plaintext)
- Configured rooms
- Auth tokens
- Talk table columns
- Room type breakdown

### Talk Service Debug Helpers

These public methods on `TalkService` are available for programmatic debugging:

| Method | Returns |
|---|---|
| `detectTalkTableFromCatalog('talk_rooms', 'spreed_room')` | Table name or null |
| `getTalkTableColumns('table_name')` | Array of {name, type, nullable} |
| `getTalkTableSample('table_name', 10)` | Array of sample rows |
| `getAllTalkRoomsDebug(100)` | Array of {id, token, type, readable_name, label, name, object_type, object_id} |
| `getRoomTypeBreakdown()` | {type: count} array |

### Log Tags

All log entries use:
- Logger tag: `'nc_bot_webhooks:'`
- App tag: `'app' => 'nc_bot_webhooks'`

Example log entries:
```
nc_bot_webhooks: webhook processed successfully
nc_bot_webhooks: failed to post webhook message to Talk
nc_bot_webhooks: message posted to room ABC123
nc_bot_webhooks: bot password not configured
nc_bot_webhooks: bot not enabled for room
nc_bot_webhooks: base URL not configured
nc_bot_webhooks: getAvailableTalkRooms
nc_bot_webhooks: found 5 rooms
nc_bot_webhooks: purgeOldImages
nc_bot_webhooks: purged 3 old image files
```

---

## Migration Notes

### From ncdiscordhook to nc_bot_webhooks (v1.2.1)

**Breaking change:** New app ID = new installation. Existing config data is under old app ID.

**Migration steps:**
1. Copy new app files to Nextcloud apps directory
2. Enable app: `php occ app:enable nc_bot_webhooks`
3. Run config migration SQL
4. Re-enter bot password in settings (encrypted values don't migrate)
5. Re-configure rooms and auth tokens (or migrate via SQL)

**Config migration SQL:**
```sql
UPDATE oc_appconfig SET appid = 'nc_bot_webhooks' WHERE appid = 'ncdiscordhook';
```

**What migrates:**
- `rooms` — room configuration
- `auth_tokens` — auth tokens
- `retention_days` — retention period
- `sender_name` — sender name

**What does NOT migrate:**
- `bot_password` — must be re-entered (encrypted values are tied to the instance, not the app ID)

### Nextcloud Version Compatibility

Currently pinned to Nextcloud 33–35 (`min-version="33" max-version="35"`). If upgrading Nextcloud beyond 35:

1. Update `max-version` in `info.xml`
2. Test Talk API compatibility (Chat API v1 may change)
3. Test `information_schema` queries on new NC version
4. Test AttendeeMapper API compatibility

### Talk API Version Notes

- Uses **Chat API v1** only (`/ocs/v2.php/apps/spreed/api/v1/chat/{roomToken}`)
- Talk 14+ removed `TalkManager::getRoomForToken` — app uses direct DB queries
- Talk 14+ requires `actorDisplayName` to match participant record exactly
- Talk 19 / NC33 only supports v1 of the Chat API (older versions deprecated)

---

## Testing

### Manual Testing Checklist

1. **Discord webhook** — POST valid payload → message appears in Talk room
2. **Discord webhook with images** — embed with image URL → image appears inline
3. **Discord webhook with embeds** — embed with title/description/fields → formatted message
4. **Discord webhook invalid token** → 401 unauthorized
5. **Discord webhook invalid JSON** → 400 bad_request
6. **Discord webhook empty content** → 200 no_content
7. **Apprise webhook** — POST valid notification → message appears in Talk room
8. **Apprise webhook with attachments** — URL attachments → images appear inline
9. **Apprise webhook type=image** — image notification → inline image
10. **Apprise webhook base64 attachment** — base64 data → inline image
11. **Apprise webhook form-encoded** — form data → message appears
12. **Admin settings** — save config → rooms configured, bot participants added
13. **Token generation** — generate token → appears in room token list
14. **Token revoke** — revoke all → tokens cleared
15. **Image cleanup** — wait for cron → old images purged
16. **Debug endpoint** — enable → inspect config → disable

### Curl Test Commands

**Discord webhook:**
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"content":"Test message","username":"CI Bot"}' \
  https://your-server/apps/nc_bot_webhooks/discord-webhook/<room-token>/<auth-token>
```

**Apprise webhook:**
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","body":"Test message","type":"info"}' \
  https://your-server/apps/nc_bot_webhooks/apprise-webhook/<room-token>/<auth-token>
```

**Check debug endpoint (when enabled):**
```bash
curl -u admin:password https://your-server/apps/nc_bot_webhooks/debug | jq .
```

---

## Quick Reference

### File Sizes (approximate)

| File | Lines | Size |
|---|---|---|
| `TalkService.php` | 1904 | ~35 KB |
| `WebhookController.php` | ~400 | ~15 KB |
| `settings.js` | 351 | ~12 KB |
| `Admin.php` | 74 | ~3 KB |
| `ImageCleanup.php` | 54 | ~1.5 KB |
| `DebugToggle.php` | 102 | ~3 KB |
| `NavigationProvider.php` | 38 | ~1 KB |
| `adminSettings.php` | 54 | ~2 KB |
| `adminSettings.css` | 106 | ~2 KB |
| `routes.php` | 43 | ~1 KB |
| `info.xml` | 33 | ~1 KB |

### Key Dependencies

| Dependency | Purpose |
|---|---|
| `IAppConfig` | App-level key-value config storage |
| `IClientService` | HTTP client (with local address blocking) |
| `IShareManager` | File share management (public link shares) |
| `IRootFolder` | Filesystem root (user folder access) |
| `IUserManager` | User lookup (`get('talk-bot')`) |
| `IDBConnection` | Database queries |
| `IAttendeeMapper` | Talk attendee queries |
| `ICrypto` | Password encryption/decryption |
| `IShareManager` | Public link share creation |
| `IL10N` | Internationalization |
| `LoggerInterface` | PSR-3 logging |

### Nextcloud APIs Used

| API | Version | Endpoint / Interface |
|---|---|---|
| Talk Chat API | v1 | `/ocs/v2.php/apps/spreed/api/v1/chat/{roomToken}` |
| Talk AttendeeMapper | — | `findByActor(roomId, actorType, actorId)` |
| Talk Room catalog | — | `information_schema.tables` + table name detection |
| Nextcloud Share API | — | `newShare()`, `createShare()`, `getShareById()` |
| Nextcloud File API | — | `getUserFolder()`, `getFolder()`, `newFile()` |
| Nextcloud AppConfig | — | `getAppValue()`, `setAppValue()`, `getValueBool()` |
| Nextcloud Crypto | — | `encrypt()`, `decrypt()` via `ICrypto` |
| Nextcloud OCS API | — | OCS-Expect-Formatted / OCS-APIRequest headers |
