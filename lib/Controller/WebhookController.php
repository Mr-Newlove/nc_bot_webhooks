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
use OCP\IRequest;
use OCP\IUserSession;

class WebhookController extends Controller {
    private TalkService $talkService;

    public function __construct(IRequest $request, TalkService $talkService) {
        parent::__construct('ncdiscordhook', $request);
        $this->talkService = $talkService;
    }

    /**
     * Receive Discord webhook payload for a room.
     *
     * URL: POST /apps/ncdiscordhook/webhook/{roomToken}/{authToken}
     */
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
        $body = $this->request->getContent();
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
                        $richObjects[] = $this->talkService->buildRichObject($filePath, $imageData['mimeType'], $roomToken);
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
     * Save configuration from the settings UI.
     *
     * URL: POST /apps/ncdiscordhook/save-config
     */
    @AdminRequired
    public function saveConfig(): DataResponse {
        $body = $this->request->getContent();
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
     * Get available Talk rooms.
     *
     * URL: GET /apps/ncdiscordhook/rooms
     */
    @AdminRequired
    public function getRooms(): DataResponse {
        $rooms = $this->talkService->getAvailableTalkRooms();
        $configured = $this->talkService->getRooms();

        // Mark which rooms are already configured
        $result = [];
        foreach ($rooms as $room) {
            $token = $room['token'] ?? $room['roomId'] ?? '';
            $name = $room['displayName'] ?? $room['name'] ?? '';
            $result[] = [
                'token' => $token,
                'name' => $name,
                'configured' => isset($configured[$token]),
            ];
        }

        return new DataResponse($result);
    }
}
