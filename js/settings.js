document.addEventListener('DOMContentLoaded', function () {
    const APP_ID = 'ncdiscordhook';

    // Read config passed via data attributes on the page
    const dataEl = document.getElementById('nc-config-data');
    const configuredRooms = dataEl ? JSON.parse(dataEl.dataset.configuredRooms || '{}') : {};
    const authTokens = dataEl ? JSON.parse(dataEl.dataset.authTokens || '{}') : {};

    // Elements
    const botPasswordInput = document.getElementById('nc-bot-password');
    const retentionInput = document.getElementById('nc-retention');
    const fetchRoomsBtn = document.getElementById('nc-fetch-rooms');
    const roomsList = document.getElementById('nc-rooms-list');
    const saveBtn = document.getElementById('nc-save');
    const savePasswordBtn = document.getElementById('nc-save-password');
    const statusMsg = document.getElementById('nc-status');

    let configuredRoomsState = configuredRooms;
    let authTokensState = authTokens;

    function showStatus(msg, type) {
        statusMsg.textContent = msg;
        statusMsg.className = 'nc-status-' + type;
        setTimeout(() => { statusMsg.textContent = ''; }, 5000);
    }

    // Show/hide the save-password button when user types
    botPasswordInput.addEventListener('input', function () {
        savePasswordBtn.style.display = this.value.trim() ? 'inline-block' : 'none';
    });

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
                if (room.configured || (authTokensState[room.token] && authTokensState[room.token].length > 0)) {
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

        const tokens = authTokensState[roomToken] || [];

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
                    delete authTokensState[roomToken];
                    container.style.display = 'none';
                } else {
                    authTokensState[roomToken] = tokens;
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
            if (!authTokensState[roomToken]) {
                authTokensState[roomToken] = [];
            }
            authTokensState[roomToken].push(btoa(Math.random().toString(36).substring(2) + Date.now().toString(36)).replace(/=/g, ''));
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

    // Save bot password only
    savePasswordBtn.addEventListener('click', async function () {
        var pw = botPasswordInput.value.trim();
        if (!pw) return;
        savePasswordBtn.disabled = true;
        savePasswordBtn.textContent = 'Saving...';

        try {
            var resp = await fetch(OC.generateUrl('/apps/' + APP_ID + '/save-bot-password'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bot_password: pw }),
            });
            var data = await resp.json();

            if (data.status === 'ok') {
                showStatus('Bot password saved', 'success');
                botPasswordInput.value = '';
                savePasswordBtn.style.display = 'none';
            } else {
                showStatus('Save failed: ' + (data.error || 'unknown error'), 'error');
            }
        } catch (err) {
            showStatus('Save failed: ' + err.message, 'error');
        }

        savePasswordBtn.disabled = false;
        savePasswordBtn.textContent = 'Save';
    });

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
        Object.keys(configuredRoomsState).forEach(function (token) {
            if (rooms[token] === undefined) {
                rooms[token] = configuredRoomsState[token];
            }
        });

        const payload = {
            rooms: rooms,
            auth_tokens: authTokensState,
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
                configuredRoomsState = rooms;
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
