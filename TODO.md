# nc_bot_webhooks — Pending Tasks

## Debug Endpoint
- [x] Re-enable admin auth requirement for `/debug` endpoint (WebhookController.php)
- [x] Re-enable admin gate + update docs
- [ ] Add auto-disable timer (2-hour TTL) to prevent accidental prolonged exposure

## README / Documentation Fixes
- [x] Section 4: App password path — confirmed "Personal Settings → Security" is correct
- [x] Section 4 (FIXED): Template hint `templates/adminSettings.php:11` — confirmed correct path
- [x] Section 4: l10n string `lib/Settings/Admin.php:43` — confirmed correct path
- [x] Section 5: "Administration → Additional settings" → confirmed correct for current NC
- [x] Section 3: "Settings → Users" → confirmed correct for current NC
- [x] Security table: `allow_local_address: true` description — clarified wording
- [x] Image cleanup cron grep — already correct in README (`grep 'nc_bot_webhooks: purged'`)
- [x] Troubleshooting: `ncdiscordhook` app ID — not in README (only in agent.md, which is correct)
- [x] Payload Mapping: `mapPayload` title — confirmed bold formatting correct
- [x] Payload Mapping: `type` icon mapping — confirmed actual emojis listed
- [x] Home Assistant YAML: `message` vs `body` — `message` works via fallback, no change needed
- [x] Version: bumped to 1.2.1 (info.xml + agent.md updated)
- [x] NC max-version: docs say 33, info.xml says 35 — agent.md updated

## Code Documentation Fixes
- [x] `buildRichObject` docs describe public link shares; code uses TYPE_ROOM shares
- [x] `postToRoom` docs describe JSON body posting; code uses query params
- [x] `prependDisplayName` signature — already documents all 3 params including `$typeIcon`
- [x] `getRooms()` response schema missing `type`, `type_label`, `object_type`
- [x] `saveConfig` docs missing `disabled_rooms` field
- [x] `ensureBotParticipants` visibility — already documented as `private` with call context
- [x] `getBaseUrl()` resolution incompletely documented
- [x] TalkService constructor params: docs say 8, actual 17
- [x] TalkService.php size: docs say 1138 lines, actual 1904
- [ ] All line numbers in agent.md are stale

## Code Issues
- [ ] Room type filter `type IN (1,2,3)` misses type 6 (Note to self) and future types

## Cleanup
- [x] `config:app:delete nc_bot_webhooks routes` — removed from README (routes are defined in routes.php, not stored in DB)
- [ ] `composer.json` exists but migration plan says delete it (resolved — keep it for dev tooling, update migration plan)
- [x] agent.md dead references cleaned: `app (copy).svg`, `composer/autoload.php`, `composer/autoload_psr4.php` (removed from directory tree). `tests/`, `phpunit.xml.dist`, `.gitea/workflows/ci.yml` were not actually in agent.md (false positives)
- [x] `webhook-setup.md` — added clear label "Nextcloud Talk Native Bot Setup (bots-v1)" with note distinguishing from nc_bot_webhooks
