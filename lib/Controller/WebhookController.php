<?php

namespace OCA\Ncbotwebhooks\Controller;

use OCA\Ncbotwebhooks\Service\TalkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

class WebhookController extends Controller {
    private TalkService $talkService;
    private LoggerInterface $logger;
    private IAppManager $appManager;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private IConfig $config;
    private IAppConfig $appConfig;
    private IClientService $clientService;
    private IShareManager $shareManager;

    public function __construct(IRequest $request, TalkService $talkService, LoggerInterface $logger, IAppManager $appManager, IUserSession $userSession, IGroupManager $groupManager, IConfig $config, IAppConfig $appConfig, IClientService $clientService, IShareManager $shareManager) {
        parent::__construct('nc_bot_webhooks', $request);
        $this->talkService = $talkService;
        $this->logger = $logger;
        $this->appManager = $appManager;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->appConfig = $appConfig;
        $this->clientService = $clientService;
        $this->shareManager = $shareManager;
    }

    /**
     * Receive Discord webhook payload for a room.
     *
     * URL: POST /apps/nc_bot_webhooks/discord-webhook/{roomToken}/{token}
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function receive(string $roomToken, string $token): DataResponse {
        // Validate auth token
        if (!$this->talkService->validateAuthToken($roomToken, $token)) {
            $this->logger->warning('NCbotwebhooks: invalid auth token for room', [
                'app' => 'nc_bot_webhooks',
                'room_token' => $roomToken,
            ]);
            return new DataResponse(
                ['error' => 'Unauthorized'],
                Http::STATUS_UNAUTHORIZED,
                ['X-Webhook-Status' => 'unauthorized'],
            );
        }

        // Parse Discord JSON payload
        $body = file_get_contents('php://input');
        $data = @json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            // Fall back to $_GET — HA's rest_command sends payload as query params
            // when the request body is empty.
            if (!empty($_GET) && is_array($_GET)) {
                $data = $_GET;
            }
        }
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->logger->warning('NCbotwebhooks: invalid JSON from webhook', [
                'app' => 'nc_bot_webhooks',
                'room_token' => $roomToken,
                'body_length' => strlen($body),
                'get_empty' => empty($_GET) ? true : null,
            ]);
            return new DataResponse(
                ['error' => 'Invalid JSON'],
                Http::STATUS_BAD_REQUEST,
                ['X-Webhook-Status' => 'bad_request'],
            );
        }

        // Unwrap nested "data" key — HA's REST notify platform wraps the actual payload under data:
        // {"message": "...", "data": {"embeds": [...], "username": "..."}}
        if (empty($data['content']) && isset($data['data']) && is_array($data['data'])) {
            if (!empty($data['data']['content'])) {
                $data['content'] = $data['data']['content'];
            } elseif (!empty($data['data']['message'])) {
                $data['content'] = $data['data']['message'];
            }
            if (empty($data['embeds']) && !empty($data['data']['embeds'])) {
                $data['embeds'] = $data['data']['embeds'];
            }
            if (empty($data['username']) && !empty($data['data']['username'])) {
                $data['username'] = $data['data']['username'];
            }
            if (empty($data['sender_name']) && !empty($data['data']['sender_name'])) {
                $data['sender_name'] = $data['data']['sender_name'];
            }
        }

        // Fallback: use "message" key as content if no content found (HA REST notify sends `message` not `content`)
        if (empty($data['content']) && !empty($data['message'])) {
            $data['content'] = $data['message'];
        }

        // Map to Talk message
        $message = $this->talkService->mapPayload($data);
        if ($message === '') {
            return new DataResponse(
                ['error' => 'No message content'],
                Http::STATUS_BAD_REQUEST,
                ['X-Webhook-Status' => 'no_content'],
            );
        }

        $senderName = $this->talkService->getSenderName($data);
        // Prepend display name since Talk doesn't support per-message avatars
        $message = $this->talkService->prependDisplayName($senderName, $message);

        // Handle images — wrap in try-catch so image failures don't crash the message
        $richObjects = [];
        $imageError = null;
        try {
            if (!empty($data['embeds']) && is_array($data['embeds'])) {
                foreach ($data['embeds'] as $embed) {
                    if (!is_array($embed)) {
                        continue;
                    }

                    foreach (['image', 'thumbnail'] as $imageKey) {
                        if (empty($embed[$imageKey]) || !is_array($embed[$imageKey]) || empty($embed[$imageKey]['url'])) {
                            continue;
                        }

                        $imageData = $this->talkService->downloadImage($embed[$imageKey]['url']);
                        if ($imageData === null) {
                            continue;
                        }

                        // Derive filename from URL or content type
                        $parsed = parse_url($embed[$imageKey]['url']);
                        $pathParts = explode('/', $parsed['path'] ?? '');
                        $filename = end($pathParts);
                        if (!$filename || strlen($filename) < 2) {
                            $ext = pathinfo($imageData['mimeType'], PATHINFO_EXTENSION) ?: 'png';
                            $filename = 'webhook-image.' . $ext;
                        }

                        $filePath = $this->talkService->uploadImage($roomToken, $filename, $imageData['data'], $imageData['mimeType']);
                        if ($filePath !== null) {
                            $richObj = $this->talkService->buildRichObject($filePath, $imageData['mimeType'], $roomToken);
                            if ($richObj !== null) {
                                $richObjects[] = $richObj;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $imageError = $e->getMessage();
            $this->logger->error('NCbotwebhooks: image processing failed, continuing without images', [
                'app' => 'nc_bot_webhooks',
                'room_token' => $roomToken,
                'error' => $e->getMessage(),
            ]);
        }

        // Handle Apprise file attachments — sent as multipart/form-data.
        // Apprise API sends metadata in $data['attachments'] and file bytes
        // in $_FILES['attachment[]'] (array of files).
        if (!empty($_FILES['attachment[]']['name']) && is_array($_FILES['attachment[]']['name'])) {
            $fileNames = $_FILES['attachment[]']['name'];
            $fileTmps = $_FILES['attachment[]']['tmp_name'];
            $fileTypes = $_FILES['attachment[]']['type'];
            $fileErrors = $_FILES['attachment[]']['error'];
            $attachmentMeta = $data['attachments'] ?? [];

            for ($i = 0; $i < count($fileNames); $i++) {
                if ($fileErrors[$i] !== UPLOAD_ERR_OK) {
                    $this->logger->warning('NCbotwebhooks: file upload error for attachment ' . $i, [
                        'app' => 'nc_bot_webhooks',
                        'error_code' => $fileErrors[$i],
                    ]);
                    continue;
                }

                $filename = basename($fileNames[$i]) ?: 'upload.' . pathinfo($fileTypes[$i] ?? '', PATHINFO_EXTENSION);
                $mimeType = $fileTypes[$i] ?: 'application/octet-stream';
                $fileData = file_get_contents($fileTmps[$i]);

                if ($fileData === false || strlen($fileData) === 0) {
                    $this->logger->warning('NCbotwebhooks: empty or unreadable file attachment ' . $i, [
                        'app' => 'nc_bot_webhooks',
                        'tmp_name' => $fileTmps[$i],
                    ]);
                    continue;
                }

                $filePath = $this->talkService->uploadImage($roomToken, $filename, $fileData, $mimeType);
                if ($filePath !== null) {
                    $richObj = $this->talkService->buildRichObject($filePath, $mimeType, $roomToken);
                    if ($richObj !== null) {
                        $richObjects[] = $richObj;
                    }
                }
            }
        }

        // Post to Talk via Chat API
        $success = $this->talkService->postToRoom($roomToken, $message, $senderName, $richObjects);

        if ($success) {
            $this->logger->info('NCbotwebhooks: webhook processed successfully', [
                'app' => 'nc_bot_webhooks',
                'room_token' => $roomToken,
            ]);
            return new DataResponse(
                ['status' => 'ok'],
                Http::STATUS_CREATED,
                ['X-Webhook-Status' => 'ok'],
            );
        }

        $this->logger->error('NCbotwebhooks: failed to post webhook message to Talk', [
            'app' => 'nc_bot_webhooks',
            'room_token' => $roomToken,
        ]);
        return new DataResponse(
            ['error' => 'Failed to post to Talk'],
            Http::STATUS_INTERNAL_SERVER_ERROR,
            ['X-Webhook-Status' => 'error'],
        );
    }

    /**
     * Receive Apprise webhook payload for a room.
     *
     * URL: POST /apps/nc_bot_webhooks/apprise-webhook/{roomToken}/{token}
     * Also handles Apprise's notify URL format: /apprise-webhook/{roomToken}/notify/{token}
     *
     * Apprise sends JSON like:
     * {
     *   "version": 0,
     *   "type": "info|success|warning|error",
     *   "title": "Title",
     *   "body": "Message body",
     *   "attachments": [{"path": "file:///path/to/file", "name": "file.png"}]
     * }
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function receiveApprise(string $roomToken, string $token): DataResponse {
        // Validate auth token
        if (!$this->talkService->validateAuthToken($roomToken, $token)) {
            $this->logger->warning('NCbotwebhooks: invalid auth token for room', [
                'app' => 'nc_bot_webhooks',
                'room_token' => $roomToken,
            ]);
            return new DataResponse(
                ['error' => 'Unauthorized'],
                Http::STATUS_UNAUTHORIZED,
                ['X-Webhook-Status' => 'unauthorized'],
            );
        }

        // Parse payload — Apprise API sends {"version": 0, "notifications": [...]}
        // while direct webhook may send form-encoded or flat JSON
        $body = file_get_contents('php://input');

        // Nextcloud framework can consume php://input — fall back to /dev/stdin
        if ($body === false || $body === '') {
            $stdin = fopen('/dev/stdin', 'r');
            if ($stdin) {
                $body = stream_get_contents($stdin);
                fclose($stdin);
            }
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        // Log raw request details for debugging
        $preview = substr($body, 0, 2000);
        // Sanitize binary data from preview to avoid corrupting logs
        $preview = preg_replace('/[^\x20-\x7E\x0A\x0D\x09]/', '.', $preview);
        $this->logger->info('NCbotwebhooks: receiveApprise raw request', [
            'app' => 'nc_bot_webhooks',
            'room_token' => $roomToken,
            'content_type' => $contentType,
            'body_length' => strlen($body),
            'body_preview' => $preview,
            'files_count' => count($_FILES),
            'files' => json_encode($_FILES),
            'post_count' => count($_POST),
            'get_count' => count($_GET),
        ]);

        // Try JSON first
        $data = @json_decode($body, true);
        $jsonOk = json_last_error() === JSON_ERROR_NONE && is_array($data);

        if (!$jsonOk) {
            // Check if content-type indicates multipart form data
            if (stripos($contentType, 'multipart/form-data') !== false) {
                // PHP auto-parses multipart: files go to $_FILES, form fields to $_POST
                if (!empty($_POST)) {
                    $data = $_POST;
                }
                // Apprise API sends the notification payload as form fields (type, title, body)
                // and the file separately in $_FILES['file01'].
                // Construct the attachments array from $_FILES so mapApprisePayload can process it.
                if (!empty($_FILES['file01']) && $_FILES['file01']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['file01'];
                    if (!empty($data['type']) && $data['type'] === 'image') {
                        $data['attachments'] = [[
                            'path' => 'file://' . $file['tmp_name'],
                            'name' => $file['name'],
                            'mimeType' => $file['type'],
                        ]];
                    } else {
                        // For non-image types, still make the file available
                        $data['attachments'] = [[
                            'path' => 'file://' . $file['tmp_name'],
                            'name' => $file['name'],
                            'mimeType' => $file['type'],
                        ]];
                    }
                }
            } elseif (!empty($_POST)) {
                // Some content-types still get auto-parsed
                $data = $_POST;
            } else {
                // Fallback: try form-encoded
                parse_str($body, $parsed);
                if (!empty($parsed)) {
                    $data = $parsed;
                }
            }
        }

        if (empty($data) || !is_array($data)) {
            // Fall back to $_GET — HA's rest_command sends payload as query params
            // when the request body is empty.
            if (!empty($_GET) && is_array($_GET)) {
                $data = $_GET;
            }
        }

        if (empty($data) || !is_array($data)) {
            $this->logger->warning('NCbotwebhooks: invalid payload from apprise webhook', [
                'app' => 'nc_bot_webhooks',
                'room_token' => $roomToken,
                'content_type' => $contentType,
                'body_length' => strlen($body),
                'body_preview' => substr($body, 0, 1000),
                'json_error' => $jsonOk ? null : json_last_error_msg(),
                'post_empty' => empty($_POST) ? true : null,
                'get_empty' => empty($_GET) ? true : null,
            ]);
            return new DataResponse(
                ['error' => 'Invalid payload'],
                Http::STATUS_BAD_REQUEST,
                ['X-Webhook-Status' => 'bad_request'],
            );
        }

        // Apprise API wraps notifications in a "notifications" array
        if (isset($data['notifications']) && is_array($data['notifications']) && !empty($data['notifications'])) {
            $wrapper = $data;
            $data = $data['notifications'][0];
            // Some Apprise versions put subject/title at the wrapper level
            if (empty($data['subject']) && !empty($wrapper['subject'])) {
                $data['subject'] = $wrapper['subject'];
            }
            if (empty($data['title']) && !empty($wrapper['title'])) {
                $data['title'] = $wrapper['title'];
            }
        }

        // Unwrap nested "data" key — HA's REST notify platform wraps the actual payload under data:
        // {"message": "...", "data": {"body": "...", "subject": "...", "attachments": [...]}}
        if (empty($data['body']) && isset($data['data']) && is_array($data['data'])) {
            if (!empty($data['data']['body'])) {
                $data['body'] = $data['data']['body'];
            } elseif (!empty($data['data']['message'])) {
                $data['body'] = $data['data']['message'];
            }
            if (empty($data['subject']) && !empty($data['data']['subject'])) {
                $data['subject'] = $data['data']['subject'];
            }
            if (empty($data['title']) && !empty($data['data']['title'])) {
                $data['title'] = $data['data']['title'];
            }
            if (empty($data['type']) && !empty($data['data']['type'])) {
                $data['type'] = $data['data']['type'];
            }
            if (empty($data['attachments']) && !empty($data['data']['attachments'])) {
                $data['attachments'] = $data['data']['attachments'];
            }
        }

        // Fallback: use "message" key as body if no body found (HA REST notify sends `message` not `body`)
        if (empty($data['body']) && !empty($data['message'])) {
            $data['body'] = $data['message'];
        }

        // Unwrap sender_name from data key if present
        if (empty($data['sender_name']) && isset($data['data']) && is_array($data['data']) && !empty($data['data']['sender_name'])) {
            $data['sender_name'] = $data['data']['sender_name'];
        }

        // Fallback: if no subject/title anywhere, use config default
        if (empty($data['subject']) && empty($data['title'])) {
            $data['subject'] = $this->talkService->getSenderNameDefault();
        }

        // Map apprise format to our internal payload format
        // Wrap in try-catch: image download/upload can throw exceptions that would
        // otherwise crash the entire webhook response
        $mapped = null;
        $imageError = null;
        try {
            $mapped = $this->talkService->mapApprisePayload($data, $roomToken);
        } catch (\Exception $e) {
            $imageError = $e->getMessage();
            $this->logger->error('NCbotwebhooks: image processing failed, continuing without images', [
                'app' => 'nc_bot_webhooks',
                'room_token' => $roomToken,
                'error' => $e->getMessage(),
            ]);
        }

        if ($mapped === null) {
            // Fallback: map without image processing
            $mapped = [
                'message' => $data['body'] ?? $data['message'] ?? '',
                'senderName' => $data['sender_name'] ?? $data['title'] ?? $data['subject'] ?? $this->talkService->getSenderNameDefault(),
                'displayName' => $data['sender_name'] ?? $data['title'] ?? $data['subject'] ?? $this->talkService->getSenderNameDefault(),
                'richObjects' => [],
            ];
        }

        // DEBUG: log mapped values
        $this->logger->info('NCbotwebhooks: DEBUG mapped', [
            'app' => 'nc_bot_webhooks',
            'message' => $mapped['message'] ?? 'EMPTY',
            'senderName' => $mapped['senderName'] ?? 'EMPTY',
            'displayName' => $mapped['displayName'] ?? 'EMPTY',
            'richObjects' => count($mapped['richObjects'] ?? []),
            'imageError' => $imageError ?? 'none',
        ]);

        // Allow empty message for image-only notifications (type=image)
        if ((empty($mapped['message']) || $mapped['message'] === '') && empty($mapped['richObjects'])) {
            return new DataResponse(
                ['error' => 'No message content'],
                Http::STATUS_BAD_REQUEST,
                ['X-Webhook-Status' => 'no_content'],
            );
        }

        $senderName = $mapped['senderName'] ?? $this->talkService->getSenderNameDefault();
        $richObjects = $mapped['richObjects'] ?? [];

        // Prepend display name since Talk doesn't support per-message avatars
        $displayName = $mapped['displayName'] ?? $senderName;
        $message = $this->talkService->prependDisplayName($displayName, $mapped['message']);

        // Post to Talk via Chat API
        $success = $this->talkService->postToRoom($roomToken, $message, $senderName, $richObjects);

        if ($success) {
            $this->logger->info('NCbotwebhooks: apprise webhook processed successfully', [
                'app' => 'nc_bot_webhooks',
                'room_token' => $roomToken,
            ]);
            return new DataResponse(
                ['status' => 'ok'],
                Http::STATUS_CREATED,
                ['X-Webhook-Status' => 'ok'],
            );
        }

        $this->logger->error('NCbotwebhooks: failed to post apprise message to Talk', [
            'app' => 'nc_bot_webhooks',
            'room_token' => $roomToken,
        ]);
        return new DataResponse(
            ['error' => 'Failed to post to Talk'],
            Http::STATUS_INTERNAL_SERVER_ERROR,
            ['X-Webhook-Status' => 'error'],
        );
    }

    /**
     * Receive Apprise webhook payload for a room.
     *
     * Handles Apprise's notify URL format: /apprise-webhook/{roomToken}/notify/{token}
     * Apprise's apprise:// URL scheme inserts 'notify' in the path.
     * Delegates to receiveApprise() for processing.
     *
     * URL: POST /apps/nc_bot_webhooks/apprise-webhook/{roomToken}/notify/{token}
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function receiveAppriseNotify(string $roomToken, string $token): DataResponse {
        return $this->receiveApprise($roomToken, $token);
    }

    /**
     * Save bot password from the settings UI.
     *
     * URL: POST /apps/nc_bot_webhooks/save-bot-password
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function saveBotPassword(): DataResponse {
        $body = file_get_contents('php://input');
        $data = @json_decode($body, true);
        if (!is_array($data) || !isset($data['bot_password'])) {
            return new DataResponse(
                ['error' => 'Missing bot_password field'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $validation = $this->talkService->validateBotPassword($data['bot_password']);
        if (!$validation['valid']) {
            return new DataResponse(
                ['error' => $validation['error']],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $this->talkService->setBotPassword($data['bot_password']);

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * Save configuration from the settings UI.
     *
     * URL: POST /apps/nc_bot_webhooks/save-config
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function saveConfig(): DataResponse {
        $body = file_get_contents('php://input');
        $config = @json_decode($body, true);
        if (!is_array($config)) {
            return new DataResponse(
                ['error' => 'Invalid config'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $this->talkService->saveConfig($config);
        } catch (\Exception $e) {
            $this->logger->error('NCbotwebhooks: saveConfig failed: ' . $e->getMessage(), [
                'app' => 'nc_bot_webhooks',
                'exception' => (string)$e,
            ]);
            return new DataResponse(
                ['error' => 'Save failed: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }

        return new DataResponse([
            'status' => 'ok',
            'auth_tokens' => $this->talkService->getAuthTokens(),
        ]);
    }

    /**
     * Debug endpoint to verify the app is working.
     *
     * URL: GET /apps/nc_bot_webhooks/debug
     * Query params:
     *   - webhook_url: Full webhook URL to diagnose (e.g. /apps/nc_bot_webhooks/discord-webhook/{room}/{token})
     *   - test_post=1: Actually POST a test message to the room
     *
     * This endpoint is disabled by default. Enable it via:
     *   php occ nc_bot_webhooks:debug:enable
     *
     * SECURITY: Never leave debug enabled in production. It exposes internal
     * configuration, database schema, and bot credentials.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function debug(): DataResponse {
        // Debug endpoint must be explicitly enabled via OCC command
        $debugEnabled = $this->appConfig->getValueBool('nc_bot_webhooks', 'debug_enabled', false);
        if (!$debugEnabled) {
            return new DataResponse(
                ['error' => 'Debug endpoint is disabled. Enable it with: php occ nc_bot_webhooks:debug:enable'],
                Http::STATUS_FORBIDDEN,
            );
        }

        $user = $this->userSession->getUser();
        $uid = $user !== null ? $user->getUID() : null;
        $isAdmin = $user !== null && $this->groupManager->isAdmin($uid);

        $info = [
            'app_enabled' => $this->appManager->isInstalled('nc_bot_webhooks'),
            'user' => $uid,
            'user_is_admin' => $isAdmin,
            'bot_user' => null,
            'has_bot_password' => false,
            'debug_enabled' => true,
        ];

        try {
            $bot = $this->talkService->getBotUser();
            $info['bot_user'] = $bot ? $bot->getUID() : null;
            $info['has_bot_password'] = $this->talkService->hasBotPassword();
        } catch (\Exception $e) {
            $info['bot_error'] = $e->getMessage();
        }

        // System config values
        try {
            $info['system_config'] = [
                'trusted_domains' => $this->config->getSystemValue('trusted_domains', []),
                'overwrite.cli.url' => $this->config->getSystemValueString('overwrite.cli.url', ''),
                'overwritewebroot' => $this->config->getSystemValueString('overwritewebroot', ''),
                'dbtableprefix' => $this->config->getSystemValueString('dbtableprefix', ''),
            ];
        } catch (\Exception $e) {
            $info['system_config_error'] = $e->getMessage();
        }

        // AppConfig values (iterate known keys to avoid lazy AppConfig RuntimeException in NC33)
        try {
            $knownKeys = ['bot_password', 'rooms', 'auth_tokens', 'retention_days', 'sender_name'];
            $appConfigKeys = [];
            foreach ($knownKeys as $key) {
                $val = $this->appConfig->getValueString('nc_bot_webhooks', $key, '');
                $appConfigKeys[$key] = $val !== '' ? '[set]' : '[empty]';
            }
            $info['app_config_keys'] = $appConfigKeys;
            $info['app_config_room_count'] = count($this->talkService->getRooms());
            $info['app_config_auth_token_count'] = count($this->talkService->getAuthTokens());
            $info['app_config_bot_password_set'] = $this->talkService->hasBotPassword();
        } catch (\Exception $e) {
            $info['app_config_error'] = $e->getMessage();
        }

        // Talk table schema and sample data
        try {
            $talkService = $this->talkService;

            // Resolve database prefix and table name
            $sysPrefix = $this->config->getSystemValueString('dbtableprefix', '');
            $talkPrefix = $this->config->getAppValue('spreed', 'databaseprefix', $sysPrefix);
            $info['database'] = [
                'system_prefix' => $sysPrefix,
                'talk_prefix' => $talkPrefix,
                'talk_prefix_differs' => $talkPrefix !== $sysPrefix,
            ];

            $roomTable = $talkService->detectTalkTableFromCatalog('talk_rooms', 'spreed_room');
            $info['talk_room_table'] = $roomTable;

            if ($roomTable !== null) {
                // Column schema
                try {
                    $columns = $talkService->getTalkTableColumns($roomTable);
                    $info['room_table_columns'] = $columns;
                } catch (\Exception $e) {
                    $info['room_table_columns_error'] = $e->getMessage();
                }

                // Sample rows (all columns)
                try {
                    $sampleRows = $talkService->getTalkTableSample($roomTable, 100);
                    $info['room_table_sample'] = $sampleRows;
                } catch (\Exception $e) {
                    $info['room_table_sample_error'] = $e->getMessage();
                }

                // Room type breakdown
                try {
                    $typeCounts = $talkService->getRoomTypeBreakdown();
                    $info['room_type_breakdown'] = $typeCounts;
                } catch (\Exception $e) {
                    $info['room_type_breakdown_error'] = $e->getMessage();
                }

                // All rooms with type and name for debugging
                try {
                    $allRooms = $talkService->getAllTalkRoomsDebug(100);
                    $info['all_talk_rooms_debug'] = $allRooms;
                } catch (\Exception $e) {
                    $info['all_talk_rooms_debug_error'] = $e->getMessage();
                }

                // Rooms that match our filter (type IN 1,2,3, not sample, not note_to_self, not files)
                try {
                    $filteredRooms = $talkService->getAvailableTalkRooms();
                    $info['filtered_rooms'] = $filteredRooms;
                } catch (\Exception $e) {
                    $info['filtered_rooms_error'] = $e->getMessage();
                }

                // The raw SQL that getAvailableTalkRooms executes
                $info['filtered_rooms_sql'] = 'SELECT token, COALESCE(NULLIF(name, \'\'), token) as display_name
                    FROM "' . $roomTable . '"
                    WHERE type IN (1, 2, 3)
                      AND (object_type IS NULL OR object_type != \'sample\')
                      AND object_type != \'note_to_self\'
                      AND object_type != \'file\'';
            } else {
                $info['talk_debug_error'] = 'Talk table not found in database catalog';
            }
        } catch (\Exception $e) {
            $info['talk_debug_error'] = $e->getMessage();
            $info['talk_debug_error_file'] = $e->getFile();
            $info['talk_debug_error_line'] = $e->getLine();
            $info['talk_debug_error_trace'] = substr($e->getTraceAsString(), 0, 2000);
        }

        // ── Webhook URL debug ─────────────────────────────────────────
        // Parse webhook URL if provided via query param
        $webhookUrl = $this->request->getParam('webhook_url', '');
        $doTestPost = (bool) $this->request->getParam('test_post', 0);
        if ($webhookUrl !== '') {
            $info['webhook_debug'] = $this->debugWebhookUrl($webhookUrl);
            if ($doTestPost) {
                $info['test_post'] = $this->runTestPost($webhookUrl);
            }
        }

        return new DataResponse($info);
    }

    /**
     * Debug a webhook URL step by step.
     */
    private function debugWebhookUrl(string $webhookUrl): array {
        $result = [];

        // 1. Parse the URL
        $parsed = parse_url($webhookUrl);
        $result['parsed_url'] = $parsed;

        $path = $parsed['path'] ?? '';
        $segments = explode('/', trim($path, '/'));

        // Expected: apps/nc_bot_webhooks/discord-webhook/{roomToken}/{token}
        if (count($segments) >= 5 && $segments[0] === 'apps' && $segments[1] === 'nc_bot_webhooks' && $segments[2] === 'discord-webhook') {
            $roomToken = $segments[3];
            $token = $segments[4];
            $result['room_token'] = $roomToken;
            $result['token'] = $token;
            $result['url_valid'] = true;

            // 2. Validate auth token
            $valid = $this->talkService->validateAuthToken($roomToken, $token);
            $result['auth_token_valid'] = $valid;

            // 3. Check if room is configured
            $configured = $this->talkService->isBotEnabledForRoom($roomToken);
            $result['room_configured'] = $configured;

            // 4. Check bot user
            try {
                $bot = $this->talkService->getBotUser();
                $result['bot_user_exists'] = $bot !== null;
                $result['bot_uid'] = $bot !== null ? $bot->getUID() : null;
            } catch (\Exception $e) {
                $result['bot_user_error'] = $e->getMessage();
            }

            // 5. Check bot password
            $result['has_bot_password'] = $this->talkService->hasBotPassword();
            try {
                $pw = $this->talkService->getBotPassword();
                $result['bot_password_length'] = $pw !== null ? strlen($pw) : null;
                $result['bot_password_starts_with'] = $pw !== null ? substr($pw, 0, 4) . '...' : null;
            } catch (\Exception $e) {
                $result['bot_password_error'] = $e->getMessage();
            }

            // 6. Check base URL
            $baseUrl = $this->talkService->getBaseUrl();
            $result['base_url'] = $baseUrl;
            $result['base_url_scheme'] = parse_url($baseUrl, PHP_URL_SCHEME) ?? null;
            $result['base_url_host'] = parse_url($baseUrl, PHP_URL_HOST) ?? null;

            // 7. Check if room exists in DB
            try {
                $sysPrefix = $this->config->getSystemValueString('dbtableprefix', '');
                $talkPrefix = $this->config->getAppValue('spreed', 'databaseprefix', $sysPrefix);
                $roomTable = $this->talkService->detectTalkTableFromCatalog('talk_rooms', 'spreed_room');
                $result['room_table'] = $roomTable;

                if ($roomTable !== null) {
                    $stmt = $this->talkService->getDbConnection()->executeQuery(
                        'SELECT token, type, name, object_type, object_id
                         FROM "' . $roomTable . '" WHERE token = ?',
                        [$roomToken],
                    );
                    $row = $stmt->fetch();
                    $stmt->closeCursor();

                    $result['room_found_in_db'] = $row !== false;
                    if ($row !== false) {
                        $result['room_type'] = (int)$row['type'];
                        // readable_name may not exist in all Talk versions
                        $result['room_readable_name'] = isset($row['readable_name']) ? ($row['readable_name'] ?: null) : null;
                        $result['room_name'] = $row['name'] ?: null;
                        $result['room_object_type'] = $row['object_type'] ?: null;
                        $result['room_object_id'] = $row['object_id'] ?: null;

                        // 8. Check room name doesn't look like a DM JSON
                        if (is_string($row['name']) && str_starts_with($row['name'], '["')) {
                            $result['room_name_is_json_dm'] = true;
                            $result['room_name_is_json_dm_warning'] = 'Room name looks like a DM JSON array — this room type is normally filtered out but is explicitly configured.';
                        }
                    }
                }
            } catch (\Exception $e) {
                $result['db_check_error'] = $e->getMessage();
            }

            // 9. Construct Chat API endpoint (v1 for Talk 19 / NC33)
            $chatEndpoint = $baseUrl . '/ocs/v2.php/apps/spreed/api/v1/chat/' . $roomToken;
            $result['chat_api_endpoint'] = $chatEndpoint;

            // 10. Construct Basic auth header (without exposing full password)
            try {
                $pw = $this->talkService->getBotPassword();
                if ($pw !== null) {
                    $b64 = base64_encode('talk-bot:' . $pw);
                    $result['basic_auth_header_prefix'] = 'Basic ' . substr($b64, 0, 20) . '...';
                    $result['basic_auth_header_length'] = strlen($b64);
                }
            } catch (\Exception $e) {
                $result['basic_auth_error'] = $e->getMessage();
            }

            // 11. Test HTTP connectivity to the Chat API endpoint
            try {
                $client = $this->clientService->newClient();
                $response = $client->get($chatEndpoint, [
                    'headers' => [
                        'OCS-Expect-Formatted' => 'json',
                        'Authorization' => 'Basic ' . base64_encode('talk-bot:' . ($this->talkService->getBotPassword() ?? 'ERROR')),
                    ],
                    'nextcloud' => [
                        'allow_local_address' => true,
                    ],
                ]);
                $result['http_test_status'] = $response->getStatusCode();
                $result['http_test_body'] = $response->getBody();
            } catch (\Guzzle\Exception\ConnectException $e) {
                $result['http_test_error'] = 'Connection failed: ' . $e->getMessage();
                $result['http_test_error_type'] = 'connection';
            } catch (\Guzzle\Exception\ServerException $e) {
                $result['http_test_status'] = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
                $result['http_test_body'] = $e->getResponse() ? $e->getResponse()->getBody() : null;
                $result['http_test_error'] = 'Server error: ' . $e->getMessage();
                $result['http_test_error_type'] = 'server';
            } catch (\Guzzle\Exception\ClientException $e) {
                $result['http_test_status'] = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
                $result['http_test_body'] = $e->getResponse() ? $e->getResponse()->getBody() : null;
                $result['http_test_error'] = 'Client error: ' . $e->getMessage();
                $result['http_test_error_type'] = 'client';
            } catch (\Exception $e) {
                $result['http_test_error'] = $e->getMessage();
                $result['http_test_error_type'] = 'other';
            }

            // 12. Summary of issues
            $issues = [];
            if (!$result['auth_token_valid'] ?? false === false) {
                $issues[] = 'Auth token is INVALID for this room';
            }
            if (!($result['room_configured'] ?? false)) {
                $issues[] = 'Room is NOT configured (bot not enabled)';
            }
            if (!($result['bot_user_exists'] ?? false)) {
                $issues[] = 'talk-bot user does NOT exist';
            }
            if (!($result['has_bot_password'] ?? false)) {
                $issues[] = 'Bot password is NOT configured';
            }
            if (($result['room_found_in_db'] ?? false) === false) {
                $issues[] = 'Room NOT found in Talk database';
            }
            if (($result['http_test_status'] ?? null) !== null && ($result['http_test_status'] ?? 0) >= 400) {
                $issues[] = 'Chat API returned HTTP ' . ($result['http_test_status'] ?? 'unknown');
            }
            if (isset($result['http_test_error'])) {
                $issues[] = 'HTTP test failed: ' . $result['http_test_error'];
            }
            $result['issues'] = $issues;
            $result['summary'] = empty($issues) ? 'All checks passed — if webhook still fails, check Talk server logs' : implode('; ', $issues);

        } else {
            $result['url_valid'] = false;
            $result['parse_error'] = 'URL does not match expected pattern: /apps/nc_bot_webhooks/discord-webhook/{roomToken}/{token}';
        }

        return $result;
    }

