# NCbotwebhooks

Webhook bridge that forwards Discord webhook-style and Apprise payloads into Nextcloud Talk rooms, with image attachment support and per-room authentication tokens.

Accepts Discord webhook-compatible JSON payloads (embeds, fields, images) and Apprise notification payloads, maps them to Nextcloud Talk Chat API v1 format, and posts them as the `talk-bot` user.

## Table of Contents

- [Installation](#installation)
- [Manual Deployment](#manual-deployment)
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
- [Integrations](#integrations)
  - [Home Assistant](#home-assistant)
- [Troubleshooting](#troubleshooting)

---

## Installation

### 1. Deploy the app

```bash
cd /path/to/nextcloud/apps
cp -r /path/to/nc_bot_webhooks .
php occ app:enable nc_bot_webhooks
```

Or upload via the Nextcloud web UI as a ZIP (only the `nc_bot_webhooks` directory, not its parent).

### 2. Create the bot user

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
3. Click **Add device** and give it a name (e.g., "NCbotwebhooks")
4. Copy the generated device password — you'll need it in the next step

### 5. Configure the app

Go to **Settings → Admin → NCbotwebhooks**:

1. **Bot App Password** — paste the app password from step 4
2. **Default Sender Name** — set the name that appears as the message sender (default: "Webhook Bot")
3. **Image Retention** — set how many days to keep uploaded images (default: 90)
4. **Room Selection** — click **Fetch Rooms** to list available Talk rooms
5. Check the rooms you want to accept webhooks for
6. For each room, click **+ Generate Auth Token** to create a webhook URL
7. Click **Save Configuration**

---

## Manual Deployment

> **Note:** The paths below are specific to a TrueNAS Scale Nextcloud Docker install. Adjust them to match your deployment.

This section covers updating the app on a running Nextcloud instance using the synced-file workflow.

**Workflow:** Keep your local development copy synced to your server via the Nextcloud desktop client. Edit files locally, then deploy with the script below.

### Update script

```bash
cd /var/www/html
php occ app:disable nc_bot_webhooks

rm -rf /var/www/html/custom_apps/nc_bot_webhooks/*
mkdir -p /var/www/html/custom_apps/nc_bot_webhooks

# Update from your synced Nextcloud files directory — adjust this path to match your setup.
cp -r "/var/www/html/data/<username>/files/TrueNAS configs/Nextcloud Hooker/nc_bot_webhooks/"* /var/www/html/custom_apps/nc_bot_webhooks/

chown -R www-data:www-data /var/www/html/custom_apps/nc_bot_webhooks
chmod -R u+rX,go+rX /var/www/html/custom_apps/nc_bot_webhooks

cd /var/www/html
php occ app:enable nc_bot_webhooks
php occ config:app:delete nc_bot_webhooks routes 2>/dev/null || true
php occ maintenance:repair
clear
```

> **Note:** The path `"/var/www/html/data/<username>/files/TrueNAS configs/Nextcloud Hooker/nc_bot_webhooks/"` is where your Nextcloud user's synced files land on the server (TrueNAS Docker path). Replace `<username>` with your Nextcloud username, and adjust `TrueNAS configs/Nextcloud Hooker/nc_bot_webhooks/` to match the directory you are syncing to.

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

## Troubleshooting

| Problem | Solution |
|---|---|
| **"talk-bot user not found"** | The `talk-bot` user doesn't exist. Re-run Step 2 of installation. |
| **"Bot password not configured"** | You haven't entered the bot password in admin settings, or it was cleared. Re-enter it. |
| **Messages not appearing** | Check the Nextcloud log (`data/nextcloud.log` or Settings → Admin → Logging) for errors from the `nc_bot_webhooks` app. |
| **Image upload fails** | Verify the bot user has file storage quota and the `nc_bot_webhooks-images` folder can be created. |
| **Apprise 500 error** | Likely config data not migrated from the old app ID. Re-enter settings or run: `UPDATE oc_appconfig SET appid = 'nc_bot_webhooks' WHERE appid = 'ncdiscordhook';` |
| **Bot not listed in room participants** | The `ensureBotParticipants()` method runs automatically on save, but you may need to re-check the room in admin settings to trigger it. |
