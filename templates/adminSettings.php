<div class="nc-app-frame">
    <h2>Nextcloud Bot Webhooks</h2>
    <div class="nc-settings-section">
        <h3>Bot App Password</h3>
        <label for="nc-bot-password"><strong>Enter bot app password</strong></label><br>
        <div style="display: flex; gap: 8px; align-items: center; margin: 8px 0;">
            <input type="password" id="nc-bot-password" placeholder="<?= $hasBotPassword ? '●●●●●●●● (leave blank to keep current)' : 'Paste talk-bot app password here' ?>" style="flex: 1; padding: 8px; font-size: 1em;">
            <button id="nc-save-password" type="button" style="display: <?= $hasBotPassword ? 'none' : 'inline-block' ?>;">Save</button>
        </div>
        <p class="nc-hint">
            Generate in <strong>Personal Settings</strong> → <strong>Security</strong> → Devices &amp; sessions → <strong>Add device</strong> for the <strong>talk-bot</strong> bot user account (not a regular user).
            <?= $hasBotPassword ? 'Leave blank to keep current password.' : 'Required to send messages to Talk.' ?>
        </p>
        <p class="nc-hint" style="margin-top: 4px;">
            <strong>The bot user must be an admin</strong> to list all Talk rooms. Grant admin access in <strong>Settings → Users → [your-bot-user] → Admin</strong>.
        </p>
    </div>

    <div class="nc-settings-section">
        <h3>Default Sender Name</h3>
        <label for="nc-sender-name">Sender name used when posting messages</label><br>
        <input type="text" id="nc-sender-name" value="<?= htmlspecialchars($senderName ?? 'Webhook Bot') ?>" style="width: 100%; padding: 8px; font-size: 1em; margin: 4px 0;">
        <p class="nc-hint">This name appears as the sender of webhook messages in Talk.</p>
    </div>

    <div class="nc-settings-section">
        <h3>Image Retention</h3>
        <label for="nc-retention">Retention period (days)</label><br>
        <input type="number" id="nc-retention" value="<?= $retentionDays ?>" min="1" max="365" step="1">
        <p class="nc-hint">Images older than this many days will be purged by the daily cron job. Default: 90 days.</p>
    </div>

    <div class="nc-settings-section">
        <h3>Room Selection</h3>
        <button id="nc-fetch-rooms" type="button">Fetch Rooms</button>
        <div id="nc-rooms-list"></div>
        <p class="nc-hint">Check rooms to enable webhooks for. Each room gets its own auth token.</p>
        <button id="nc-save" type="button" style="margin-top: 1em;">Save Configuration</button>
        <span id="nc-status" class="nc-status"></span>
    </div>
</div>

<!-- Config data passed to JS via data attributes -->
<div id="nc-config-data"
     data-configured-rooms="<?= htmlspecialchars(json_encode($rooms ?? []), ENT_QUOTES, 'UTF-8') ?>"
     data-auth-tokens="<?= htmlspecialchars(json_encode($authTokens ?? []), ENT_QUOTES, 'UTF-8') ?>"
     data-server-url="<?= htmlspecialchars($serverUrl ?? 'https://localhost', ENT_QUOTES, 'UTF-8') ?>"
     data-sender-name="<?= htmlspecialchars($senderName ?? 'Webhook Bot', ENT_QUOTES, 'UTF-8') ?>"
     data-has-bot-password="<?= $hasBotPassword ? '1' : '0' ?>"
     data-retention="<?= htmlspecialchars((string)($retentionDays ?? 90), ENT_QUOTES, 'UTF-8') ?>"
     data-l10n="<?= htmlspecialchars(json_encode($l10n ?? [], ENT_QUOTES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
     style="display:none;">
</div>
