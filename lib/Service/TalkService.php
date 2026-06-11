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
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Security\ICrypto;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager;
use Psr\Log\LoggerInterface;

class TalkService {
    private const APP_ID = 'ncdiscordhook';
    private const IMAGES_DIR = 'NCdiscordhook-images';

    // Internal system rooms to hide from the room picker.
    private const INTERNAL_ROOMS = [
        'talk-bot',
        'Note to self',
        "Let's get started!",
    ];

    private IClient $client;
    private IConfig $config;
    private IDBConnection $db;
    private IRootFolder $rootFolder;
    private ICrypto $crypto;
    private IURLGenerator $urlGenerator;
    private IUserManager $userManager;
    private IRequest $request;
    private IManager $shareManager;
    private LoggerInterface $logger;

    public function __construct(
        IClientService $clientService,
        IConfig $config,
        IDBConnection $db,
        IRootFolder $rootFolder,
        ICrypto $crypto,
        IURLGenerator $urlGenerator,
        IUserManager $userManager,
        IRequest $request,
        IManager $shareManager,
        LoggerInterface $logger,
    ) {
        $this->client = $clientService->newClient();
        $this->config = $config;
        $this->db = $db;
        $this->rootFolder = $rootFolder;
        $this->crypto = $crypto;
        $this->urlGenerator = $urlGenerator;
        $this->userManager = $userManager;
        $this->request = $request;
        $this->shareManager = $shareManager;
        $this->logger = $logger;
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
     * Falls back to overwrite.cli.url when overwritewebroot is not set.
     * When the URL resolves to localhost, falls back to the request host
     * or trusted_domains — the HTTP client blocks localhost for SSRF safety.
     */
    private function getBaseUrl(): string {
        $overwritten = $this->config->getSystemValueString('overwritewebroot', '');
        if ($overwritten !== '') {
            return rtrim($overwritten, '/');
        }
        $cliUrl = $this->config->getSystemValueString('overwrite.cli.url', '');
        if ($cliUrl === '') {
            return '';
        }
        $parsed = parse_url($cliUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? '';
        $path = rtrim($parsed['path'] ?? '', '/');
        $portStr = $port !== '' ? ':' . $port : '';
        $baseUrl = $scheme . '://' . $host . $portStr . $path;

        // HTTP client blocks localhost — fall back to request host or trusted_domains
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            $requestHost = $this->request->getServerHost();
            if ($requestHost !== '') {
                // Extract port from Host header (e.g. "example.com:443" or "example.com")
                $hostHeader = $this->request->getHeader('Host');
                $requestPort = 0;
                if (str_contains($hostHeader, ':')) {
                    $parts = explode(':', $hostHeader);
                    $requestPort = (int) end($parts);
                }
                $basePort = parse_url($baseUrl, PHP_URL_PORT);
                $requestPortStr = $requestPort !== 0 && $requestPort !== $basePort
                    ? ':' . $requestPort
                    : '';
                return $scheme . '://' . $requestHost . $requestPortStr . $path;
            }
            // Last resort: trusted_domains
            $trusted = $this->config->getSystemValue('trusted_domains', []);
            if (!empty($trusted[0])) {
                return $scheme . '://' . $trusted[0] . $path;
            }
        }

        return $baseUrl;
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
     * Queries the Talk DB directly to find rooms where the bot is a participant.
     * Bypasses the OCS API which does not accept app passwords for auth.
     * Returns rooms as [token => displayName] pairs.
     */
    public function getAvailableTalkRooms(): array {
        $botUser = $this->getBotUser();
        if (!$botUser) {
            return [];
        }

        $botUid = $botUser->getUID();

        // Detect table names by querying PostgreSQL information_schema directly.
        // We cannot rely on tableExists() — it has case-sensitivity issues with PostgreSQL
        // where it may match a differently-cased table name but raw SQL still fails.
        $roomTable = $this->detectTalkTableFromCatalog('talk_rooms', 'spreed_room');
        $participantTable = $this->detectTalkTableFromCatalog('talk_attendees', 'spreed_participant');

        if ($roomTable === null || $participantTable === null) {
            $sysPrefix = $this->config->getSystemValueString('dbtableprefix', '');
            $talkPrefix = $this->config->getAppValue('spreed', 'databaseprefix', $sysPrefix);
            $this->logger->warning('NCdiscordhook: Talk tables not found', [
                'app' => self::APP_ID,
                'sysPrefix' => $sysPrefix,
                'talkPrefix' => $talkPrefix,
            ]);
            return [];
        }

        // Find rooms where the bot is a participant.
        // Use raw SQL — query builder's expr() methods re-parse table names and double-prefix them.
        $sql = 'SELECT "' . $roomTable . '".token, "' . $roomTable . '".name, "' . $roomTable . '".type
                FROM "' . $roomTable . '"
                JOIN "' . $participantTable . '" ON "' . $participantTable . '".room_id = "' . $roomTable . '".id
                WHERE "' . $participantTable . '".actor_type = :actorType
                  AND "' . $participantTable . '".actor_id = :actorId
                  AND "' . $roomTable . '".type <> :excludeType';

        $result = $this->db->executeQuery($sql, [
            'actorType' => 'users',
            'actorId' => $botUid,
            'excludeType' => -1, // exclude UNKNOWN type
        ]);

        $this->logger->info('NCdiscordhook: getAvailableTalkRooms', [
            'app' => self::APP_ID,
            'bot_user' => $botUid,
            'room_table' => $roomTable,
            'participant_table' => $participantTable,
        ]);

        try {
            $rooms = [];
            while ($row = $result->fetch()) {
                $token = $row['token'];
                $name = $row['name'] !== '' ? $row['name'] : $token;
                $rooms[$token] = $name;
            }
            $result->closeCursor();

            $this->logger->info('NCdiscordhook: found ' . count($rooms) . ' rooms', [
                'app' => self::APP_ID,
                'rooms' => array_keys($rooms),
            ]);

            // Filter out internal system rooms.
            foreach (self::INTERNAL_ROOMS as $internal) {
                foreach ($rooms as $token => $name) {
                    if (mb_strtolower($name) === mb_strtolower($internal)) {
                        unset($rooms[$token]);
                    }
                }
            }

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
     * Detect which Talk table name variant exists in the database.
     *
     * Queries PostgreSQL information_schema directly to find actual table names,
     * then verifies by checking column presence. Avoids tableExists() which has
     * case-sensitivity issues with PostgreSQL.
     *
     * Talk app has its own database prefix (spreed.appconfig.databaseprefix),
     * separate from the main dbtableprefix.
     */
    private function detectTalkTableFromCatalog(string $newName, string $oldName): ?string {
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
        // This avoids the PostgreSQL case-sensitivity mismatch where tableExists()
        // may match a differently-cased table but raw SQL fails.
        foreach ($unique as $table) {
            try {
                $testResult = $this->db->executeQuery(
                    'SELECT 1 FROM "' . $table . '" LIMIT 1',
                );
                $testResult->closeCursor();
                return $table;
            } catch (\Exception $e) {
                // Table exists in catalog but query failed — skip to next candidate
                continue;
            }
        }

        return null;
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

            $serverUrl = $this->config->getSystemValueString('overwrite.cli.url', 'https://example.com');

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
            $this->logger->error('talk-bot user not found', ['app' => self::APP_ID]);
            return false;
        }

        $botPassword = $this->getBotPassword();
        if (!$botPassword) {
            $this->logger->error('Bot password not configured', ['app' => self::APP_ID]);
            return false;
        }

        $baseUrl = $this->getBaseUrl();
        $url = $baseUrl . '/ocs/v2.php/apps/spreed/api/v1/chat/' . rawurlencode($roomToken);

        // Build form body
        $bodyParts = [
            'message' => $message,
            'username' => $senderName,
        ];

        if (!empty($richObjects)) {
            $indexed = [];
            foreach ($richObjects as $index => $richObj) {
                $indexed['file-' . $index] = $richObj;
            }
            $bodyParts['richObjects'] = $indexed;
        }

        $body = http_build_query($bodyParts, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $this->client->post($url, [
                'auth' => 'basic',
                'basic' => [$bot->getUID(), $botPassword],
                'headers' => [
                    'OCS-Expect' => '100',
                    'OCS-ApiRequest' => 'true',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Content-Length' => (string) strlen($body),
                ],
                'body' => $body,
            ]);

            $httpCode = $response->getStatusCode();
            return $httpCode === 200;
        } catch (\Exception $e) {
            $this->logger->error('Failed to post to Talk: ' . $e->getMessage(), ['app' => self::APP_ID]);
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
