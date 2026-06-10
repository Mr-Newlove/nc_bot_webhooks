<script>
document.addEventListener('DOMContentLoaded', function () {
    const APP_ID = 'ncdiscordhook';

    // Elements
    const botPasswordInput = document.getElementById('nc-bot-password');
    const retentionInput = document.getElementById('nc-retention');
    const fetchRoomsBtn = document.getElementById('nc-fetch-rooms');
    const roomsList = document.getElementById('nc-rooms-list');
    const saveBtn = document.getElementById('nc-save');
    const statusMsg = document.getElementById('nc-status');

    let configuredRooms = <?= json_encode($rooms ?? []) ?>;
    let authTokens = <?= json_encode($authTokens ?? []) ?>;

    function showStatus(msg, type) {
        statusMsg.textContent = msg;
        statusMsg.className = 'nc-status-' + type;
        setTimeout(() => { statusMsg.textContent = ''; }, 5000);
    }

    // Fetch available Talk rooms
    fetchRoomsBtn.addEventListener('click', async function () {
        fetchRoomsBtn.disabled = true;
        fetchRoomsBtn.textContent = 'Fetching...';

        try {
            const resp = await fetch(OC.generateUrl('/apps/' + APP_ID + '/rooms'));
            const data = await resp.json();

            roomsList.innerHTML = '';

            if (data.length === 0) {
                roomsList.innerHTML = '<p class="nc-empty">No Talk rooms found. Create rooms first via the Nextcloud Talk settings.</p>';
                return;
            }

            data.forEach(function (room) {
                const label = document.createElement('label');
                label.className = 'nc-room-item';

                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = room.token;
                cb.id = 'room-' + room.token;
                if (room.configured) {
                    cb.checked = true;
                    cb.disabled = true;
                }
                cb.addEventListener('change', function () { toggleRoomTokens(room.token, this.checked); });

                const nameSpan = document.createElement('span');
                nameSpan.textContent = room.name || room.token;

                label.appendChild(cb);
                label.appendChild(nameSpan);
                roomsList.appendChild(label);

                // Auth tokens container
                const tokenDiv = document.createElement('div');
                tokenDiv.className = 'nc-room-tokens';
                tokenDiv.id = 'tokens-' + room.token;
                tokenDiv.style.display = 'none';
                if (room.configured || (authTokens[room.token] && authTokens[room.token].length > 0)) {
                    tokenDiv.style.display = 'block';
                    renderTokens(room.token, tokenDiv);
                }
                roomsList.appendChild(tokenDiv);
            });

            fetchRoomsBtn.textContent = 'Refresh Rooms';
        } catch (err) {
            showStatus('Failed to fetch rooms', 'error');
            fetchRoomsBtn.textContent = 'Fetch Rooms';
        }

        fetchRoomsBtn.disabled = false;
    });

    // Render auth tokens for a room
    function renderTokens(roomToken, container) {
        container.innerHTML = '';

        const tokens = authTokens[roomToken] || [];

        tokens.forEach(function (token) {
            const row = document.createElement('div');
            row.className = 'nc-token-row';

            const input = document.createElement('input');
            input.type = 'text';
            input.value = token;
            input.readOnly = true;
            input.className = 'nc-token-input';

            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'nc-token-copy';
            copyBtn.textContent = 'Copy';
            copyBtn.addEventListener('click', function () {
                navigator.clipboard.writeText(token).then(() => {
                    copyBtn.textContent = 'Copied!';
                    setTimeout(() => { copyBtn.textContent = 'Copy'; }, 2000);
                });
            });

            const revokeBtn = document.createElement('button');
            revokeBtn.type = 'button';
            revokeBtn.className = 'nc-token-revoke';
            revokeBtn.textContent = 'Revoke';
            revokeBtn.addEventListener('click', function () {
                if (!confirm('Revoke this auth token?')) return;
                tokens.splice(tokens.indexOf(token), 1);
                if (tokens.length === 0) {
                    delete authTokens[roomToken];
                    container.style.display = 'none';
                } else {
                    authTokens[roomToken] = tokens;
                    renderTokens(roomToken, container);
                }
            });

            row.appendChild(input);
            row.appendChild(copyBtn);
            row.appendChild(revokeBtn);
            container.appendChild(row);
        });

        // Generate new token button
        const genBtn = document.createElement('button');
        genBtn.type = 'button';
        genBtn.className = 'nc-generate-token';
        genBtn.textContent = '+ Generate Auth Token';
        genBtn.addEventListener('click', function () {
            if (!authTokens[roomToken]) {
                authTokens[roomToken] = [];
            }
            authTokens[roomToken].push(btoa(Math.random().toString(36).substring(2) + Date.now().toString(36)).replace(/=/g, ''));
            renderTokens(roomToken, container);
            container.style.display = 'block';
        });
        container.appendChild(genBtn);
    }

    // Toggle room token display
    function toggleRoomTokens(roomToken, checked) {
        const tokenDiv = document.getElementById('tokens-' + roomToken);
        if (tokenDiv) {
            tokenDiv.style.display = checked ? 'block' : 'none';
        }
    }

    // Save configuration
    saveBtn.addEventListener('click', async function () {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        // Collect configured rooms
        const rooms = {};
        document.querySelectorAll('#nc-rooms-list input[type="checkbox"]:checked').forEach(function (cb) {
            const token = cb.value;
            rooms[token] = ''; // name will be filled from existing config
        });

        // Restore names from existing config for newly checked rooms
        Object.keys(configuredRooms).forEach(function (token) {
            if (rooms[token] === undefined) {
                rooms[token] = configuredRooms[token];
            }
        });

        const payload = {
            rooms: rooms,
            auth_tokens: authTokens,
            retention_days: parseInt(retentionInput.value) || 90,
        };

        if (botPasswordInput.value) {
            payload.bot_password = botPasswordInput.value;
        }

        try {
            const resp = await fetch(OC.generateUrl('/apps/' + APP_ID + '/save-config'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await resp.json();

            if (data.status === 'ok') {
                configuredRooms = rooms;
                showStatus('Configuration saved', 'success');
                botPasswordInput.value = '';
            } else {
                showStatus('Save failed: ' + (data.error || 'unknown error'), 'error');
            }
        } catch (err) {
            showStatus('Save failed: ' + err.message, 'error');
        }

        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Configuration';
    });
});
</script>

<style>
    .nc-settings-section {
        margin-bottom: 2em;
        padding: 1em;
        border: 1px solid var(--color-border);
        border-radius: 4px;
    }
    .nc-settings-section h3 {
        margin-top: 0;
    }
    .nc-room-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 4px 0;
    }
    .nc-room-item span {
        font-size: 0.9em;
    }
    .nc-room-tokens {
        margin-left: 24px;
        margin-top: 8px;
        padding: 8px;
        background: var(--color-background-dark);
        border-radius: 4px;
    }
    .nc-token-row {
        display: flex;
        gap: 4px;
        margin-bottom: 4px;
    }
    .nc-token-input {
        flex: 1;
        font-family: monospace;
        font-size: 0.85em;
        background: var(--color-main-background);
        border: 1px solid var(--color-border);
        padding: 4px 8px;
        border-radius: 3px;
    }
    .nc-token-copy {
        padding: 4px 8px;
        background: var(--color-primary-element);
        color: white;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 0.85em;
    }
    .nc-token-revoke {
        padding: 4px 8px;
        background: var(--color-error);
        color: white;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 0.85em;
    }
    .nc-generate-token {
        margin-top: 8px;
        padding: 4px 12px;
        background: var(--color-primary-element);
        color: white;
        border: none;
        border-radius: 3px;
        cursor: pointer;
    }
    .nc-empty {
        color: var(--color-text-lighter);
        font-style: italic;
    }
    .nc-status-success { color: var(--color-success); }
    .nc-status-error { color: var(--color-error); }
</style>

<div class="nc-settings-section">
    <h3>Bot Configuration</h3>
    <label for="nc-bot-password">Bot App Password</label><br>
    <input type="password" id="nc-bot-password" placeholder="<?= $hasBotPassword ? '•••••••• (leave blank to keep current)' : 'Paste talk-bot app password here' ?>">
    <p class="nc-hint">
        Generate in Nextcloud Settings → <strong>talk-bot</strong> → Devices &amp; sessions → <strong>Add device</strong>.
        <?= $hasBotPassword ? 'Leave blank to keep current password.' : 'Required to send messages to Talk.' ?>
    </p>
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
