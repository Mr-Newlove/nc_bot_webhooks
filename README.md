# NCbotwebhooks

Discord webhook bridge for Nextcloud Talk with image attachment support.

Accepts Discord webhook-style JSON payloads and posts them into Nextcloud Talk rooms, preserving images and embed formatting.

## Installation

### 1. Deploy the app

```bash
cd /path/to/nextcloud/apps
cp -r /path/to/nc_bot_webhooks .
php occ app:enable nc_bot_webhooks
```

### 2. Create the bot user

```bash
php occ user:add --password-from-env --display-name="Webhook Bot" talk-bot
```

### 3. Generate an app password for the bot

1. Log in as `talk-bot` (or set a password as admin)
2. Go to **Settings → Security → Devices & sessions → Add device**
3. Copy the app password

### 4. Configure the app

Go to **Settings → Admin → NCbotwebhooks**:

1. **Bot Configuration** — paste the app password from step 3
2. **Image Retention** — set how long to keep uploaded images (default: 90 days)
3. **Room Management** — click "Fetch Rooms" to see available Talk rooms, select the ones you want, and generate auth tokens

### 5. Point your services at the webhook URLs

Each configured room gets **two** webhook URLs (Discord and Apprise formats):

```
https://your-server.com/apps/nc_bot_webhooks/discord-webhook/<room-token>/<auth-token>
```

Copy the auth token from the app settings for each room.

## API

### Accepts (Discord webhook format)

```json
{
  "content": "Build #1234 passed",
  "embeds": [
    {
      "title": "Deployment",
      "description": "Successfully deployed to production",
      "color": 3066993,
      "image": { "url": "https://example.com/screenshot.png" },
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

### Sends to Nextcloud Talk

- Formatted text message (content + embeds + fields)
- Each image from `embeds[].image` or `embeds[].thumbnail` uploaded and shared inline
- `username` shown as the sender display name

### Payload mapping

| Discord field | Nextcloud action |
|---|---|
| `content` | Sent as text message |
| `embeds[].title` | Included as bold line |
| `embeds[].description` | Included in message body |
| `embeds[].image.url` | Downloaded, uploaded to NC, shared inline |
| `embeds[].thumbnail.url` | Downloaded, uploaded to NC, shared inline |
| `embeds[].fields` | Formatted as `name: value` lines |
| `username` | Sender display name |
| `avatar_url` | Ignored (NCTalk doesn't support per-message avatars) |

## Room routing

Each room gets its own webhook URL with a unique auth token:

```
/discord-webhook/<room-token>/<auth-token>
```

- **Room token** — identifies which Talk room to post to (from `occ talk:room:list`)
- **Auth token** — secret key for this webhook endpoint (generated in app settings)

Multiple auth tokens can be created per room — useful if you need to rotate keys or share the webhook across multiple services.

### Apprise webhook

For Apprise integrations, each room also gets an Apprise webhook URL:

```
https://your-server.com/apps/nc_bot_webhooks/apprise-webhook/<room-token>/notify/<auth-token>
```

Note: the `notify` segment is required — Apprise's `apprises://` URL scheme inserts it in the path.

Apprise sends a different JSON format. Supported fields:

```json
{
  "title": "Build #1234",
  "body": "Successfully deployed to production",
  "type": "info",
  "attachments": [
    { "url": "https://example.com/screenshot.png" }
  ]
}
```

The apprise webhook maps `title`, `body`, and `attachments` to the same Talk message format as the Discord endpoint, so images and formatting work the same way.

## Image management

- Images are uploaded to the bot user's files at `/nc_bot_webhooks-images/<room-token>/`
- Cron job purges images older than the configured retention period (default: 90 days)
- Images are stored in the bot user's storage — they count toward the bot user's quota

## Security

- Auth token in the URL is the primary auth mechanism — keep it secret
- Bot password is encrypted at rest using Nextcloud's crypto
- Image download uses Nextcloud's HTTP client with local address blocking
- Rate limiting should be handled at the web server or reverse proxy level
- **Debug endpoint disabled by default** — see below

### Debug endpoint

The `/apps/nc_bot_webhooks/debug` endpoint exposes internal configuration,
database schema, and bot credentials. It is **disabled by default** and must
be explicitly enabled via the CLI:

```bash
# Check current status
php occ nc_bot_webhooks:debug:status

# Enable (WARNING: exposes sensitive data)
php occ nc_bot_webhooks:debug:enable

# Disable (default)
php occ nc_bot_webhooks:debug:disable

# Toggle current state
php occ nc_bot_webhooks:debug:toggle
```

Never leave the debug endpoint enabled in production. After troubleshooting,
disable it immediately:

```bash
php occ nc_bot_webhooks:debug:disable
```

## Logging

Responses include a `X-Webhook-Status` header:

| Header value | Meaning |
|---|---|
| `ok` | Forwarded successfully |
| `unauthorized` | Invalid auth token |
| `bad_request` | Invalid JSON payload |
| `no_content` | No message content in payload |
| `error` | Check server logs for details |
