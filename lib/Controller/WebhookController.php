<?php

namespace OCA\NCdiscordhook\Controller;

use OCA\NCdiscordhook\Service\TalkService;
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
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class WebhookController extends Controller {
    private TalkService $talkService;
    private LoggerInterface $logger;
    private IAppManager $appManager;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private IConfig $config;
    private IAppConfig $appConfig;

    public function __construct(IRequest $request, TalkService $talkService, LoggerInterface $logger, IAppManager $appManager, IUserSession $userSession, IGroupManager $groupManager, IConfig $config, IAppConfig $appConfig) {
        parent::__construct('ncdiscordhook', $request);
        $this->talkService = $talkService;
        $this->logger = $logger;
        $this->appManager = $appManager;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->config = $config;
        $this->appConfig = $appConfig;
    }

    /**
     * Receive Discord webhook payload for a room.
     *
     * URL: POST /apps/ncdiscordhook/bot-webhook/{roomToken}/{token}
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function receive(string $roomToken, string $token): DataResponse {
        // Validate auth token
        if (!$this->talkService->validateAuthToken($roomToken, $token)) {
            $this->logger->warning('NCdiscordhook: invalid auth token for room', [
                'app' => 'ncdiscordhook',
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
            $this->logger->warning('NCdiscordhook: invalid JSON from webhook', [
                'app' => 'ncdiscordhook',
                'room_token' => $roomToken,
            ]);
            return new DataResponse(
                ['error' => 'Invalid JSON'],
                Http::STATUS_BAD_REQUEST,
                ['X-Webhook-Status' => 'bad_request'],
            );
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

        // Handle images
        $richObjects = [];
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

        // Post to Talk via Chat API
        $success = $this->talkService->postToRoom($roomToken, $message, $senderName, $richObjects);

        if ($success) {
            $this->logger->info('NCdiscordhook: webhook processed successfully', [
                'app' => 'ncdiscordhook',
                'room_token' => $roomToken,
            ]);
            return new DataResponse(
                ['status' => 'ok'],
                Http::STATUS_CREATED,
                ['X-Webhook-Status' => 'ok'],
            );
        }

        $this->logger->error('NCdiscordhook: failed to post webhook message to Talk', [
            'app' => 'ncdiscordhook',
            'room_token' => $roomToken,
        ]);
        return new DataResponse(
            ['error' => 'Failed to post to Talk'],
            Http::STATUS_INTERNAL_SERVER_ERROR,
            ['X-Webhook-Status' => 'error'],
        );
    }

    /**
     * Save bot password from the settings UI.
     *
     * URL: POST /apps/ncdiscordhook/save-bot-password
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function saveBotPassword(): DataResponse {
        $body = file_get_contents('php://input');
        $data = @json_decode($body, true);
        if (!is_array($data) || empty($data['bot_password'])) {
            return new DataResponse(
                ['error' => 'Invalid data'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $this->talkService->setBotPassword($data['bot_password']);

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * Save configuration from the settings UI.
     *
     * URL: POST /apps/ncdiscordhook/save-config
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

        $this->talkService->saveConfig($config);

        return new DataResponse([
            'status' => 'ok',
            'auth_tokens' => $this->talkService->getAuthTokens(),
        ]);
    }

    /**
     * Debug endpoint to verify the app is working.
     *
     * URL: GET /apps/ncdiscordhook/debug
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function debug(): DataResponse {
        $user = $this->userSession->getUser();
        $uid = $user !== null ? $user->getUID() : null;
        $isAdmin = $user !== null && $this->groupManager->isAdmin($uid);

        $info = [
            'app_enabled' => $this->appManager->isInstalled('ncdiscordhook'),
            'user' => $uid,
            'user_is_admin' => $isAdmin,
            'bot_user' => null,
            'has_bot_password' => false,
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

        // AppConfig values
        try {
            $appConfig = $this->appConfig->getAllValues('ncdiscordhook');
            $info['app_config_keys'] = $appConfig;
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

        return new DataResponse($info);
    }

    /**
     * Get available Talk rooms.
     *
     * URL: GET /apps/ncdiscordhook/rooms
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
            $this->logger->error('NCdiscordhook getRooms failed: ' . $e->getMessage(), ['app' => 'ncdiscordhook', 'exception' => (string)$e]);
            return new DataResponse(
                ['error' => 'Server error: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
