document.addEventListener('DOMContentLoaded', function () {
    const APP_ID = 'nc_bot_webhooks';

    // Read config passed via data attributes on the page
    function parseData(el, key) {
        if (!el || !el.dataset || !el.dataset[key]) return {};
        try {
            var val = JSON.parse(el.dataset[key]);
            return (val && typeof val === 'object') && !Array.isArray(val) ? val : {};
        } catch (e) { return {}; }
    }
    const dataEl = document.getElementById('nc-config-data');
    const configuredRooms = parseData(dataEl, 'configuredRooms');
    const authTokens = parseData(dataEl, 'authTokens');
    const serverUrl = dataEl ? (dataEl.dataset.serverUrl || '') : '';

    // Elements
    const botPasswordInput = document.getElementById('nc-bot-password');
    const senderNameInput = document.getElementById('nc-sender-name');
    const retentionInput = document.getElementById('nc-retention');
    const fetchRoomsBtn = document.getElementById('nc-fetch-rooms');
    const roomsList = document.getElementById('nc-rooms-list');
    const saveBtn = document.getElementById('nc-save');
    const savePasswordBtn = document.getElementById('nc-save-password');
    const statusMsg = document.getElementById('nc-status');

    let configuredRoomsState = configuredRooms;
    let authTokensState = authTokens;
    let serverAuthTokens = authTokens; // canonical server state, re-synced after save

    function showStatus(msg, type) {
        statusMsg.textContent = msg;
        statusMsg.className = 'nc-status-' + type;
        setTimeout(() => { statusMsg.textContent = ''; }, 5000);
    }

    // Show/hide the save-password button when user types
    botPasswordInput.addEventListener('input', function () {
        savePasswordBtn.style.display = this.value.trim() ? 'inline-block' : 'none';
    });

    // Copy CLI commands to clipboard
    document.querySelectorAll('.nc-copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = this.dataset.copy || '';
            navigator.clipboard.writeText(text).then(function () {
                var orig = this.textContent;
                this.textContent = '✓';
                this.style.color = '#28a745';
                setTimeout(function () {
                    btn.textContent = orig;
                    btn.style.color = '';
                }, 2000);
            }.bind(this));
        });
    });

    // Fetch available Talk rooms
    function fetchRooms() {
        fetchRoomsBtn.disabled = true;
        fetchRoomsBtn.textContent = 'Fetching...';

        fetch(OC.generateUrl('/apps/' + APP_ID + '/rooms')).then(function (resp) {
            return resp.json();
        }).then(function (data) {
            roomsList.innerHTML = '';

            if (data.length === 0) {
                roomsList.innerHTML = '<p class="nc-empty">No Talk rooms found. Create rooms first via the Nextcloud Talk settings.</p>';
                fetchRoomsBtn.textContent = 'Fetch Rooms';
                fetchRoomsBtn.disabled = false;
                return;
            }

            data.forEach(function (room) {
                var label = document.createElement('label');
                label.className = 'nc-room-item';

                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = room.token;
                cb.id = 'room-' + room.token;
                if (room.configured) {
                    cb.checked = true;
                }
                cb.addEventListener('change', function () {
                    const tokenDiv = document.getElementById('tokens-' + room.token);
                    if (tokenDiv) {
                        tokenDiv.style.display = cb.checked ? 'block' : 'none';
                        if (cb.checked) {
                            renderTokens(room.token, tokenDiv);
                        }
                    }
                });

                var nameSpan = document.createElement('span');
                nameSpan.textContent = room.name || room.token;

                label.appendChild(cb);
                label.appendChild(nameSpan);
                roomsList.appendChild(label);

                // Auth tokens container
                var tokenDiv = document.createElement('div');
                tokenDiv.className = 'nc-room-tokens';
                tokenDiv.id = 'tokens-' + room.token;
                tokenDiv.style.display = 'none';
                if (room.configured || (serverAuthTokens[room.token] && serverAuthTokens[room.token].length > 0)) {
                    tokenDiv.style.display = 'block';
                    renderTokens(room.token, tokenDiv);
                }
                roomsList.appendChild(tokenDiv);
            });

            fetchRoomsBtn.textContent = 'Refresh Rooms';
            fetchRoomsBtn.disabled = false;
        }).catch(function (err) {
            showStatus('Failed to fetch rooms', 'error');
            fetchRoomsBtn.textContent = 'Fetch Rooms';
            fetchRoomsBtn.disabled = false;
        });
    }
    fetchRoomsBtn.addEventListener('click', fetchRooms);

    // Auto-fetch rooms on load if there are already configured rooms
    if (Object.keys(configuredRoomsState).length > 0) {
        fetchRooms();
    }

    // Render auth tokens for a room
    function renderTokens(roomToken, container) {
        container.innerHTML = '';

        const tokens = authTokensState[roomToken] || [];

        // Build both webhook URLs with server origin
        var discordPath = OC.generateUrl('/apps/' + APP_ID + '/discord-webhook/') + roomToken + '/{token}';
        var apprisePath = OC.generateUrl('/apps/' + APP_ID + '/apprise-webhook/') + roomToken + '/notify/{token}';
        var discordUrl = window.location.protocol + '//' + window.location.host + discordPath;
        var appriseUrl = window.location.protocol + '//' + window.location.host + apprisePath;

        if (tokens.length === 0) {
            // Show a hint that they can generate a token
            var hint = document.createElement('p');
            hint.className = 'nc-token-hint';
            hint.textContent = 'No tokens yet. Generate one below.';
            hint.style.color = '#888';
            hint.style.fontSize = '0.85em';
            container.appendChild(hint);
        }

        tokens.forEach(function (token) {
            var discordFullUrl = discordUrl.replace('{token}', encodeURIComponent(token));
            var appriseFullUrl = appriseUrl.replace('{token}', encodeURIComponent(token));

            // Discord webhook URL row
            var labelDiscord = document.createElement('div');
            labelDiscord.className = 'nc-token-hint';
            labelDiscord.style.marginBottom = '2px';
            labelDiscord.textContent = 'Discord webhook:';
            container.appendChild(labelDiscord);

            var rowDiscord = document.createElement('div');
            rowDiscord.className = 'nc-token-row';

            var urlInputD = document.createElement('input');
            urlInputD.type = 'text';
            urlInputD.value = discordFullUrl;
            urlInputD.readOnly = true;
            urlInputD.className = 'nc-token-url-input';
            urlInputD.title = discordFullUrl;

            var copyBtnD = document.createElement('button');
            copyBtnD.type = 'button';
            copyBtnD.className = 'nc-token-copy';
            copyBtnD.textContent = 'Copy';
            copyBtnD.addEventListener('click', function () {
                navigator.clipboard.writeText(discordFullUrl).then(function () {
                    copyBtnD.textContent = 'Copied!';
                    setTimeout(function () { copyBtnD.textContent = 'Copy'; }, 2000);
                });
            });

            rowDiscord.appendChild(urlInputD);
            rowDiscord.appendChild(copyBtnD);
            container.appendChild(rowDiscord);

            // Apprise webhook URL row
            var labelApprise = document.createElement('div');
            labelApprise.className = 'nc-token-hint';
            labelApprise.style.marginTop = '4px';
            labelApprise.style.marginBottom = '2px';
            labelApprise.textContent = 'Apprise webhook:';
            container.appendChild(labelApprise);

            var rowApprise = document.createElement('div');
            rowApprise.className = 'nc-token-row';

            var urlInputA = document.createElement('input');
            urlInputA.type = 'text';
            urlInputA.value = appriseFullUrl;
            urlInputA.readOnly = true;
            urlInputA.className = 'nc-token-url-input';
            urlInputA.title = appriseFullUrl;

            var copyBtnA = document.createElement('button');
            copyBtnA.type = 'button';
            copyBtnA.className = 'nc-token-copy';
            copyBtnA.textContent = 'Copy';
            copyBtnA.addEventListener('click', function () {
                navigator.clipboard.writeText(appriseFullUrl).then(function () {
                    copyBtnA.textContent = 'Copied!';
                    setTimeout(function () { copyBtnA.textContent = 'Copy'; }, 2000);
                });
            });

            rowApprise.appendChild(urlInputA);
            rowApprise.appendChild(copyBtnA);
            container.appendChild(rowApprise);

            // Revoke button (applied to all tokens for this room)
            var revokeBtn = document.createElement('button');
            revokeBtn.type = 'button';
            revokeBtn.className = 'nc-token-revoke';
            revokeBtn.textContent = 'Revoke All';
            revokeBtn.addEventListener('click', function () {
                if (!confirm('Revoke all auth tokens for this room?')) return;
                tokens.length = 0;
                delete authTokensState[roomToken];
                renderTokens(roomToken, container);
            });
            container.appendChild(revokeBtn);
        });

        // Generate new token button
        var genBtn = document.createElement('button');
        genBtn.type = 'button';
        genBtn.className = 'nc-generate-token';
        genBtn.textContent = '+ Generate Auth Token';
        genBtn.addEventListener('click', function () {
            if (!authTokensState[roomToken]) {
                authTokensState[roomToken] = [];
            }
            // Use URL-safe base64 (no +, /, = characters)
            // TODO: Switch to server-side token generation for higher security (client-side Math.random() is not cryptographically secure)
            var raw = Math.random().toString(36).substring(2) + Date.now().toString(36);
            var token = btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
            authTokensState[roomToken].push(token);
            renderTokens(roomToken, container);
            container.style.display = 'block';
        });
        container.appendChild(genBtn);
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

        // Collect configured rooms from checked checkboxes
        const rooms = {};
        document.querySelectorAll('#nc-rooms-list input[type="checkbox"]:checked').forEach(function (cb) {
            const token = cb.value;
            rooms[token] = configuredRoomsState[token] || ''; // preserve name from existing config
        });

        // Remove rooms that were previously configured but are now unchecked
        const disabledRooms = [];
        Object.keys(configuredRoomsState).forEach(function (token) {
            if (rooms[token] === undefined) {
                disabledRooms.push(token);
            }
        });

        const payload = {
            rooms: rooms,
            disabled_rooms: disabledRooms,
            auth_tokens: authTokensState,
            retention_days: parseInt(retentionInput.value) || 90,
            sender_name: senderNameInput.value || 'Webhook Bot',
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
                // Sync auth tokens from server to fix state drift
                if (data.auth_tokens) {
                    serverAuthTokens = data.auth_tokens;
                    authTokensState = Object.assign({}, serverAuthTokens);
                    // Re-render token divs for re-enabled rooms
                    document.querySelectorAll('#nc-rooms-list input[type="checkbox"]:checked').forEach(function (cb) {
                        const tokenDiv = document.getElementById('tokens-' + cb.value);
                        if (tokenDiv && tokenDiv.style.display !== 'none') {
                            renderTokens(cb.value, tokenDiv);
                        }
                    });
                }
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
