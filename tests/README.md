# nc_bot_webhooks Test Suite

## Running Tests Locally

```bash
cd nc_bot_webhooks
composer install
composer test
```

## Requirements

- PHP 8.2+
- Composer

## What Each Test File Covers

### `TalkServiceTest.php`

Unit tests for `Service/TalkService.php` business logic. All TalkService constructor dependencies are mocked via `PHPUnit\Framework\TestCase::getMockForTrait()`.

**Test groups:**

- **validateBotPassword** — empty string, valid password, special chars, unicode
- **getBaseUrl** — overwritehost priority, overwritewebroot, trusted_domains filtering, CLI URL fallback, empty config
- **getRooms / setRooms** — JSON persistence round-trip, empty config, invalid JSON
- **getAuthTokens / setAuthTokens** — JSON persistence, empty config
- **validateAuthToken** — valid token, invalid token, nonexistent room, multiple tokens
- **generateAuthToken** — 48-char hex token, uniqueness across calls
- **revokeAuthToken** — removes specific token, cleans empty room arrays, nonexistent token
- **mapPayload** — content only, embeds with title/description/fields, empty payload, multiple embeds
- **mapApprisePayload** — body only, title fallback, type icons, image type with attachments, nested data key
- **getSenderName** — sender_name > username > config default priority
- **getSenderNameDefault** — config default
- **prependDisplayName** — with/without message, with type icon
- **downloadImage** — successful download, empty body
- **purgeOldImages** — files older/newer than cutoff, empty directory, bot user not found

### `WebhookControllerTest.php`

Unit tests for `Controller/WebhookController.php` HTTP endpoints. Tests response codes and headers for the controller methods that don't depend on `php://input` (auth validation and room listing), plus fallback paths that use `$_GET` and `$_POST` directly.

**Test groups:**

- **receive** — invalid auth token (401), empty body (400), $_GET fallback with content, $_GET fallback with embeds
- **receiveApprise** — invalid auth token (401), empty body (400), $_POST fallback with notifications, $_POST with image type, $_GET fallback with notifications
- **receiveAppriseNotify** — delegates to receiveApprise (401)
- **saveConfig** — error handling, invalid config (400), success with auth_tokens (200)
- **saveBotPassword** — invalid password (400), valid password (200)
- **getRooms** — empty rooms, room list with configured flag, exception → 500
- **debug** — disabled (403), enabled for admin (200), enabled for non-admin (200)

### `ImageCleanupTest.php`

Unit tests for `Cron/ImageCleanup.php` cron job. Tests the full run() flow including early-exit paths and success logging.

**Test groups:**

- **Early exits** — no bot user, no images directory, root folder exception
- **Success** — purge runs and logs count, no log when count is 0
- **Edge cases** — null argument handling

### `NavigationProviderTest.php`

Unit tests for `NavigationProvider` settings link. Tests that the navigation link is only shown to admin users.

**Test groups:**

- **Non-admin** — null user, non-admin user → empty array
- **Admin** — admin user → navigation link with correct metadata

### `DebugToggleTest.php`

Unit tests for `Command/DebugToggle.php` OCC command. Tests --status, --enable, --disable, toggle, and --enable/--disable conflict.

**Test groups:**

- **--status** — disabled by default, enabled when set
- **--enable** — sets value to true, logs warning
- **--disable** — sets value to false, logs success
- **Toggle** — enables when disabled, disables when enabled
- **Conflict** — --enable + --disable together returns INVALID (1)

## Adding New Tests

1. Determine which service or controller the new code belongs to.
2. Add test methods to the appropriate test file.
3. Use `makeTalkServiceMock()` (WebhookControllerTest) or the helper methods in TalkServiceTest to set up mocks.
4. Run `composer test` to verify.

## Test Architecture

- **TalkService dependencies**: All 17 constructor parameters are mocked using PHPUnit's `getMockForTrait()` for abstract/interface types and `createMock()` for concrete classes.
- **WebhookController dependencies**: TalkService is mocked; the other 9 dependencies use `createMock()`.
- **ImageCleanup dependencies**: TalkService, IRootFolder, IUserManager, LoggerInterface — all mocked.
- **NavigationProvider dependencies**: IURLGenerator, IUserSession, IConfig, IL10N — all mocked.
- **DebugToggle dependencies**: IAppConfig — mocked; Symfony Console Input/Output used for CLI interaction.
- **No real Nextcloud instance**: Tests verify business logic in isolation. Image upload/download tests verify the download logic but not real file storage.
- **php://input**: The controller reads from `php://input` which cannot be mocked directly. Tests cover:
  - Auth-failure path (no input needed)
  - `$_GET` fallback for receive (simulated via `$_GET` in test setup)
  - `$_POST` fallback for receiveApprise (simulated via `$_POST` in test setup)
  - Payload parsing / mapping logic is covered by TalkServiceTest.

## CI

Tests run automatically on every push and PR via Gitea Actions. See `.gitea/workflows/ci.yml`.

## Test Summary

| File | Tests | Coverage |
|------|-------|----------|
| TalkServiceTest.php | 48 | All TalkService methods |
| WebhookControllerTest.php | 21 | All controller endpoints + fallbacks |
| ImageCleanupTest.php | 6 | All run() paths |
| NavigationProviderTest.php | 3 | Admin/non-admin navigation |
| DebugToggleTest.php | 7 | --status, --enable, --disable, toggle, conflict |
| **Total** | **85** | All lib/ components covered |
