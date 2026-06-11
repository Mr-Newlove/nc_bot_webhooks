<?php

namespace OCA\NCdiscordhook\Controller;

use OCA\NCdiscordhook\Service\TalkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware\Security\CSRF\Token;
use OCP\AppFramework\Middleware\Security\CSRF\TokenStore;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\AppFramework\Controller\Attribute\AdminRequired;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\SubAdminRequired;

class WebhookController extends Controller {
    private TalkService $talkService;
    private LoggerInterface $logger;

    public function __construct(IRequest $request, TalkService $talkService, LoggerInterface $logger) {
        // Debug: write to file to bypass output buffering
        @file_put_contents('/tmp/ncdebug.txt', date('c') . " WebhookController constructed\n", FILE_APPEND);
        parent::__construct('ncdiscordhook', $request);
        $this->talkService = $talkService;
        $this->logger = $logger;
    }

    /**
     * Receive Discord webhook payload for a room.
     *
     * URL: POST /apps/ncdiscordhook/webhook/{roomToken}/{authToken}
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function receive(string $roomToken, string $authToken): DataResponse {
        // Validate auth token
        if (!$this->talkService->validateAuthToken($roomToken, $authToken)) {
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

        // Post to Talk
        $success = $this->talkService->postToRoom($roomToken, $message, $senderName, $richObjects);

        if ($success) {
            return new DataResponse(
                ['status' => 'ok'],
                Http::STATUS_CREATED,
                ['X-Webhook-Status' => 'ok'],
            );
        }

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

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * Debug endpoint to verify the app is working.
     *
     * URL: GET /apps/ncdiscordhook/debug
     */
    #[NoCSRFRequired]
    public function debug(): DataResponse {
        $info = [
            'app_enabled' => \OC_App::isEnabled('ncdiscordhook'),
            'user' => \OC_User::getUser(),
            'user_is_admin' => \OC_User::isAdminUser(\OC_User::getUser()),
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

        return new DataResponse($info);
    }

    /**
     * Diagnostic endpoint to discover Talk table names.
     *
     * URL: GET /apps/ncdiscordhook/debug-tables
     */
    #[NoCSRFRequired]
    public function debugTables(): DataResponse {
        $result = [];

        try {
            $conn = $this->talkService->getDbConnection();
            $pdo = $conn->getConnection()->getWrappedConnection();

            // List all tables that could be Talk-related
            $tables = $pdo->query("
                SELECT table_schema, table_name
                FROM information_schema.tables
                WHERE table_type = 'BASE TABLE'
                  AND table_schema NOT IN ('pg_catalog', 'information_schema')
                  AND (table_name LIKE 'talk%' OR table_name LIKE 'spreed%'
                       OR table_name LIKE '%room%' OR table_name LIKE '%attendee%'
                       OR table_name LIKE '%conversation%')
                ORDER BY table_schema, table_name
            ")->fetchAll();

            $result['tables'] = $tables;

            // Check Talk app config
            $talkPrefix = \OC::$server->get(\OCP\IConfig::class)->getAppValue('spreed', 'databaseprefix', '');
            $sysPrefix = \OC::$server->get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix', '');
            $result['sysPrefix'] = $sysPrefix;
            $result['talkPrefix'] = $talkPrefix;

            // Check each candidate with tableExists
            $candidates = [
                $talkPrefix . 'talk_rooms',
                $sysPrefix . 'talk_rooms',
                'talk_rooms',
                $talkPrefix . 'talk_attendees',
                $sysPrefix . 'talk_attendees',
                'talk_attendees',
            ];
            $result['tableExists_results'] = [];
            foreach ($candidates as $c) {
                $result['tableExists_results'][$c] = $conn->tableExists($c);
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return new DataResponse($result);
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

            // Mark which rooms are already configured
            $result = [];
            foreach ($rooms as $token => $name) {
                $result[] = [
                    'token' => $token,
                    'name' => $name !== '' ? $name : $token,
                    'configured' => isset($configured[$token]),
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
