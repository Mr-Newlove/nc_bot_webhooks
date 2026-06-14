# nc_bot_webhooks — Installation Guide

## Prerequisites

- Nextcloud 33 with the **Talk** app enabled
- PHP 8.1+
- Access to the Nextcloud server (SSH or direct file access)

## Step 1: Enable the `talk-bot` user

The app posts messages as a dedicated bot user using the Talk Chat API (Basic auth). You must create this user and generate an app password.

### Create the bot user (via `occ`):

```bash
cd /path/to/nextcloud
php occ user:add --password-from-env talk-bot
# You'll be prompted to set a password interactively, or:
export OC_PASS="your-bot-password-here"
php occ user:add --password-from-env talk-bot
```

### Make the bot user an admin

The bot must have admin privileges to list all Talk rooms. Grant admin access:

1. Go to **Settings → Users**
2. Find **talk-bot** and click it
3. Check **Admin** under "Settings"
4. Save

### Generate an app password for the bot:

1. Log in to Nextcloud as **admin**
2. Go to **Settings → talk-bot → Devices & sessions**
3. Click **Add device** and give it a name (e.g., "nc_bot_webhooks")
4. Copy the generated device password — you'll need this in Step 3

## Step 2: Install the app

### Option A: From the web UI (recommended)

1. ZIP only the `nc_bot_webhooks` directory (not the parent folder):

```bash
cd /path/to/nc_bot_webhooks
zip -r ../nc_bot_webhooks.zip .
```

2. Upload via web UI:
   - Go to **Apps → Apps management** (or **Administration → Apps**)
   - Click **Upload app** (or **Download and enable** → upload the ZIP)
   - Select `nc_bot_webhooks.zip`
   - The app will install and enable automatically

### Option B: From the command line

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/Mr-Newlove/nc_bot_webhooks.git
# or copy the directory manually:
# cp -r /path/to/nc_bot_webhooks /path/to/nextcloud/apps/nc_bot_webhooks
```

Enable the app:

```bash
cd /path/to/nextcloud
php occ app:enable nc_bot_webhooks
```

## Step 3: Configure the app

1. Log in to Nextcloud as **admin**
2. Go to **Settings → Admin → nc_bot_webhooks**
3. **Bot App Password** — Paste the device password you generated in Step 1
4. **Default Sender Name** — Set the name that appears as the message sender (default: "Webhook Bot")
5. **Image Retention** — Set how many days to keep uploaded images (default: 90)
6. **Room Selection** — Click **Fetch Rooms** to list available Talk rooms
7. Check the rooms you want to accept webhooks for
8. For each room, click **+ Generate Auth Token** to create a webhook URL
9. Click **Save Configuration**

## Step 4: Set up the webhook URL

1. For Discord: go to **Channel Settings → Integrations → Webhooks**, create a new webhook, and set the Webhook URL to:

```
https://your-nextcloud-server/apps/nc_bot_webhooks/discord-webhook/<room-token>/<auth-token>
```

Replace `<room-token>` and `<auth-token>` with the values shown in the Nextcloud admin settings for the selected room.

4. Save the webhook in Discord

## Step 5: Verify

Send a test message through the Discord webhook. You should see it appear in the corresponding Nextcloud Talk room with the configured sender name.

Test with curl:

```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"content":"Test message from nc_bot_webhooks","username":"CI Bot"}' \
  https://your-nextcloud-server/apps/nc_bot_webhooks/discord-webhook/<room-token>/<auth-token>
```

## Apprise webhook

For Apprise integrations, use the `/apprise-webhook/` endpoint with the same room-token and auth-token:

```
https://your-nextcloud-server/apps/nc_bot_webhooks/apprise-webhook/<room-token>/notify/<auth-token>
```

Note: the `notify` segment is required — Apprise's `apprises://` URL scheme inserts it in the path.

Apprise sends a different JSON format (`title`, `body`, `type`, `attachments`) — the app maps these to the same Talk message format.

## Troubleshooting

- **"talk-bot user not found"** — The `talk-bot` user doesn't exist. Re-run Step 1.
- **"Bot password not configured"** — You haven't entered the bot password in the admin settings, or it was cleared. Re-enter it in Step 3.
- **Messages not appearing** — Check the Nextcloud log (`data/nextcloud.log` or Settings → Admin → Logging) for errors from the `nc_bot_webhooks` app.
- **Image upload fails** — Verify the bot user has file storage quota and the `nc_bot_webhooks-images` folder can be created.

## Security Notes

- Each room has its own auth token — treat them like passwords
- The webhook endpoint is public (no auth required) but validates the auth token from the URL path
- Admin settings endpoints (`/save-config`, `/rooms`) require admin login
- Images are stored in the bot user's files and purged after the retention period
- **Debug endpoint disabled by default** — see below
- **Known limitations (to be fixed in a future release):**
  - Auth tokens generated from the settings UI use client-side generation; for higher security, regenerate them via the server API
  - The webhook endpoint has no rate limiting — consider placing it behind a reverse proxy rate limiter if exposing to untrusted sources

### Debug endpoint

The `/apps/nc_bot_webhooks/debug` endpoint exposes internal configuration,
database schema, and bot credentials. It is **disabled by default**.

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
