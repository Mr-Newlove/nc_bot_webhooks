<?php

namespace OCA\NCdiscordhook\Service;

use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\Security\ICrypto;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCA\Talk\Manager as TalkManager;
use Psr\Log\LoggerInterface;

class TalkService {
    private const APP_ID = 'ncdiscordhook';
    private const IMAGES_DIR = 'NCdiscordhook-images';

    private IDBConnection $db;
    private IRootFolder $rootFolder;
    private IRequest $request;
    private IURLGenerator $urlGenerator;
    private IUserManager $userManager;
    private IManager $shareManager;
    private LoggerInterface $logger;
    private IClientService $clientService;
    private TalkManager $talkManager;
    private ICrypto $crypto;
    private IConfig $config;

    public function __construct(
        IClientService $clientService,
        IConfig $config,
        IDBConnection $db,
        IRootFolder $rootFolder,
        IRequest $request,
        IURLGenerator $urlGenerator,
        IUserManager $userManager,
        IManager $shareManager,
        LoggerInterface $logger,
        TalkManager $talkManager,
        ICrypto $crypto,
    ) {
        $this->clientService = $clientService;
        $this->config = $config;
        $this->db = $db;
        $this->rootFolder = $rootFolder;
        $this->request = $request;
        $this->urlGenerator = $urlGenerator;
        $this->userManager = $userManager;
        $this->shareManager = $shareManager;
        $this->logger = $logger;
        $this->talkManager = $talkManager;
        $this->crypto = $crypto;
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

    /**
     * Get the raw DB connection (for diagnostic use).
     */
    public function getDbConnection(): IDBConnection {
        return $this->db;
    }

    // ── Retention ─────────────────────────────────────────────────

    public function getRetentionDays(): int {
        return (int) $this->config->getAppValue(self::APP_ID, 'retention_days', '90');
    }

    public function setRetentionDays(int $days): void {
        $this->config->setAppValue(self::APP_ID, 'retention_days', (string) $days);
    }

    // ── Base URL ──────────────────────────────────────────────────

    /**
     * Get the server base URL for internal API calls.
     * Uses trusted_domains as the base URL — the HTTP client blocks
     * all localhost addresses, and in Docker deployments overwrite.cli.url
     * often points to a localhost:port that is unreachable from within the container.
     */
    public function getBaseUrl(): string {
        $overwritten = $this->config->getSystemValueString('overwritewebroot', '');
        if ($overwritten !== '') {
            return rtrim($overwritten, '/');
        }

        // Prefer a public (non-loopback, non-private) trusted domain.
        // In Docker/container deployments, trusted_domains[0] is often 127.0.0.1
        // which is unreachable from within the container.
        $trusted = $this->config->getSystemValue('trusted_domains', []);
        foreach ($trusted as $domain) {
            if ($domain === '') {
                continue;
            }
            // Already has a scheme — trust it as-is
            if (preg_match('#^https?://#', $domain)) {
                return rtrim($domain, '/');
            }
            // Skip loopback and localhost
            $lower = strtolower($domain);
            if ($lower === 'localhost'
                || $lower === '127.0.0.1'
                || $lower === '::1'
            ) {
                continue;
            }
            // Skip private IP ranges (10.x, 172.16-31.x, 192.168.x)
            if (preg_match(
                '/^(10\.\d{1,3}|172\.(1[6-9]|2\d|3[01])|192\.168)\./i',
                $domain
            )) {
                continue;
            }
            return 'https://' . $domain;
        }

        // Fallback to first trusted domain (may be localhost; caller may fail)
        if (!empty($trusted[0])) {
            if (preg_match('#^https?://#', $trusted[0])) {
                return rtrim($trusted[0], '/');
            }
            return 'https://' . $trusted[0];
        }

        // Last resort: try overwrite.cli.url
        $cliUrl = $this->config->getSystemValueString('overwrite.cli.url', '');
        if ($cliUrl !== '') {
            return rtrim($cliUrl, '/');
        }

        return '';
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
     * Get available Talk rooms via database query.
     *
     * Queries the Talk DB directly (bypasses the OCS API which does not
     * accept app passwords for auth). Returns only public channels (type 1)
     * — the only room type that makes sense for a Discord-style webhook
     * channel picker. Returns rooms as [token => displayName] pairs.
     */
    public function getAvailableTalkRooms(): array {
        // Detect table names by querying PostgreSQL information_schema directly.
        $roomTable = $this->detectTalkTableFromCatalog('talk_rooms', 'spreed_room');

        if ($roomTable === null) {
            $sysPrefix = $this->config->getSystemValueString('dbtableprefix', '');
            $talkPrefix = $this->config->getAppValue('spreed', 'databaseprefix', $sysPrefix);
            $this->logger->warning('NCdiscordhook: Talk tables not found', [
                'app' => self::APP_ID,
                'sysPrefix' => $sysPrefix,
                'talkPrefix' => $talkPrefix,
            ]);
            return [];
        }

        // In NC33 the room display name is stored in the 'name' column
        // for all room types. Exclude deleted (type 4), note-to-self (type 6),
        // sample rooms, file share rooms (object_type = 'file'),
        // and private DM rooms (name starts with '["').
        try {
            $sql = 'SELECT token, COALESCE(NULLIF(name, \'\'), token) as display_name
                    FROM "' . $roomTable . '"
                    WHERE type IN (1, 2, 3)
                      AND (object_type IS NULL OR object_type != \'sample\')
                      AND object_type != \'note_to_self\'
                      AND object_type != \'file\'
                      AND name NOT LIKE \'["%\'';
            $result = $this->db->executeQuery($sql);
        } catch (\Exception $e) {
            $this->logger->warning('NCdiscordhook: room name query failed, using token fallback', [
                'app' => self::APP_ID,
                'error' => $e->getMessage(),
            ]);
            $sql = 'SELECT token, token as display_name
                    FROM "' . $roomTable . '"
                    WHERE type IN (1, 2, 3)
                      AND (object_type IS NULL OR object_type != \'sample\')
                      AND object_type != \'note_to_self\'
                      AND object_type != \'file\'
                      AND name NOT LIKE \'["%\'';
            $result = $this->db->executeQuery($sql);
        }

        $this->logger->info('NCdiscordhook: getAvailableTalkRooms', [
            'app' => self::APP_ID,
            'room_table' => $roomTable,
        ]);

        try {
            $rooms = [];
            while ($row = $result->fetch()) {
                $rooms[$row['token']] = $row['display_name'] !== '' ? $row['display_name'] : $row['token'];
            }
            $result->closeCursor();

            $this->logger->info('NCdiscordhook: found ' . count($rooms) . ' rooms', [
                'app' => self::APP_ID,
                'rooms' => array_keys($rooms),
            ]);

            return $rooms;
        } catch (\Exception $e) {
            $this->logger->error('NCdiscordhook: room listing exception', [
                'app' => self::APP_ID,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return [];
        }
    }

    /**
     * Debug helper: return all rooms with key columns for inspection.
     */
    public function getAllTalkRoomsDebug(int $limit = 100): array {
        $roomTable = $this->detectTalkTableFromCatalog('talk_rooms', 'spreed_room');
        if ($roomTable === null) {
            return [];
        }
        $sql = 'SELECT id, token, type, readable_name, label, name, object_type, object_id
                FROM "' . $roomTable . '"
                ORDER BY id LIMIT ' . (int)$limit;
        $result = $this->db->executeQuery($sql);
        $rooms = [];
        while ($row = $result->fetch()) {
            $rooms[] = [
                'id' => $row['id'],
                'token' => $row['token'],
                'type' => (int)$row['type'],
                'readable_name' => $row['readable_name'] ?? null,
                'label' => $row['label'] ?? null,
                'name' => $row['name'] ?? null,
                'object_type' => $row['object_type'] ?? null,
                'object_id' => $row['object_id'] ?? null,
            ];
        }
        $result->closeCursor();
        return $rooms;
    }

    /**
     * Debug helper: count of rooms per type.
     */
    public function getRoomTypeBreakdown(): array {
        $roomTable = $this->detectTalkTableFromCatalog('talk_rooms', 'spreed_room');
        if ($roomTable === null) {
            return [];
        }
        $sql = 'SELECT type, COUNT(*) as count
                FROM "' . $roomTable . '"
                GROUP BY type ORDER BY type';
        $result = $this->db->executeQuery($sql);
        $breakdown = [];
        while ($row = $result->fetch()) {
            $breakdown[(int)$row['type']] = (int)$row['count'];
        }
        $result->closeCursor();
        return $breakdown;
    }

    /**
     * Detect which Talk table name variant exists in the database.
     */
    public function detectTalkTableFromCatalog(string $newName, string $oldName): ?string {
        $sysPrefix = $this->config->getSystemValueString('dbtableprefix', '');
        $talkPrefix = $this->config->getAppValue('spreed', 'databaseprefix', $sysPrefix);

        // Build candidate list ordered by likelihood
        $candidates = [
            $talkPrefix . $newName,
            $sysPrefix . $newName,
            $talkPrefix . $oldName,
            $sysPrefix . $oldName,
            $newName,
            $oldName,
        ];

        // Deduplicate preserving order
        $seen = [];
        $unique = [];
        foreach ($candidates as $name) {
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $unique[] = $name;
            }
        }

        // Query information_schema to find which candidates actually exist as tables
        try {
            $result = $this->db->executeQuery(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = \'public\' AND table_type = \'BASE TABLE\' AND table_name IN (' . implode(',', array_map(fn($n) => '\'' . $n . '\'', $unique)) . ')',
            );
            $found = [];
            while ($row = $result->fetch()) {
                $found[] = $row['table_name'];
            }
            $result->closeCursor();
        } catch (\Exception $e) {
            $this->logger->warning('NCdiscordhook: information_schema query failed', [
                'app' => self::APP_ID,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Try executing a lightweight query on each candidate to verify it's usable.
        foreach ($unique as $table) {
            try {
                $testResult = $this->db->executeQuery(
                    'SELECT 1 FROM "' . $table . '" LIMIT 1',
                );
                $testResult->closeCursor();
                return $table;
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Debug helper: get column names and types for a table.
     */
    public function getTalkTableColumns(string $tableName): array {
        $columns = $this->db->executeQuery(
            'SELECT column_name, data_type, is_nullable
             FROM information_schema.columns
             WHERE table_name = ?
             ORDER BY ordinal_position',
            [$tableName],
        );
        $result = [];
        while ($row = $columns->fetch()) {
            $result[] = [
                'name' => $row['column_name'],
                'type' => $row['data_type'],
                'nullable' => $row['is_nullable'],
            ];
        }
        $columns->closeCursor();
        return $result;
    }

    /**
     * Debug helper: get all column names from a table, then fetch sample rows.
     */
    public function getTalkTableSample(string $tableName, int $limit): array {
        // Get column names
        $colResult = $this->db->executeQuery(
            'SELECT column_name FROM information_schema.columns
             WHERE table_name = ? ORDER BY ordinal_position',
            [$tableName],
        );
        $colNames = [];
        while ($row = $colResult->fetch()) {
            $colNames[] = $row['column_name'];
        }
        $colResult->closeCursor();

        if (empty($colNames)) {
            return [];
        }

        $colList = implode(', ', array_map(fn($c) => '"' . $c . '"', $colNames));
        $rows = $this->db->executeQuery(
            'SELECT ' . $colList . ' FROM "' . $tableName . '" ORDER BY id LIMIT ?',
            [$limit],
        );

        $result = [];
        while ($row = $rows->fetch()) {
            $result[] = $row;
        }
        $rows->closeCursor();
        return $result;
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
     * Get sender name default.
     */
    public function getSenderNameDefault(): string {
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
            $client = $this->clientService->newClient();
            $response = $client->get($url, [
                'timeout' => 15,
                'nextcloud' => [
                    'allow_local_address' => true,
                ],
            ]);

            $mimeType = $response->getHeader('Content-Type') ?: 'image/png';
            $body = $response->getBody();

            if (strlen($body) === 0) {
                return null;
            }

            return ['data' => $body, 'mimeType' => $mimeType];
        } catch (\Exception $e) {
            $this->logger->error('Failed to download image: ' . $url, ['app' => self::APP_ID]);
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
            $imagesDir = $userFolder->getFolder(self::IMAGES_DIR, true);
            $roomDir = $imagesDir->getFolder($roomToken, true);

            // Avoid path traversal
            $safeFilename = basename($filename);
            $filePath = $roomDir->newFile($safeFilename, $data);

            return self::IMAGES_DIR . '/' . $roomToken . '/' . $safeFilename;
        } catch (\Exception $e) {
            $this->logger->error('Failed to upload image: ' . $e->getMessage(), ['app' => self::APP_ID]);
            return null;
        }
    }

    /**
     * Build rich object data for a Talk message from an uploaded file path.
     * Creates a public link share so Talk can resolve the rich object.
     */
    public function buildRichObject(string $filePath, string $mimeType, string $roomToken): ?array {
        $bot = $this->userManager->get('talk-bot');
        if (!$bot) {
            return null;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($bot->getUID());
            $file = $userFolder->get($filePath);
            if (!$file || !$file->isReadable()) {
                return null;
            }

            $share = $this->shareManager->newShare();
            $share->setNode($file)
                ->setPermissions(\OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_DOWNLOAD)
                ->setType(\OCP\Constants::SHARE_TYPE_LINK)
                ->setName(basename($filePath));
            $share = $this->shareManager->createShare($share);

            $linkShare = $this->shareManager->getShareById($share->getId());

            return [
                'rich_object' => [
                    'id' => '',
                    'elements' => [
                        [
                            'type' => 'file',
                            'id' => $linkShare->getToken(),
                            'name' => basename($filePath),
                            'mimetype' => $mimeType,
                            'thumbnailReady' => true,
                            'fileTarget' => '/' . $filePath,
                            'path' => basename($filePath),
                        ],
                    ],
                ],
                'source' => 'file',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to build rich object: ' . $e->getMessage(), ['app' => self::APP_ID]);
            return null;
        }
    }

    // ── Chat API: post message to Talk room ──────────────────────

    /**
     * Post a message to a Talk room via the Chat API.
     *
     * Uses Basic auth with the talk-bot user's app password.
     * Endpoint: /ocs/v2.php/apps/spreed/api/v1/chat/{roomToken}
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
        $botPassword = $this->getBotPassword();
        if ($botPassword === null) {
            $this->logger->error('NCdiscordhook: bot password not configured', ['app' => self::APP_ID]);
            return false;
        }

        // Check bot is enabled for this room (via AppConfig)
        if (!$this->isBotEnabledForRoom($roomToken)) {
            $this->logger->warning('NCdiscordhook: bot not enabled for room', [
                'app' => self::APP_ID,
                'room_token' => $roomToken,
            ]);
            return false;
        }

        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            $this->logger->error('NCdiscordhook: base URL not configured', ['app' => self::APP_ID]);
            return false;
        }

        $endpoint = $baseUrl . '/ocs/v2.php/apps/spreed/api/v1/chat/' . $roomToken;

        // Build message body for Chat API
        $body = [
            'message' => $message,
            'actorType' => 'users',
            'actorId' => 'talk-bot',
            'actorDisplayName' => $senderName,
        ];

        if (!empty($richObjects)) {
            $indexed = [];
            foreach ($richObjects as $index => $richObj) {
                $indexed['file-' . $index] = $richObj;
            }
            $body['richObjects'] = $indexed;
        }

        $jsonBody = json_encode($body);

        // Basic auth: base64('talk-bot:' . bot_password)
        $credentials = base64_encode('talk-bot:' . $botPassword);

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($endpoint, [
                'body' => $jsonBody,
                'headers' => [
                    'OCS-Expect-Formatted' => 'json',
                    'OCS-APIRequest' => '1',
                    'Authorization' => 'Basic ' . $credentials,
                    'Content-Type' => 'application/json',
                ],
                'nextcloud' => [
                    'allow_local_address' => true,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('NCdiscordhook: message posted to room ' . $roomToken, [
                    'app' => self::APP_ID,
                ]);
                return true;
            }

            $responseBody = $response->getBody();
            $this->logger->error('NCdiscordhook: chat API returned ' . $statusCode . ': ' . $responseBody, [
                'app' => self::APP_ID,
                'room_token' => $roomToken,
                'status' => $statusCode,
            ]);
            return false;
        } catch (\Exception $e) {
            $this->logger->error('NCdiscordhook: chat API request failed: ' . $e->getMessage(), [
                'app' => self::APP_ID,
                'room_token' => $roomToken,
            ]);
            return false;
        }
    }

    /**
     * Check if the bot is enabled for a specific room (via AppConfig).
     */
    public function isBotEnabledForRoom(string $roomToken): bool {
        $rooms = $this->getRooms();
        return isset($rooms[$roomToken]);
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
            $this->logger->error('Image cleanup failed: ' . $e->getMessage(), ['app' => self::APP_ID]);
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
                $node->delete();
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
     *     disabled_rooms?: array<string>,
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

        // Remove explicitly disabled rooms from config
        if (isset($config['disabled_rooms']) && is_array($config['disabled_rooms'])) {
            $rooms = $this->getRooms();
            foreach ($config['disabled_rooms'] as $token) {
                unset($rooms[$token]);
            }
            $this->setRooms($rooms);
        }

        if (isset($config['auth_tokens'])) {
            $this->setAuthTokens($config['auth_tokens']);
        }

        if (isset($config['sender_name'])) {
            $this->setSenderName($config['sender_name']);
        }

        // Ensure talk-bot is a participant in all configured rooms
        $this->ensureBotParticipants();
    }

    /**
     * Add talk-bot user as a participant in all configured rooms.
     * Required so the Chat API accepts requests authenticated as talk-bot.
     */
    private function ensureBotParticipants(): void {
        if ($this->talkParticipantService === null) {
            $this->logger->info('NCdiscordhook: ParticipantService not available, skipping auto-join for talk-bot', ['app' => self::APP_ID]);
            return;
        }

        $rooms = $this->getRooms();
        if (empty($rooms)) {
            return;
        }

        // Get the talk-bot user
        $botUser = $this->userManager->get('talk-bot');
        if ($botUser === null) {
            $this->logger->warning('NCdiscordhook: talk-bot user not found', ['app' => self::APP_ID]);
            return;
        }

        foreach (array_keys($rooms) as $token) {
            try {
                $room = $this->talkManager->getRoomForToken($token);
                // Check if talk-bot is already a participant
                $participants = $room->getParticipants();
                $actors = [];
                if (is_array($participants)) {
                    $actors = $participants['actors'] ?? [];
                } elseif (is_object($participants) && method_exists($participants, 'getActors')) {
                    $actors = $participants->getActors();
                }
                $hasBot = false;
                foreach ($actors as $actor) {
                    if (isset($actor['type']) && $actor['type'] === 'users' && isset($actor['actorId']) && $actor['actorId'] === 'talk-bot') {
                        $hasBot = true;
                        break;
                    }
                }
                if (!$hasBot) {
                    $this->talkParticipantService->addParticipant(
                        $room,
                        $botUser,
                        \OCA\Talk\Participant::TYPE_USER,
                        \OCA\Talk\Participant::PERMISSIONS_DEFAULT,
                    );
                    $this->logger->info('NCdiscordhook: added talk-bot as participant in room ' . $token, [
                        'app' => self::APP_ID,
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->warning('NCdiscordhook: failed to add talk-bot to room ' . $token, [
                    'app' => self::APP_ID,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