    /**
     * Run a test post to simulate a full webhook delivery.
     */
    private function runTestPost(string $webhookUrl): array {
        $result = [];

        // Parse URL to get roomToken and token
        $parsed = parse_url($webhookUrl);
        $path = $parsed['path'] ?? '';
        $segments = explode('/', trim($path, '/'));

        if (!(count($segments) >= 5 && $segments[0] === 'apps' && $segments[1] === 'nc_bot_webhooks' && $segments[2] === 'discord-webhook')) {
            return ['error' => 'Invalid webhook URL format'];
        }

        $roomToken = $segments[3];
        $token = $segments[4];
        $result['room_token'] = $roomToken;
        $result['token'] = $token;

        // Step 1: Validate auth token
        $authValid = $this->talkService->validateAuthToken($roomToken, $token);
        $result['step_1_auth_token'] = $authValid ? 'OK' : 'FAIL — token not found for this room';
        if (!$authValid) {
            $result['steps'] = $result;
            return $result;
        }

        // Step 2: Build a realistic Discord-style test payload
        $testPayload = [
            'content' => '🔧 **Test message from nc_bot_webhooks debug**\n\nThis is a test message sent via the debug endpoint. If you see this, the webhook pipeline is working!',
            'embeds' => [
                [
                    'title' => 'Webhook Test',
                    'description' => 'Sent at ' . date('Y-m-d H:i:s T'),
                    'color' => 3066993,
                    'footer' => [
                        'text' => 'nc_bot_webhooks debug endpoint',
                    ],
                ],
            ],
            'username' => 'Debug Test',
            'avatar_url' => null,
        ];

        // Step 3: Map payload to message
        $message = $this->talkService->mapPayload($testPayload);
        $result['step_2_map_payload'] = $message !== '' ? 'OK — "' . substr($message, 0, 100) . '..." (' . strlen($message) . ' chars)' : 'FAIL — empty message';
        if ($message === '') {
            return $result;
        }

        // Step 4: Get sender name
        $senderName = $this->talkService->getSenderName($testPayload);
        $result['step_3_sender_name'] = $senderName;

        // Step 5: Check bot password
        $botPassword = $this->talkService->getBotPassword();
        $result['step_4_bot_password'] = $botPassword !== null ? 'OK (' . strlen($botPassword) . ' chars)' : 'FAIL — not configured';
        if ($botPassword === null) {
            return $result;
        }

        // Step 6: Check bot enabled for room
        $botEnabled = $this->talkService->isBotEnabledForRoom($roomToken);
        $result['step_5_bot_enabled'] = $botEnabled ? 'OK' : 'FAIL — room not enabled in config';
        if (!$botEnabled) {
            return $result;
        }

        // Step 7: Get base URL
        $baseUrl = $this->talkService->getBaseUrl();
        $result['step_6_base_url'] = $baseUrl !== '' ? $baseUrl : 'FAIL — not configured';
        if ($baseUrl === '') {
            return $result;
        }

        // Step 8: Build the Chat API request (v1 for Talk 19 / NC33)
        $endpoint = $baseUrl . '/ocs/v2.php/apps/spreed/api/v1/chat/' . $roomToken;
        $result['step_7_endpoint'] = $endpoint;

        // Step 9: Try the actual Chat API POST
        try {
            $client = $this->clientService->newClient();
            $response = $client->post($endpoint, [
                'body' => json_encode([
                    'message' => $message,
                    'actorType' => 'users',
                    'actorId' => 'talk-bot',
                    'actorDisplayName' => $senderName,
                ]),
                'headers' => [
                    'OCS-Expect-Formatted' => 'json',
                    'OCS-APIRequest' => '1',
                    'Authorization' => 'Basic ' . base64_encode('talk-bot:' . $botPassword),
                    'Content-Type' => 'application/json',
                ],
                'nextcloud' => [
                    'allow_local_address' => true,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody();
            $result['step_8_http_status'] = $statusCode;
            $result['step_8_http_body'] = $body;

            if ($statusCode === 200) {
                $result['step_8_result'] = 'SUCCESS — message posted to Talk!';
                $result['overall'] = 'Webhook pipeline is WORKING. If messages still fail from Discord, check your Discord webhook configuration.';
            } else {
                $result['step_8_result'] = 'FAIL — HTTP ' . $statusCode;

                // Parse OCS error if possible
                if (strpos($body, 'ocs') !== false) {
                    $parsedBody = @json_decode($body, true);
                    if (is_array($parsedBody) && isset($parsedBody['ocs']['meta']['message'])) {
                        $result['step_8_error_message'] = $parsedBody['ocs']['meta']['message'];
                    }
                }
            }
        } catch (\Guzzle\Exception\ConnectException $e) {
            $result['step_8_result'] = 'FAIL — Connection error';
            $result['step_8_error'] = $e->getMessage();
        } catch (\Guzzle\Exception\ServerException $e) {
            $result['step_8_result'] = 'FAIL — Server error (5xx)';
            $result['step_8_error'] = $e->getMessage();
            if ($e->getResponse()) {
                $result['step_8_status'] = $e->getResponse()->getStatusCode();
                $result['step_8_body'] = $e->getResponse()->getBody();
            }
        } catch (\Guzzle\Exception\ClientException $e) {
            $result['step_8_result'] = 'FAIL — Client error (4xx)';
            $result['step_8_error'] = $e->getMessage();
            if ($e->getResponse()) {
                $result['step_8_status'] = $e->getResponse()->getStatusCode();
                $result['step_8_body'] = $e->getResponse()->getBody();
            }
        } catch (\Exception $e) {
            $result['step_8_result'] = 'FAIL — Unexpected error';
            $result['step_8_error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Get available Talk rooms.
     *
     * URL: GET /apps/nc_bot_webhooks/rooms
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function getRooms(): DataResponse {
        try {
            $rooms = $this->talkService->getAvailableTalkRooms();
            if (!is_array($rooms)) {
                $rooms = [];
            }
            $configured = $this->talkService->getRooms();

            // Also fetch type/object_type for each room from the DB
            $roomTable = $this->talkService->detectTalkTableFromCatalog('talk_rooms', 'spreed_room');
            $typeMap = [];
            $objectTypeMap = [];
            if ($roomTable !== null) {
                $sql = 'SELECT token, type, object_type
                        FROM "' . $roomTable . '"
                        WHERE type IN (1, 2, 3)
                          AND (object_type IS NULL OR object_type != \'sample\')
                          AND object_type != \'note_to_self\'
                          AND object_type != \'file\'
                          AND name NOT LIKE \'["%\'';
                $result = $this->talkService->getDbConnection()->executeQuery($sql);
                while ($row = $result->fetch()) {
                    $typeMap[$row['token']] = (int)$row['type'];
                    $objectTypeMap[$row['token']] = $row['object_type'] ?: null;
                }
                $result->closeCursor();
            }

            // Type labels for reference
            $typeLabels = [1 => 'public', 2 => 'private', 3 => 'password'];

            // Mark which rooms are configured
            $result = [];
            foreach ($rooms as $token => $name) {
                $result[] = [
                    'token' => $token,
                    'name' => $name !== '' ? $name : $token,
                    'configured' => isset($configured[$token]),
                    'type' => $typeMap[$token] ?? null,
                    'type_label' => $typeLabels[$typeMap[$token] ?? 0] ?? 'unknown',
                    'object_type' => $objectTypeMap[$token] ?? null,
                ];
            }

            return new DataResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('NCbotwebhooks getRooms failed: ' . $e->getMessage(), ['app' => 'nc_bot_webhooks', 'exception' => (string)$e]);
            return new DataResponse(
                ['error' => 'Server error: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
