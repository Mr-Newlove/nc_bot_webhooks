<?php

namespace OCA\NCdiscordhook\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Security\Crypto\DefaultCrypto;
use OCP\IURLGenerator;
use OCP\IUserManager;

class TalkService {
    private const APP_ID = 'ncdiscordhook';
    private const IMAGES_DIR = 'NCdiscordhook-images';

    private IClient $client;
    private IConfig $config;
    private IRootFolder $rootFolder;
    private DefaultCrypto $crypto;
    private IURLGenerator $urlGenerator;
    private IUserManager $userManager;
    private IRequest $request;

    public function __construct(
        IClientService $clientService,
        IConfig $config,
        IRootFolder $rootFolder,
        DefaultCrypto $crypto,
        IURLGenerator $urlGenerator,
        IUserManager $userManager,
        IRequest $request,
    ) {
        $this->client = $clientService->newClient();
        $this->config = $config;
        $this->rootFolder = $rootFolder;
        $this->crypto = $crypto;
        $this->urlGenerator = $urlGenerator;
        $this->userManager = $userManager;
        $this->request = $request;
    }

    // ── Bot password ──────────────────────────────────────────────

    public function getBotPassword(): ?string {
        $encrypted = $this->config->getAppValue(self::APP_ID, 'bot_password', '');
        if ($encrypted === '') {
            return null;
        }
        try {
            return $this->crypto->decrypt($encrypted);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function setBotPassword(string $password): void {
        $this->config->setAppValue(self::APP_ID, 'bot_password', $this->crypto->encrypt($password));
    }

    public function hasBotPassword(): bool {
        return $this->getBotPassword() !== null;
    }

    public function getBotUser(): ?\OCP\IUser {
        return $this->userManager->get('talk-bot');
    }

    // ── Retention ─────────────────────────────────────────────────

    public function getRetentionDays(): int {
        return (int) $this->config->getAppValue(self::APP_ID, 'retention_days', '90');
    }

    public function setRetentionDays(int $days): void {
        $this->config->setAppValue(self::APP_ID, 'retention_days', (string) $days);
    }

    // ── Rooms ─────────────────────────────────────────────────────

    /**
     * Get configured rooms: room token → display name
     */
    public function getRooms(): array {
        $json = $this->config->getAppValue(self::APP_ID, 'rooms', '[]');
        $rooms = json_decode($json, true);
        return is_array($rooms) ? $rooms : [];
    }

    /**
     * Save configured rooms: room token → display name
     */
    public function setRooms(array $rooms): void {
        $this->config->setAppValue(self::APP_ID, 'rooms', json_encode($rooms));
    }

    /**
     * Get available Talk rooms via API.
     */
    public function getAvailableTalkRooms(): array {
        $baseUrl = rtrim($this->config->getSystemValueString('overwritewebroot', ''), '/');
        $url = $baseUrl . '/ocs/v2.php/apps/spreed/api/v1/room';

        try {
            $response = $this->client->get($url, [
                'auth' => 'basic',
                'basic' => [
                    $this->config->getSystemValueString('adminuser', 'admin'),
                    $this->config->getSystemValueString('adminpass', ''),
                ],
                'headers' => [
                    'OCS-Expect' => '100',
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            if (isset($data['ocs']['data'])) {
                return $data['ocs']['data'];
            }
        } catch (\Exception $e) {
            // Log but don't fail — rooms list is optional
        }

        return [];
    }

    // ── Auth tokens ───────────────────────────────────────────────

    /**
     * Get auth tokens for a room: room token → [token1, token2, ...]
     */
    public function getAuthTokens(): array {
        $json = $this->config->getAppValue(self::APP_ID, 'auth_tokens', '{}');
        $tokens = json_decode($json, true);
        return is_array($tokens) ? $tokens : [];
    }

    /**
     * Save auth tokens for a room.
     */
    public function setAuthTokens(array $tokens): void {
        $this->config->setAppValue(self::APP_ID, 'auth_tokens', json_encode($tokens));
    }

    /**
     * Validate auth token for a room.
     */
    public function validateAuthToken(string $roomToken, string $authToken): bool {
        $allTokens = $this->getAuthTokens();
        if (!isset($allTokens[$roomToken]) || !is_array($allTokens[$roomToken])) {
            return false;
        }
        return in_array($authToken, $allTokens[$roomToken], true);
    }

    /**
     * Generate a new auth token for a room.
     * Returns the new token.
     */
    public function generateAuthToken(string $roomToken): string {
        $token = bin2hex(random_bytes(24)); // 48-char hex token
        $allTokens = $this->getAuthTokens();
        if (!isset($allTokens[$roomToken])) {
            $allTokens[$roomToken] = [];
        }
        $allTokens[$roomToken][] = $token;
        $this->setAuthTokens($allTokens);
        return $token;
    }

    /**
     * Revoke a token from a room.
     */
    public function revokeAuthToken(string $roomToken, string $authToken): void {
        $allTokens = $this->getAuthTokens();
        if (!isset($allTokens[$roomToken]) || !is_array($allTokens[$roomToken])) {
            return;
        }
        $allTokens[$roomToken] = array_values(array_filter(
            $allTokens[$roomToken],
            fn($t) => $t !== $authToken
        ));
        if (empty($allTokens[$roomToken])) {
            unset($allTokens[$roomToken]);
        }
        $this->setAuthTokens($allTokens);
    }

    // ── Payload mapping ───────────────────────────────────────────

    /**
     * Map Discord webhook payload to Talk message text.
     */
    public function mapPayload(array $data): string {
        $parts = [];

        // Regular content
        if (!empty($data['content'])) {
            $parts[] = $data['content'];
        }

        // Embeds
        if (!empty($data['embeds']) && is_array($data['embeds'])) {
            foreach ($data['embeds'] as $embed) {
                if (!is_array($embed)) {
                    continue;
                }
                if (!empty($embed['title'])) {
                    $parts[] = '**' . $embed['title'] . '**';
                }
                if (!empty($embed['description'])) {
                    $parts[] = $embed['description'];
                }
                if (!empty($embed['fields']) && is_array($embed['fields'])) {
                    foreach ($embed['fields'] as $field) {
                        if (is_array($field) && !empty($field['name'])) {
                            $fieldText = $field['name'] . ': ';
                            if (!empty($field['value'])) {
                                $fieldText .= $field['value'];
                            }
                            $parts[] = $fieldText;
                        }
                    }
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Get sender name from payload or config default.
     */
    public function getSenderName(array $data): string {
        if (!empty($data['username'])) {
            return $data['username'];
        }
        return $this->config->getAppValue(self::APP_ID, 'sender_name', 'Webhook Bot');
    }

    /**
     * Set sender name default.
     */
    public function setSenderName(string $name): void {
        $this->config->setAppValue(self::APP_ID, 'sender_name', $name);
    }

    // ── Image handling ────────────────────────────────────────────

    /**
     * Download an image from a URL.
     * Returns ['data' => binary, 'mimeType' => string] or null on failure.
     */
    public function downloadImage(string $url): ?array {
        try {
            $response = $this->client->get($url, [
                'timeout' => 15,
                'nextcloud' => [
                    'allow_local_address' => false,
                ],
            ]);

            $mimeType = $response->getHeader('Content-Type') ?: 'image/png';
            $body = $response->getBody();

            if (strlen($body) === 0) {
                return null;
            }

            return ['data' => $body, 'mimeType' => $mimeType];
        } catch (\Exception $e) {
            \OC::$server->getLogger()->error('Failed to download image: ' . $url, ['app' => self::APP_ID]);
            return null;
        }
    }

    /**
     * Upload an image to the bot user's files.
     * Returns the file path (e.g. NCdiscordhook-images/roomToken/filename.png) or null on failure.
     */
    public function uploadImage(string $roomToken, string $filename, string $data, string $mimeType): ?string {
        $bot = $this->userManager->get('talk-bot');
        if (!$bot) {
            return null;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($bot->getUID());
            $imagesDir = $userFolder->getFolder(self::IMAGES_DIR);
            $roomDir = $imagesDir->getFolder($roomToken);

            // Avoid path traversal
            $safeFilename = basename($filename);
            $filePath = $roomDir->newFile($safeFilename, $data);

            return self::IMAGES_DIR . '/' . $roomToken . '/' . $safeFilename;
        } catch (\Exception $e) {
            \OC::$server->getLogger()->error('Failed to upload image: ' . $e->getMessage(), ['app' => self::APP_ID]);
            return null;
        }
    }

    /**
     * Build rich object data for a Talk message from an uploaded file path.
     */
    public function buildRichObject(string $filePath, string $mimeType, string $roomToken): array {
        $baseUrl = rtrim($this->config->getSystemValueString('overwritewebroot', ''), '/');
        $serverUrl = $this->config->getSystemValueString('overwrite.cli.url', 'https://example.com');

        return [
            'type' => 'file',
            'source' => 'share',
            'fileId' => basename($filePath),
            'sourceType' => 'file',
            'mimetype' => $mimeType,
            'title' => basename($filePath),
            'description' => 'Webhook image attachment',
            'thumbnailReady' => true,
            'fileTarget' => '/' . $filePath,
        ];
    }

    // ── Talk Chat API ─────────────────────────────────────────────

    /**
     * Post a message to a Talk room via Chat API.
     *
     * @param string $roomToken Talk room token
     * @param string $message Message text
     * @param string $senderName Sender display name
     * @param array $richObjects Rich object data (optional)
     * @return bool Success
     */
    public function postToRoom(
        string $roomToken,
        string $message,
        string $senderName,
        array $richObjects = [],
    ): bool {
        $bot = $this->userManager->get('talk-bot');
        if (!$bot) {
            \OC::$server->getLogger()->error('talk-bot user not found', ['app' => self::APP_ID]);
            return false;
        }

        $botPassword = $this->getBotPassword();
        if (!$botPassword) {
            \OC::$server->getLogger()->error('Bot password not configured', ['app' => self::APP_ID]);
            return false;
        }

        $baseUrl = rtrim($this->config->getSystemValueString('overwritewebroot', ''), '/');
        $url = $baseUrl . '/ocs/v2.php/apps/spreed/api/v1/chat/' . rawurlencode($roomToken);

        // Build form body
        $bodyParts = [
            'message' => $message,
            'username' => $senderName,
        ];

        if (!empty($richObjects)) {
            $bodyParts['richObjects'] = json_encode($richObjects);
        }

        $body = http_build_query($bodyParts, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $this->client->post($url, [
                'auth' => 'basic',
                'basic' => [$bot->getUID(), $botPassword],
                'headers' => [
                    'OCS-Expect' => '100',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Content-Length' => (string) strlen($body),
                ],
                'body' => $body,
            ]);

            $httpCode = $response->getStatusCode();
            return $httpCode === 200;
        } catch (\Exception $e) {
            \OC::$server->getLogger()->error('Failed to post to Talk: ' . $e->getMessage(), ['app' => self::APP_ID]);
            return false;
        }
    }

    // ── Image cleanup ─────────────────────────────────────────────

    /**
     * Purge images older than retention period.
     */
    public function purgeOldImages(): int {
        $retentionDays = $this->getRetentionDays();
        $cutoff = time() - ($retentionDays * 86400);

        $bot = $this->userManager->get('talk-bot');
        if (!$bot) {
            return 0;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($bot->getUID());
            $imagesDir = $userFolder->getFolder(self::IMAGES_DIR);
            return $this->purgeFolder($imagesDir, $cutoff);
        } catch (\Exception $e) {
            \OC::$server->getLogger()->error('Image cleanup failed: ' . $e->getMessage(), ['app' => self::APP_ID]);
            return 0;
        }
    }

    /**
     * Recursively purge files older than cutoff from a folder.
     */
    private function purgeFolder(Folder $folder, int $cutoff): int {
        $count = 0;

        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getMTime() < $cutoff) {
                $node->delete();
                $count++;
            } elseif ($node instanceof Folder) {
                $count += $this->purgeFolder($node, $cutoff);
            }
        }

        // Clean up empty subdirectories
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder && $node->getFolderInfo() === null) {
                // Folder is empty, check if parent has content
            }
        }

        return $count;
    }

    // ── Config save (bulk) ────────────────────────────────────────

    /**
     * Save all config from the settings UI in one call.
     *
     * @param array $config {
     *     bot_password?: string,
     *     retention_days?: int,
     *     rooms?: array<string, string>,
     *     auth_tokens?: array<string, string[]>,
     *     sender_name?: string
     * }
     */
    public function saveConfig(array $config): void {
        if (isset($config['bot_password']) && $config['bot_password'] !== '') {
            $this->setBotPassword($config['bot_password']);
        }

        if (isset($config['retention_days'])) {
            $this->setRetentionDays((int) $config['retention_days']);
        }

        if (isset($config['rooms'])) {
            $this->setRooms($config['rooms']);
        }

        if (isset($config['auth_tokens'])) {
            $this->setAuthTokens($config['auth_tokens']);
        }

        if (isset($config['sender_name'])) {
            $this->setSenderName($config['sender_name']);
        }
    }
}
