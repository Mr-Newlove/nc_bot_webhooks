# nc_bot_webhooks

Webhook bridge that forwards Discord webhook-style and Apprise payloads into Nextcloud Talk rooms, with image attachment support and per-room authentication tokens.

Accepts Discord webhook-compatible JSON payloads (embeds, fields, images) and Apprise notification payloads, maps them to Nextcloud Talk Chat API v1 format, and posts them as the `talk-bot` user.

## Table of Contents

- [Installation](#installation)
  - [Initial installation using git](#initial-installation-using-git)
  - [Updating using git](#updating-using-git)
  - [Local update](#local-update)
- [Configuration](#configuration)
- [Webhook URLs](#webhook-urls)
- [Payload Formats](#payload-formats)
  - [Discord Webhook Format](#discord-webhook-format)
  - [Apprise Format](#apprise-format)
- [Payload Mapping](#payload-mapping)
- [Image Management](#image-management)
- [Security](#security)
- [Debug Endpoint](#debug-endpoint)
- [Logging](#logging)
- [Debugging](#debugging)
- [Integrations](#integrations)
  - [Home Assistant](#home-assistant)
- [Troubleshooting](#troubleshooting)

---

## Installation

### Initial installation using git

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/Mr-Newlove/nc_bot_webhooks.git
php occ app:enable nc_bot_webhooks
```

### 2. Create the bot user

Create a user named `talk-bot` with the display name "Webhook Bot". This can be done via the `occ` CLI or the admin user manager GUI.

```bash
php occ user:add --password-from-env --display-name="Webhook Bot" talk-bot
```

### 3. Make the bot user an admin

The bot must have admin privileges to list all Talk rooms. Grant admin access:

1. Go to **Settings → Users**
2. Find **talk-bot** and click it
3. Check **Admin** under "Settings"
4. Save

### 4. Generate an app password for the bot

1. Log in to Nextcloud as **admin** (or set a password as admin, then log in as talk-bot)
2. Go to **Settings → talk-bot → Devices & sessions**
3. Click **Add device** and give it a name (e.g., "nc_bot_webhooks")
4. Copy the generated device password — you'll need it in the next step

### 5. Configure the app

Go to **Settings → Admin → nc_bot_webhooks**:

1. **Bot App Password** — paste the app password from step 4
2. **Default Sender Name** — set the name that appears as the message sender (default: "Webhook Bot")
3. **Image Retention** — set how many days to keep uploaded images (default: 90)
4. **Room Selection** — click **Fetch Rooms** to list available Talk rooms
5. Check the rooms you want to accept webhooks for
6. For each room, click **+ Generate Auth Token** to create a webhook URL
7. Click **Save Configuration**

---

### Updating using git

> **Note:** On a standard TrueNAS Scale Nextcloud Docker install, the app is located at `/var/www/html/custom_apps/nc_bot_webhooks/`. Adjust the paths below to match your deployment.

```bash
cd /var/www/html/custom_apps/nc_bot_webhooks
git pull
cd /var/www/html
php occ app:disable nc_bot_webhooks
php occ app:enable nc_bot_webhooks
php occ config:app:delete nc_bot_webhooks routes 2>/dev/null || true
php occ maintenance:repair
```

---

### Local update

> **Note:** The paths below are specific to a TrueNAS Scale Nextcloud Docker install. Adjust them to match your deployment.

This workflow is for when you keep your local development copy synced to your server via the Nextcloud desktop client. Edit files locally, then deploy with the script below.

```bash
cd /var/www/html
php occ app:disable nc_bot_webhooks

rm -rf /var/www/html/custom_apps/nc_bot_webhooks/*
mkdir -p /var/www/html/custom_apps/nc_bot_webhooks

# Update from your synced Nextcloud files directory — adjust this path to match your setup.
cp -r "/var/www/html/data/<username>/files/<Path on your nextcloud sync>/nc_bot_webhooks/"* /var/www/html/custom_apps/nc_bot_webhooks/

chown -R www-data:www-data /var/www/html/custom_apps/nc_bot_webhooks
chmod -R u+rX,go+rX /var/www/html/custom_apps/nc_bot_webhooks

cd /var/www/html
php occ app:enable nc_bot_webhooks
php occ config:app:delete nc_bot_webhooks routes 2>/dev/null || true
php occ maintenance:repair
```

> **Note:** The path `"/var/www/html/data/<username>/files/<Path on your nextcloud sync>/nc_bot_webhooks/"` is where your Nextcloud user's synced files land on the server (TrueNAS Docker path). Replace `<username>` with your Nextcloud username, and adjust `<Path on your nextcloud sync>/nc_bot_webhooks/` to match the directory you are syncing to.

---

## Webhook URLs

Each configured room gets two webhook URLs:

### Discord format

```
https://your-nextcloud-server/apps/nc_bot_webhooks/discord-webhook/<room-token>/<auth-token>
```

### Apprise format

```
https://your-nextcloud-server/apps/nc_bot_webhooks/apprise-webhook/<room-token>/notify/<auth-token>
```

> **Note:** The `notify` segment is required — Apprise's `apprises://` URL scheme inserts it in the path automatically.

### Multiple auth tokens per room

Each room can have multiple auth tokens — useful for rotating keys or sharing the webhook across multiple services. Generate additional tokens in the admin settings UI.

---

## Payload Formats

### Discord Webhook Format

```json
{
  "content": "Build #1234 passed",
  "embeds": [
    {
      "title": "Deployment",
      "description": "Successfully deployed to production",
      "color": 3066993,
      "image": { "url": "https://example.com/screenshot.png" },
      "thumbnail": { "url": "https://example.com/thumb.png" },
      "fields": [
        { "name": "Duration", "value": "2m 34s" },
        { "name": "Environment", "value": "Production" }
      ]
    }
  ],
  "username": "CI/CD",
  "avatar_url": "https://example.com/icon.png"
}
```

### Apprise Format

Apprise sends a JSON wrapper containing a `notifications` array:

```json
{
  "version": 0,
  "subject": "Build #1234",
  "title": "Deployment",
  "type": "info",
  "notifications": [
    {
      "subject": "Build #1234",
      "title": "Deployment",
      "body": "Successfully deployed to production",
      "type": "info",
      "attachments": [
        { "path": "https://example.com/screenshot.png" }
      ]
    }
  ]
}
```

Apprise also supports form-encoded payloads. The app auto-detects the content type (JSON, `multipart/form-data`, or form-encoded).

---

## Payload Mapping

| Field | Nextcloud Talk action |
|---|---|
| `content` | Sent as text message |
| `embeds[].title` | Included as bold line |
| `embeds[].description` | Included in message body |
| `embeds[].image.url` | Downloaded, uploaded to NC, shared inline |
| `embeds[].thumbnail.url` | Downloaded, uploaded to NC, shared inline |
| `embeds[].fields[].name` + `value` | Formatted as `name: value` lines |
| `username` | Sender display name (prepended to message) |
| `avatar_url` | Ignored (NCTalk doesn't support per-message avatars) |
| `subject` / `title` (Apprise) | Used as bold title line |
| `body` (Apprise) | Sent as message body |
| `attachments[].path` (Apprise) | Downloaded, uploaded to NC, shared inline |
| `type` (Apprise) | Maps to icon: ✅ success, ⚠️ warning, ❌ error, none for info/image |

### Sender name resolution

When posting to Talk, the app prepends a bold sender name line to the message (since Talk doesn't support per-message avatars). The sender name is resolved in this order:

1. `username` field from Discord payload
2. `subject` / `title` from Apprise payload
3. Configured default sender name (Settings → Default Sender Name)

---

## Image Management

- Images from webhooks are downloaded via HTTP and uploaded to the bot user's files
- Stored at `/nc_bot_webhooks-images/<room-token>/` under the bot user's personal storage
- A public link share is created for each image so Talk can resolve the rich object and display the inline attachment
- **Cron job** (`ImageCleanup`) purges images older than the configured retention period (default: 90 days)
- Images count toward the bot user's storage quota
- Purge operates on the bot user's personal storage directory only — it does not scan other users' files

---

## Security

| Mechanism | Description |
|---|---|
| **Auth tokens** | Each webhook URL contains a unique secret token; the endpoint validates it before processing |
| **Bot password** | Encrypted at rest using Nextcloud's crypto layer (`ICrypto`) |
| **No CSRF** | Webhook endpoints are marked `#[PublicPage]` + `#[NoCSRFRequired]` — auth token is the sole access control |
| **Local address blocking** | Image downloads use Nextcloud's HTTP client with `allow_local_address: true` (blocks private ranges by default) |
| **Path traversal protection** | Uploaded filenames use `basename()` to prevent directory traversal |
| **Rate limiting** | Not built in — handle at the web server or reverse proxy level |

### Known limitations

- Auth tokens generated from the settings UI use client-side generation; for higher security, regenerate them via the server API
- The webhook endpoint has no rate limiting — consider placing it behind a reverse proxy rate limiter if exposing to untrusted sources

---

## Debug Endpoint

The `/apps/nc_bot_webhooks/debug` endpoint exposes internal configuration, database schema, and bot credentials. It is **disabled by default**.

```bash
# Check status
php occ nc_bot_webhooks:debug:status

# Enable (WARNING: exposes sensitive data)
php occ nc_bot_webhooks:debug:enable

# Disable (default)
php occ nc_bot_webhooks:debug:disable

# Toggle current state
php occ nc_bot_webhooks:debug:toggle
```

After troubleshooting, disable it immediately:

```bash
php occ nc_bot_webhooks:debug:disable
```

---

## Integrations

### Home Assistant

Use the built-in `rest_command` integration to send notifications from Home Assistant to Nextcloud Talk.

#### 1. Define the REST command

Add this to your Home Assistant `configuration.yaml`:

```yaml
rest_command:
  notify_nextcloud_apprise:
    url: "https://<your-nextcloud-server>/apps/nc_bot_webhooks/apprise-webhook/<room-token>/notify/<auth-token>"
    method: post
    content_type: application/json
    payload: >
      {{ {
        'message': message,
        'sender_name': sender_name | default('Webhook Bot'),
        'subject': subject | default('Notification'),
        'type': type | default('info'),
        'attachments': attachments | default([])
      } | to_json }}
```

Replace `<room-token>` and `<auth-token>` with your values from the Nextcloud admin settings.

#### 2. Call the command from an automation or script

**Text-only notification:**

```yaml
action: rest_command.notify_nextcloud_apprise
data:
  message: "Garbage collection complete"
  sender_name: "HA - Valetudo"
  subject: "Vacuum"
  type: info
```

**Notification with a remote image attachment:**

```yaml
action: rest_command.notify_nextcloud_apprise
data:
  message: "{{ title | default('Notification') }} => {{ message | default('') }}"
  sender_name: "HA - Valetudo"
  subject: "{{ title | default('Notification') }}"
  type: image
  attachments:
    - path: https://<your-home-assistant-server>/local/camera.glados_vacuum_camera.jpg
```

The app will download the image from the URL, upload it to the bot user's storage, create a public link, and embed it inline in the Talk message.

**Direct file upload (multipart/form-data):**

To send a file directly from Home Assistant (e.g., a sensor-generated image or log), use `data` with `Content-Type: multipart/form-data`. The app accepts files under the key `file01`:

```yaml
action: rest_command.notify_nextcloud_apprise
data:
  message: "Sensor snapshot"
  subject: "Camera"
  type: image
  content_type: multipart/form-data
  data:
    file01: !secret camera_image_path
```

> **Note:** When using `multipart/form-data`, the app reads the uploaded file from `$_FILES['file01']` on the server side. The file is uploaded directly to the bot user's storage without needing an intermediate HTTP download step.

---

## Logging

Responses include a `X-Webhook-Status` header:

| Header value | Meaning |
|---|---|
| `ok` | Forwarded successfully |
| `unauthorized` | Invalid auth token |
| `bad_request` | Invalid JSON payload |
| `no_content` | No message content in payload |
| `error` | Check server logs for details |

---

## Debugging

Enable debug logging by setting the log level to `0` (debug) in your Nextcloud `config.php`:

```php
'loglevel' => 0,
```

Or via `occ`:

```bash
php occ config:app:set --value=0 core log_level
```

The default log file on a TrueNAS Scale Nextcloud Docker install is `/var/www/html/data/nextcloud.log`. Adjust the path if your data directory is elsewhere.

### Diagnostic grep examples

Each section below covers a common symptom, what to grep for, and what to look for.

---

#### Webhook endpoint is being hit

Confirm the webhook URL is receiving traffic:

```bash
grep 'nc_bot_webhooks: receiveApprise raw request' /var/www/html/data/nextcloud.log
grep 'nc_bot_webhooks: webhook processed successfully' /var/www/html/data/nextcloud.log
```

**What to look for:** A `receiveApprise raw request` entry followed by a `webhook processed successfully` entry. If neither appears, the webhook isn't reaching the server (check your Discord/Apprise webhook URL, network routing, or reverse proxy).

---

#### Invalid auth token

A webhook is being rejected with `401 unauthorized`:

```bash
grep 'nc_bot_webhooks: invalid auth token for room' /var/www/html/data/nextcloud.log
```

**What to look for:** The room token in the log entry. Compare it against the tokens shown in **Settings → Admin → nc_bot_webhooks**. If the token in the webhook URL doesn't match any configured token for that room, the webhook will be rejected. Regenerate the token in the admin settings and update your external service's webhook URL.

---

#### Invalid JSON / malformed payload

The webhook is being rejected with `400 bad_request`:

```bash
grep 'nc_bot_webhooks: invalid JSON from webhook' /var/www/html/data/nextcloud.log
grep 'nc_bot_webhooks: invalid payload from apprise webhook' /var/www/html/data/nextcloud.log
```

**What to look for:** The parsed payload snippet in the log entry. Verify the JSON structure matches the [Discord Webhook Format](#discord-webhook-format) or [Apprise Format](#apprise-format) documented above.

---

#### Message not appearing in Talk

The webhook succeeded but the message didn't show up in the Talk room:

```bash
grep 'nc_bot_webhooks: failed to post.*message to Talk' /var/www/html/data/nextcloud.log
grep 'nc_bot_webhooks: webhook processed successfully' /var/www/html/data/nextcloud.log
```

**What to look for:**
- If you see `failed to post.*message to Talk`, the app couldn't reach the Talk Chat API. Check that the bot user has an app password configured and that the bot is a participant in the target room.
- If you see `webhook processed successfully` but no error, the message was posted but Talk may have silently dropped it. Verify the bot user is listed as a participant in the Talk room.

---

#### Image download/upload fails

Images from webhooks aren't appearing inline:

```bash
grep 'nc_bot_webhooks: Failed to download image' /var/www/html/data/nextcloud.log
grep 'nc_bot_webhooks: Failed to upload image' /var/www/html/data/nextcloud.log
grep 'nc_bot_webhooks: uploadImage — bot user not found' /var/www/html/data/nextcloud.log
grep 'nc_bot_webhooks: image processing failed' /var/www/html/data/nextcloud.log
```

**What to look for:**
- `Failed to download image` — the source URL is unreachable, blocked by local address filtering, or returned an error. Verify the image URL is publicly accessible.
- `Failed to upload image` — check the error message for details (quota exceeded, filesystem error, etc.).
- `uploadImage — bot user not found` — the `talk-bot` user doesn't exist. Re-run Step 2 of installation.
- `image processing failed` — image download succeeded but upload failed. Check the bot user's storage quota and permissions.

---

#### Bot user not found

Errors referencing the `talk-bot` user:

```bash
grep 'nc_bot_webhooks: uploadImage — bot user not found' /var/www/html/data/nextcloud.log
```

**What to look for:** This confirms the `talk-bot` user doesn't exist in Nextcloud. Create it via `php occ user:add --password-from-env talk-bot` (Step 2 of installation).

---

#### Config save fails

Admin settings not saving:

```bash
grep 'nc_bot_webhooks: saveConfig failed' /var/www/html/data/nextcloud.log
```

**What to look for:** The error message from the crypto layer. Common causes include an invalid bot password (contains characters that break encryption) or a misconfigured Nextcloud crypto backend.

---

#### Room listing empty

The "Fetch Rooms" button returns no rooms:

```bash
grep 'nc_bot_webhooks: getAvailableTalkRooms' /var/www/html/data/nextcloud.log
grep 'nc_bot_webhooks: found.*rooms' /var/www/html/data/nextcloud.log
grep 'nc_bot_webhooks: room listing exception' /var/www/html/data/nextcloud.log
```

**What to look for:**
- `found 0 rooms` — the bot user may not have admin privileges. Re-grant admin access (Step 3 of installation).
- `room listing exception` — check the exception details for the root cause (Talk app not installed, database error, etc.).

---

#### Image cleanup cron not running

Images are not being purged after the retention period:

```bash
grep 'nc_bot_webhooks: ImageCleanup' /var/www/html/data/nextcloud.log
```

**What to look for:** A log entry each time the cron job runs. If nothing appears, ensure system cron is configured to run `php occ cron.php` (not the "Web" cron setting in Admin settings). Check the Nextcloud system cron status:

```bash
php occ system:cron:set --method=cron
```

---

#### Payload mapping inspection

See exactly how the webhook payload was mapped to Talk format (verbose):

```bash
grep 'nc_bot_webhooks: DEBUG mapped' /var/www/html/data/nextcloud.log
```

**What to look for:** The full mapped payload JSON. This is useful when a message appears incorrectly in Talk — compare the mapped payload against the expected Talk Chat API format.

> **Note:** Debug logging is verbose and impacts performance. After troubleshooting, restore your log level to `2` (warning) or higher.

| Problem | Solution |
|---|---|
| **"talk-bot user not found"** | The `talk-bot` user doesn't exist. Re-run Step 2 of installation. |
| **"Bot password not configured"** | You haven't entered the bot password in admin settings, or it was cleared. Re-enter it. |
| **Messages not appearing** | Check the Nextcloud log (`data/nextcloud.log` or Settings → Admin → Logging) for errors from the `nc_bot_webhooks` app. |
| **Image upload fails** | Verify the bot user has file storage quota and the `nc_bot_webhooks-images` folder can be created. |
| **Apprise 500 error** | Likely config data not migrated from the old app ID. Re-enter settings or run: `UPDATE oc_appconfig SET appid = 'nc_bot_webhooks' WHERE appid = 'ncdiscordhook';` |
| **Bot not listed in room participants** | The `ensureBotParticipants()` method runs automatically on save, but you may need to re-check the room in admin settings to trigger it. |
