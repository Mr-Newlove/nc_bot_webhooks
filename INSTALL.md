# NCdiscordhook — Installation Guide

## Prerequisites

- Nextcloud 28+ with the **Talk** app enabled
- PHP 8.1+
- Access to the Nextcloud server (SSH or direct file access)

## Step 1: Enable the `talk-bot` user

The app posts messages as a dedicated bot user. You must create this user and generate an app password.

### Create the bot user (via `occ`):

```bash
cd /path/to/nextcloud
php occ user:add --password-from-env talk-bot
# You'll be prompted to set a password interactively, or:
export OC_PASS="your-bot-password-here"
php occ user:add --password-from-env talk-bot
```

### Generate an app password for the bot:

1. Log in to Nextcloud as **admin**
2. Go to **Settings → talk-bot → Devices & sessions**
3. Click **Add device** and give it a name (e.g., "NCdiscordhook")
4. Copy the generated device password — you'll need this in Step 3

## Step 2: Install the app

### Option A: From the web UI (recommended)

1. ZIP only the `ncdiscordhook` directory (not the parent folder):

```bash
cd /path/to/ncdiscordhook
zip -r ../ncdiscdhook.zip .
```

2. Upload via web UI:
   - Go to **Apps → Apps management** (or **Administration → Apps**)
   - Click **Upload app** (or **Download and enable** → upload the ZIP)
   - Select `ncdiscdhook.zip`
   - The app will install and enable automatically

### Option B: From the command line

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/your-org/ncdiscordhook.git
# or copy the directory manually:
# cp -r /path/to/ncdiscordhook /path/to/nextcloud/apps/ncdiscordhook
```

Enable the app:

```bash
cd /path/to/nextcloud
php occ app:enable ncdiscordhook
```

## Step 3: Configure the app

1. Log in to Nextcloud as **admin**
2. Go to **Settings → Admin → NCdiscordhook**
3. **Bot App Password** — Paste the device password you generated in Step 1
4. **Image Retention** — Set how many days to keep uploaded images (default: 90)
5. **Room Management** — Click **Fetch Rooms** to list available Talk rooms
6. Check the rooms you want to accept webhooks for
7. For each room, click **+ Generate Auth Token** to create a webhook URL
8. Click **Save Configuration**

## Step 4: Set up the Discord webhook

1. In your Discord channel, go to **Channel Settings → Integrations → Webhooks**
2. Create a new webhook (or edit an existing one)
3. Set the Webhook URL to:

```
https://your-nextcloud-server/apps/ncdiscordhook/webhook/<room-token>/<auth-token>
```

Replace `<room-token>` and `<auth-token>` with the values shown in the Nextcloud admin settings for the selected room.

4. Save the webhook in Discord

## Step 5: Verify

Send a test message through the Discord webhook. You should see it appear in the corresponding Nextcloud Talk room with the configured sender name.

Test with curl:

```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"content":"Test message from NCdiscordhook","username":"CI Bot"}' \
  https://your-nextcloud-server/apps/ncdiscordhook/webhook/<room-token>/<auth-token>
```

## Troubleshooting

- **"talk-bot user not found"** — The `talk-bot` user doesn't exist. Re-run Step 1.
- **"Bot password not configured"** — You haven't entered the bot password in the admin settings, or it was cleared. Re-enter it in Step 3.
- **Messages not appearing** — Check the Nextcloud log (`data/nextcloud.log` or Settings → Admin → Logging) for errors from the `ncdiscordhook` app.
- **Image upload fails** — Verify the bot user has file storage quota and the `NCdiscordhook-images` folder can be created.

## Security Notes

- Each room has its own auth token — treat them like passwords
- The webhook endpoint is public (no auth required) but validates the auth token from the URL path
- Admin settings endpoints (`/save-config`, `/rooms`) require admin login
- Images are stored in the bot user's files and purged after the retention period
- **Known limitations (to be fixed in a future release):**
  - Auth tokens generated from the settings UI use client-side generation; for higher security, regenerate them via the server API
  - The webhook endpoint has no rate limiting — consider placing it behind a reverse proxy rate limiter if exposing to untrusted sources
