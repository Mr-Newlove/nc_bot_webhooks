<div class="nc-settings-section">
    <h3>Bot Configuration</h3>
    <label for="nc-bot-password">Bot App Password</label><br>
    <div style="display: flex; gap: 8px; align-items: center;">
        <input type="password" id="nc-bot-password" placeholder="<?= $hasBotPassword ? '•••••••• (leave blank to keep current)' : 'Paste talk-bot app password here' ?>" style="flex: 1;">
        <button id="nc-save-password" type="button" style="display: none;">Save</button>
    </div>
    <p class="nc-hint">
        Generate in Nextcloud Settings → <strong>talk-bot</strong> → Devices &amp; sessions → <strong>Add device</strong>.
        <?= $hasBotPassword ? 'Leave blank to keep current password.' : 'Required to send messages to Talk.' ?>
    </p>
    <p class="nc-hint" style="margin-top: 4px;">
        <strong>The bot user must be an admin</strong> to list all Talk rooms. Grant admin access in <strong>Settings → Users → [your-bot-user] → Admin</strong>.
    </p>
</div>

<!-- Config data passed to JS via data attributes -->
<div id="nc-config-data"
     data-configured-rooms="<?= json_encode($rooms ?? []) ?>"
     data-auth-tokens="<?= json_encode($authTokens ?? []) ?>"
     style="display:none;">
</div>

<div class="nc-settings-section">
    <h3>Image Retention</h3>
    <label for="nc-retention">Retention period (days)</label><br>
    <input type="number" id="nc-retention" value="<?= $retentionDays ?>" min="1" max="365" step="1">
    <p class="nc-hint">Images older than this many days will be purged by the daily cron job. Default: 90 days.</p>
</div>

<div class="nc-settings-section">
    <h3>Room Management</h3>
    <button id="nc-fetch-rooms" type="button">Fetch Rooms</button>
    <div id="nc-rooms-list"></div>
    <p class="nc-hint">Select Talk rooms to accept webhooks for. Each room gets its own webhook URL with an auth token.</p>
</div>

<div class="nc-settings-section">
    <button id="nc-save" type="button">Save Configuration</button>
    <span id="nc-status" class="nc-status"></span>
</div>
