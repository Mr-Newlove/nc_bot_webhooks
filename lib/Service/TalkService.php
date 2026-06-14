<?php

namespace OCA\Ncbotwebhooks\Service;

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
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Manager as TalkManager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\AttendeeMapper;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\TalkSession;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

class TalkService {
    private const APP_ID = 'nc_bot_webhooks';
    private const IMAGES_DIR = 'nc_bot_webhooks-images';

    private IDBConnection $db;
    private IRootFolder $rootFolder;
    private IRequest $request;
    private IURLGenerator $urlGenerator;
    private IUserManager $userManager;
    private LoggerInterface $logger;
    private IClientService $clientService;
    private TalkManager $talkManager;
    private ICrypto $crypto;
    private IConfig $config;
    private AttendeeMapper $attendeeMapper;
    private IShareManager $shareManager;
    private ParticipantService $participantService;
    private ChatManager $chatManager;
    private TalkSession $talkSession;
    private IUserSession $userSession;

    public function __construct(
        IClientService $clientService,
        IConfig $config,
        IDBConnection $db,
        IRootFolder $rootFolder,
        IRequest $request,
        IURLGenerator $urlGenerator,
        IUserManager $userManager,
        IUserSession $userSession,
        LoggerInterface $logger,
        TalkManager $talkManager,
        ICrypto $crypto,
        AttendeeMapper $attendeeMapper,
        IShareManager $shareManager,
        ParticipantService $participantService,
        ChatManager $chatManager,
        TalkSession $talkSession,
    ) {
        $this->clientService = $clientService;
        $this->config = $config;
        $this->db = $db;
        $this->rootFolder = $rootFolder;
        $this->request = $request;
        $this->urlGenerator = $urlGenerator;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->logger = $logger;
        $this->talkManager = $talkManager;
        $this->crypto = $crypto;
        $this->attendeeMapper = $attendeeMapper;
        $this->shareManager = $shareManager;
        $this->participantService = $participantService;
        $this->chatManager = $chatManager;
        $this->talkSession = $talkSession;
    }

    /**
     * Resolve Talk services from the global DI container.
     * In NC33, \OC\AppFramework\App::getAppContainer() was removed.
     * \OCP\Server::get() resolves the service via ServerContainer::getAppContainerForService()
     * which looks up the target class's app namespace (e.g. OCA\Talk) and returns the correct service.
     */
    private function resolveTalkService(string $class): object {
        return \OCP\Server::get($class);
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

    /**
     * Validate a bot password by encrypting and decrypting it (round-trip test).
     * Returns ['valid' => true] on success, or ['valid' => false, 'error' => '...'] on failure.
     */
    public function validateBotPassword(string $password): array {
        if ($password === '') {
            return ['valid' => false, 'error' => 'Bot password cannot be empty.'];
        }

        try {
            $encrypted = $this->crypto->encrypt($password);
            $decrypted = $this->crypto->decrypt($encrypted);
            if ($decrypted !== $password) {
                return ['valid' => false, 'error' => 'Password encryption/decryption failed. The password may contain unsupported characters.'];
            }
            return ['valid' => true];
        } catch (\Exception $e) {
            return ['valid' => false, 'error' => 'Password encryption failed: ' . $e->getMessage()];
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

    /**
     * Overwrite Talk actor for the current session.
     *
     * Talk's SystemMessageListener::sendSystemMessage() checks these
     * session keys to determine the actor when no participant is passed.
     * Used to suppress the "guest" actor bug in Talk 23.0.6 where
     * fixMimeTypeOfVoiceMessage() fires on every TYPE_ROOM share.
     *
     * Must be cleared after use to avoid leaking to other requests.
     */
    private function setSessionOverwrite(string $actorId): void {
        $reflection = new \ReflectionObject($this->talkSession);
        $property = $reflection->getProperty('session');
        $property->setAccessible(true);
        /** @var \OCP\ISession $session */
        $session = $property->getValue($this->talkSession);
        $session->set('talk-overwrite-actor-type', Attendee::ACTOR_USERS);
        $session->set('talk-overwrite-actor-id', $actorId);
    }

    private function clearSessionOverwrite(): void {
        try {
            $reflection = new \ReflectionObject($this->talkSession);
            $property = $reflection->getProperty('session');
            $property->setAccessible(true);
            /** @var \OCP\ISession $session */
            $session = $property->getValue($this->talkSession);
            $session->remove('talk-overwrite-actor-type');
            $session->remove('talk-overwrite-actor-id');
        } catch (\Exception $e) {
            // Session keys may not exist — ignore
        }
    }

    // ── Base URL ──────────────────────────────────────────────────

    /**
     * Get the server base URL for internal API calls.
     * Uses trusted_domains as the base URL — the HTTP client blocks
     * all localhost addresses, and in Docker deployments overwrite.cli.url
     * often points to a localhost:port that is unreachable from within the container.
     */
    public function getBaseUrl(): string {
        // Prefer overwritehost (hostname override) over trusted_domains
        $overwriteHost = $this->config->getSystemValueString('overwritehost', '');
        if ($overwriteHost !== '') {
            $proto = $this->config->getSystemValueString('overwriteproto', 'https');
            return rtrim($proto . '://' . $overwriteHost, '/');
        }

        $overwritten = $this->config->getSystemValueString('overwritewebroot', '');
        if ($overwritten !== '') {
            // overwritewebroot may be a path — prepend current host/proto if needed
            if (preg_match('#^https?://#', $overwritten)) {
                return rtrim($overwritten, '/');
            }
            // It's a path — fall through to trusted_domains
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
            $this->logger->warning('NCbotwebhooks: Talk tables not found', [
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
            $this->logger->warning('NCbotwebhooks: room name query failed, using token fallback', [
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

        $this->logger->info('NCbotwebhooks: getAvailableTalkRooms', [
            'app' => self::APP_ID,
            'room_table' => $roomTable,
        ]);

        try {
            $rooms = [];
            while ($row = $result->fetch()) {
                $rooms[$row['token']] = $row['display_name'] !== '' ? $row['display_name'] : $row['token'];
            }
            $result->closeCursor();

            $this->logger->info('NCbotwebhooks: found ' . count($rooms) . ' rooms', [
                'app' => self::APP_ID,
                'rooms' => array_keys($rooms),
            ]);

            return $rooms;
        } catch (\Exception $e) {
            $this->logger->error('NCbotwebhooks: room listing exception', [
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
            $this->logger->warning('NCbotwebhooks: information_schema query failed', [
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
    /**
     * Prepend a display name line to a message.
     * Since Talk doesn't support per-message avatars, we embed the name in the message.
     */
    public function prependDisplayName(string $displayName, string $message): string {
        $nameLine = '🤖 **' . $displayName . '**';
        if ($message === '') {
            return $nameLine;
        }
        return $nameLine . "\n\n" . $message;
    }

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
        if (!empty($data['sender_name'])) {
            return $data['sender_name'];
        }
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
     * Map Apprise JSON payload to our internal format.
     *
     * Apprise sends: { version, type, title, body, attachments }
     * Returns: { message, senderName, richObjects }
     */
    public function mapApprisePayload(array $data, string $roomToken = ''): array {
        $this->logger->info('NCbotwebhooks: mapApprisePayload input', [
            'app' => self::APP_ID,
            'type' => $data['type'] ?? 'none',
            'has_attachments' => !empty($data['attachments']),
            'has_attachment' => !empty($data['attachment']),
            'attachments' => json_encode($data['attachments'] ?? []),
            'body' => $data['body'] ?? '',
            'title' => $data['title'] ?? '',
        ]);
        $parts = [];

        // Display name: sender_name > title > subject > config default
        $displayName = !empty($data['sender_name']) ? $data['sender_name']
            : (!empty($data['title']) ? $data['title']
            : (!empty($data['subject']) ? $data['subject']
            : $this->config->getAppValue(self::APP_ID, 'sender_name', 'Webhook Bot')));

        // Body
        if (!empty($data['body'])) {
            $parts[] = $data['body'];
        }

        // For image-type notifications, use the attachment URL as the message
        if (!empty($data['type']) && $data['type'] === 'image') {
            // Apprise sends image notifications with type=image and attachment (string URL)
            $imageUrls = [];
            if (!empty($data['attachment']) && is_string($data['attachment'])) {
                $imageUrls[] = $data['attachment'];
            }
            // Collect image URLs and base64 attachments
            $imageUrls = [];
            $imageBases64 = [];
            if (!empty($data['attachment']) && is_string($data['attachment'])) {
                $imageUrls[] = $data['attachment'];
            }
            if (!empty($data['attachments']) && is_array($data['attachments'])) {
                foreach ($data['attachments'] as $a) {
                    if (is_string($a)) {
                        $imageUrls[] = $a;
                    } elseif (is_array($a)) {
                        if (!empty($a['url'])) {
                            $imageUrls[] = $a['url'];
                        } elseif (!empty($a['path']) && (str_starts_with($a['path'], 'http://') || str_starts_with($a['path'], 'https://'))) {
                            $imageUrls[] = $a['path'];
                        } elseif (!empty($a['base64'])) {
                            $imageBases64[] = $a;
                        }
                    }
                }
            }
            $richObjects = [];
            if (!empty($imageUrls)) {
                $richObjects = $this->processImageUrls($imageUrls, $roomToken);
            }
            foreach ($imageBases64 as $b64) {
                $fileData = base64_decode($b64['base64'], true);
                if ($fileData === false) {
                    continue;
                }
                $fileName = $b64['filename'] ?? $b64['name'] ?? 'attachment';
                $mimeType = $b64['mimetype'] ?? $b64['mimeType'] ?? 'image/png';
                $uploadPath = $this->uploadImage($roomToken, $fileName, $fileData, $mimeType);
                if ($uploadPath !== null) {
                    $richObj = $this->buildRichObject($uploadPath, $mimeType, $roomToken);
                    if ($richObj !== null) {
                        $richObjects[] = $richObj;
                    }
                }
            }
            if (!empty($imageUrls) || !empty($imageBases64)) {
                // Use title as message if body is empty
                if (empty($data['body'])) {
                    $message = !empty($data['title']) ? $data['title'] : '';
                } else {
                    $message = implode("\n\n", $parts);
                }
                $senderName = $displayName;
                return [
                    'message' => $message,
                    'senderName' => $senderName,
                    'displayName' => $displayName,
                    'richObjects' => $richObjects,
                ];
            }
        }

        // Type prefix for context (e.g. "[Warning]")
        if (!empty($data['type'])) {
            $typeLabels = [
                'info' => '[Info]',
                'success' => '[Success]',
                'warning' => '[Warning]',
                'error' => '[Error]',
            ];
            if (isset($typeLabels[$data['type']])) {
                array_unshift($parts, $typeLabels[$data['type']]);
            }
        }

        $message = implode("\n\n", $parts);

        // If body is empty but we have a title/subject, use it as the message
        // (handles test notifications and simple alerts with no body)
        if ($message === '') {
            if (!empty($data['title'])) {
                $message = $data['title'];
            } elseif (!empty($data['subject'])) {
                $message = $data['subject'];
            }
        }

        // Sender name: use app name if available, otherwise default
        $senderName = $displayName;

        // Handle attachments: download files and upload to Talk
        $richObjects = [];
        if (!empty($data['attachments']) && is_array($data['attachments'])) {
            foreach ($data['attachments'] as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }

                $filePath = $attachment['path'] ?? '';
                $this->logger->info('NCbotwebhooks: attachment path', [
                    'app' => self::APP_ID,
                    'path' => $filePath,
                    'url_key' => $attachment['url'] ?? 'none',
                ]);
                if (str_starts_with($filePath, 'file://')) {
                    $localPath = substr($filePath, 7); // Strip 'file://' prefix
                    if (is_file($localPath) && is_readable($localPath)) {
                        $fileData = file_get_contents($localPath);
                        if ($fileData === false) {
                            continue;
                        }

                        $fileName = $attachment['name'] ?? basename($localPath);
                        $mimeType = $attachment['mimeType'] ?? mime_content_type($localPath);

                        $uploadPath = $this->uploadImage($roomToken, $fileName, $fileData, $mimeType);
                        if ($uploadPath !== null) {
                            $richObj = $this->buildRichObject($uploadPath, $mimeType, $roomToken);
                            if ($richObj !== null) {
                                $richObjects[] = $richObj;
                            }
                        }
                    }
                } elseif (!empty($attachment['url'] ?? '')) {
                    // Remote URL attachment
                    $imageData = $this->downloadImage($attachment['url']);
                    if ($imageData === null) {
                        continue;
                    }

                    $fileName = $attachment['name'] ?? 'attachment';
                    $uploadPath = $this->uploadImage($roomToken, $fileName, $imageData['data'], $imageData['mimeType']);
                    if ($uploadPath !== null) {
                        $richObj = $this->buildRichObject($uploadPath, $imageData['mimeType'], $roomToken);
                        if ($richObj !== null) {
                            $richObjects[] = $richObj;
                        }
                    }
                } elseif (str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://')) {
                    // HA rest_command sends HTTP URLs in 'path' — treat as remote URL
                    $richObjects = array_merge($richObjects, $this->processImageUrls([$filePath], $roomToken));
                } elseif (!empty($attachment['url'] ?? '')) {
                    // Remote URL attachment
                    $imageData = $this->downloadImage($attachment['url']);
                    if ($imageData === null) {
                        continue;
                    }

                    $fileName = $attachment['name'] ?? 'attachment';
                    $uploadPath = $this->uploadImage($roomToken, $fileName, $imageData['data'], $imageData['mimeType']);
                    if ($uploadPath !== null) {
                        $richObj = $this->buildRichObject($uploadPath, $imageData['mimeType'], $roomToken);
                        if ($richObj !== null) {
                            $richObjects[] = $richObj;
                        }
                    }
                } elseif (!empty($attachment['base64'] ?? '')) {
                    // Base64-encoded attachment (Apprise library JSON method)
                    $fileData = base64_decode($attachment['base64'], true);
                    if ($fileData === false) {
                        continue;
                    }

                    $fileName = $attachment['filename'] ?? $attachment['name'] ?? 'attachment';
                    $mimeType = $attachment['mimetype'] ?? $attachment['mimeType'] ?? 'application/octet-stream';
                    $uploadPath = $this->uploadImage($roomToken, $fileName, $fileData, $mimeType);
                    if ($uploadPath !== null) {
                        $richObj = $this->buildRichObject($uploadPath, $mimeType, $roomToken);
                        if ($richObj !== null) {
                            $richObjects[] = $richObj;
                        }
                    }
                } elseif (!empty($attachment['data'] ?? '')) {
                    // Raw data attachment (extracted from standalone multipart part)
                    $fileData = $attachment['data'];
                    $fileName = $attachment['filename'] ?? 'attachment';
                    $mimeType = $attachment['mimeType'] ?? 'application/octet-stream';
                    $uploadPath = $this->uploadImage($roomToken, $fileName, $fileData, $mimeType);
                    if ($uploadPath !== null) {
                        $richObj = $this->buildRichObject($uploadPath, $mimeType, $roomToken);
                        if ($richObj !== null) {
                            $richObjects[] = $richObj;
                        }
                    }
                }
            }
        }

        return [
            'message' => $message,
            'senderName' => $senderName,
            'displayName' => $displayName,
            'richObjects' => $richObjects,
        ];
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

            $statusCode = $response->getStatusCode();
            $this->logger->info('NCbotwebhooks: image download response', [
                'app' => self::APP_ID,
                'url' => $url,
                'status_code' => $statusCode,
                'body_length' => strlen($body),
            ]);
            if (strlen($body) === 0) {
                $this->logger->warning('NCbotwebhooks: image download returned empty body', [
                    'app' => self::APP_ID,
                    'url' => $url,
                    'status_code' => $statusCode,
                ]);
                return null;
            }

            return ['data' => $body, 'mimeType' => $mimeType];
        } catch (\Exception $e) {
            $this->logger->warning('NCbotwebhooks: Failed to download image: ' . $url . ' — ' . $e->getMessage(), ['app' => self::APP_ID]);
            return null;
        }
    }

    /**
     * Upload an image to the bot user's files under IMAGES_DIR/<roomToken>/.
     * Filename format: talk-bot YYYY-MM-DD hh.mm.ss.microsec.ext
     * Returns the filecache path (e.g. nc_bot_webhooks-images/abc123/talk-bot 2026-06-13 01.35.19.123456_filename.png) or null on failure.
     */
    public function uploadImage(string $roomToken, string $filename, string $data, string $mimeType): ?string {
        $bot = $this->userManager->get('talk-bot');
        if (!$bot) {
            $this->logger->error('NCbotwebhooks: uploadImage — bot user not found', ['app' => self::APP_ID]);
            return null;
        }

        try {
            $this->logger->info('NCbotwebhooks: uploadImage start', [
                'app' => self::APP_ID,
                'roomToken' => $roomToken,
                'filename' => $filename,
                'size' => strlen($data),
                'bot_uid' => $bot->getUID(),
            ]);
            $userFolder = $this->rootFolder->getUserFolder($bot->getUID());
            $this->logger->info('NCbotwebhooks: getUserFolder succeeded', [
                'app' => self::APP_ID,
                'userFolder_type' => get_class($userFolder),
            ]);

            // Ensure IMAGES_DIR exists (e.g. nc_bot_webhooks-images/)
            $imagesDir = null;
            try {
                $imagesDir = $userFolder->get(self::IMAGES_DIR);
                $this->logger->info('NCbotwebhooks: images dir already exists', [
                    'app' => self::APP_ID,
                    'path' => self::IMAGES_DIR,
                ]);
            } catch (\OCP\Files\NotFoundException $e) {
                $this->logger->info('NCbotwebhooks: creating images dir', [
                    'app' => self::APP_ID,
                    'path' => self::IMAGES_DIR,
                ]);
                $userFolder->newFolder(self::IMAGES_DIR);
                $imagesDir = $userFolder->get(self::IMAGES_DIR);
            } catch (\Error $e) {
                $this->logger->error('NCbotwebhooks: get threw Error on images dir: ' . $e->getMessage(), [
                    'app' => self::APP_ID,
                    'path' => self::IMAGES_DIR,
                    'exception' => get_class($e),
                ]);
                throw $e;
            }

            // Ensure per-room subdirectory exists
            $roomDir = null;
            try {
                $roomDir = $imagesDir->get($roomToken);
                $this->logger->info('NCbotwebhooks: room dir already exists', [
                    'app' => self::APP_ID,
                    'path' => $roomToken,
                ]);
            } catch (\OCP\Files\NotFoundException $e) {
                $this->logger->info('NCbotwebhooks: creating room dir', [
                    'app' => self::APP_ID,
                    'parent' => self::IMAGES_DIR,
                    'path' => $roomToken,
                ]);
                $imagesDir->newFolder($roomToken);
                $roomDir = $imagesDir->get($roomToken);
            } catch (\Error $e) {
                $this->logger->error('NCbotwebhooks: get threw Error on room dir: ' . $e->getMessage(), [
                    'app' => self::APP_ID,
                    'parent' => self::IMAGES_DIR,
                    'path' => $roomToken,
                    'exception' => get_class($e),
                ]);
                throw $e;
            }

            // Build timestamped filename: talk-bot YYYY-MM-DD hh.mm.ss.microsec.ext
            $safeFilename = $this->sanitizeFilename($filename);
            $ext = pathinfo($safeFilename, PATHINFO_EXTENSION);
            $base = pathinfo($safeFilename, PATHINFO_FILENAME);
            $timestamp = date('Y-m-d H.i.s') . '.' . sprintf('%06d', (int)(microtime(true) * 1000) % 1000000);
            $relativePath = 'talk-bot ' . $timestamp . '_' . $base . '.' . $ext;

            $this->logger->info('NCbotwebhooks: writing file', [
                'app' => self::APP_ID,
                'original' => $safeFilename,
                'path' => $relativePath,
            ]);
            $roomDir->newFile($relativePath, $data);
            $this->logger->info('NCbotwebhooks: file written successfully', [
                'app' => self::APP_ID,
                'path' => self::IMAGES_DIR . '/' . $roomToken . '/' . $relativePath,
            ]);

            // Verify filecache entry was created by newFile.
            $prefix2 = strtolower($this->config->getSystemValueString('dbtableprefix', 'oc_'));
            if (substr($prefix2, -1) !== '_') {
                $prefix2 .= '_';
            }
            $fcTable = $prefix2 . 'filecache';
            try {
                $pdo2 = $this->db->getInner()->getNativeConnection();
                $stmtFc = $pdo2->prepare(
                    'SELECT "fileid","storage","path","path_hash","name","mimetype","size" FROM "' . $fcTable . '" WHERE "path" = ?',
                );
                $stmtFc->execute([self::IMAGES_DIR . '/' . $roomToken . '/' . $relativePath]);
                $fcRow = $stmtFc->fetchAll(\PDO::FETCH_ASSOC);
                $stmtFc->closeCursor();
                $this->logger->info('NCbotwebhooks: uploadImage filecache check', [
                    'app' => self::APP_ID,
                    'found' => count($fcRow) > 0,
                    'entries' => $fcRow,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('NCbotwebhooks: uploadImage filecache check failed: ' . $e->getMessage(), ['app' => self::APP_ID]);
            }

            return self::IMAGES_DIR . '/' . $roomToken . '/' . $relativePath;
        } catch (\Error $e) {
            $this->logger->error('NCbotwebhooks: uploadImage Error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), ['app' => self::APP_ID]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('NCbotwebhooks: Failed to upload image: ' . $e->getMessage(), ['app' => self::APP_ID]);
            return null;
        }
    }

    /**
     * Sanitize a filename to prevent path traversal.
     */
    private function sanitizeFilename(string $filename): string {
        return basename($filename);
    }


    /**
     * Build rich object data for a Talk message from an uploaded file path.
     * Creates a public link share so Talk can resolve the rich object.
     */
    public function buildRichObject(string $filePath, string $mimeType, string $roomToken): ?array {
        $this->logger->info('NCbotwebhooks: buildRichObject ENTER', ['app' => self::APP_ID, 'filePath' => $filePath, 'roomToken' => $roomToken]);
        $bot = $this->userManager->get('talk-bot');
        if (!$bot) {
            $this->logger->info('NCbotwebhooks: buildRichObject EXIT noBot', ['app' => self::APP_ID]);
            return null;
        }

        try {
            $safeFilename = basename($filePath);
            $this->logger->info('NCbotwebhooks: richObject step=basename', ['app' => self::APP_ID]);

            // filePath is the full filecache path returned by uploadImage (e.g. nc_bot_webhooks-images/abc123/talk-bot 2026-06-13 01.35.19.123456_filename.png).
            $fileCachePath = $filePath;
            $this->logger->info('NCbotwebhooks: richObject step=buildPath', ['app' => self::APP_ID, 'path' => $fileCachePath]);

            // Use PDO directly to avoid any DBConnection method that could trigger lazy init.
            $this->logger->info('NCbotwebhooks: richObject step=getPdo', ['app' => self::APP_ID]);
            $pdo = $this->db->getInner()->getNativeConnection();
            $this->logger->info('NCbotwebhooks: richObject step=pdoOk', ['app' => self::APP_ID]);

            // Get table prefix from system config (safe — no DB abstraction layer).
            $prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
            $prefix = strtolower($prefix);
            if ($prefix === '') {
                $prefix = 'oc_';
            }
            // Ensure trailing underscore
            if (substr($prefix, -1) !== '_') {
                $prefix = $prefix . '_';
            }

            $fileCacheTable = $prefix . 'filecache';
            $shareTable = $prefix . 'share';
            $this->logger->info('NCbotwebhooks: richObject step=tables', ['app' => self::APP_ID, 'filecache' => $fileCacheTable, 'share' => $shareTable]);

            // Resolve the bot user's home storage ID from the storages table.
            // Storage IDs are instance-specific; hardcoding '1' breaks on many setups.
            $storageId = null;
            try {
                $storagesTable = $prefix . 'storages';
                $stmt = $pdo->prepare('SELECT "numeric_id" FROM "' . $storagesTable . '" WHERE "id" = ?', []);
                $stmt->execute(['home::' . $bot->getUID()]);
                $storageId = (int)$stmt->fetch(\PDO::FETCH_COLUMN);
                $stmt->closeCursor();
                $this->logger->info('NCbotwebhooks: richObject step=storageId', ['app' => self::APP_ID, 'storageId' => $storageId]);
            } catch (\Throwable $e) {
                $this->logger->warning('NCbotwebhooks: richObject step=storageIdFail', ['app' => self::APP_ID, 'error' => $e->getMessage()]);
            }

            $this->logger->info('NCbotwebhooks: richObject step=prepareSelect', ['app' => self::APP_ID]);
            $stmt = $pdo->prepare(
                'SELECT fileid, path, mimetype, permissions, size, storage'
                . ' FROM "' . $fileCacheTable . '"'
                . ' WHERE "path_hash" = ?',
            );
            $pathHash = md5($fileCachePath);
            $this->logger->info('NCbotwebhooks: richObject step=executeSelect', ['app' => self::APP_ID, 'pathHash' => $pathHash, 'path' => $fileCachePath]);
            $stmt->execute([$pathHash]);
            $this->logger->info('NCbotwebhooks: richObject step=fetch', ['app' => self::APP_ID]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            $this->logger->info('NCbotwebhooks: richObject step=rowFetched', ['app' => self::APP_ID, 'found' => $row !== false, 'columns' => array_keys($row ?: []), 'data' => $row]);

            // Path-based fallback: if path_hash lookup found nothing, try LIKE match on path column.
            // This catches cases where the filecache entry exists but path_hash didn't match.
            if (!$row) {
                $this->logger->info('NCbotwebhooks: richObject step=pathFallback', ['app' => self::APP_ID, 'path' => $fileCachePath]);
                $stmt2 = $pdo->prepare(
                    'SELECT fileid, path, mimetype, permissions, size, storage'
                    . ' FROM "' . $fileCacheTable . '"'
                    . ' WHERE "path" LIKE ?',
                );
                $stmt2->execute([self::IMAGES_DIR . '/%']);
                $rows = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
                $stmt2->closeCursor();
                $this->logger->info('NCbotwebhooks: richObject step=pathFallbackRows', ['app' => self::APP_ID, 'count' => count($rows)]);
                foreach ($rows as $r) {
                    if ($r['path'] === $fileCachePath) {
                        $row = $r;
                        $this->logger->info('NCbotwebhooks: richObject step=pathFallbackMatch', ['app' => self::APP_ID, 'fileId' => $r['fileid']]);
                        break;
                    }
                }
            }

            if (!$row) {
                // Manual filecache insertion — the file exists on disk but the entry is missing.
                // This can happen if the scanner didn't run or the entry was deleted.
                $this->logger->warning('NCbotwebhooks: richObject step=insertFc', ['app' => self::APP_ID, 'path' => $fileCachePath, 'storageId' => $storageId]);
                try {
                    // Look up mimetype ID from the mimetype lookup table.
                    // In NC33, mimetype is a bigint (ID), not a string.
                    $mimeTypeTable = $prefix . 'mimetypes';
                    $stmtMt = $pdo->prepare('SELECT "id" FROM "' . $mimeTypeTable . '" WHERE "mimetype" = ?');
                    $stmtMt->execute([$mimeType]);
                    $mimeTypeId = (int)$stmtMt->fetch(\PDO::FETCH_COLUMN);
                    $stmtMt->closeCursor();
                    if ($mimeTypeId <= 0) {
                        $mimeTypeId = 1; // fallback
                    }

                    // Look up mimepart ID (top-level type, e.g. 'image' from 'image/jpeg').
                    $mimePart = explode('/', $mimeType)[0];
                    $stmtMp = $pdo->prepare('SELECT "id" FROM "' . $mimeTypeTable . '" WHERE "mimetype" = ?');
                    $stmtMp->execute([$mimePart]);
                    $mimePartId = (int)$stmtMp->fetch(\PDO::FETCH_COLUMN);
                    $stmtMp->closeCursor();
                    if ($mimePartId <= 0) {
                        $mimePartId = 1; // fallback
                    }

                    // Look up the room directory fileid to use as parent.
                    // filecache 'path' stores relative paths without leading slash.
                    $roomDirPath = self::IMAGES_DIR . '/' . $roomToken;
                    $roomDirHash = md5($roomDirPath);
                    $stmtP = $pdo->prepare(
                        'SELECT "fileid" FROM "' . $fileCacheTable . '" WHERE "path_hash" = ? AND "name" = ?',
                    );
                    $stmtP->execute([$roomDirHash, $roomToken]);
                    $parentFileId = (int)$stmtP->fetch(\PDO::FETCH_COLUMN);
                    $stmtP->closeCursor();
                    if ($parentFileId <= 0) {
                        // Parent not in filecache yet — use 0 (root) as fallback.
                        $parentFileId = 0;
                    }

                    $stmtInsert = $pdo->prepare(
                        'INSERT INTO "' . $fileCacheTable . '"'
                        . ' ("storage","path","path_hash","parent","name","mimetype","mimepart","size","mtime","storage_mtime","encrypted","unencrypted_size","etag","permissions")'
                        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    );
                    $stmtInsert->execute([
                        $storageId ?: 1,
                        $fileCachePath,
                        $pathHash,
                        $parentFileId,
                        $safeFilename,
                        $mimeTypeId,
                        $mimePartId,
                        0, // size (not yet known at this point)
                        (int)$this->config->getSystemValueInt('phpfileclient_mapping_localtime', time()),
                        (int)$this->config->getSystemValueInt('phpfileclient_mapping_localtime', time()),
                        0, // encrypted
                        0, // unencrypted_size
                        '', // etag
                        \OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_UPDATE | \OCP\Constants::PERMISSION_DELETE,
                    ]);
                    $fileId = (int)$pdo->lastInsertId($prefix . 'filecache_fileid_seq');
                    $this->logger->info('NCbotwebhooks: richObject step=fcInserted', ['app' => self::APP_ID, 'fileId' => $fileId]);
                } catch (\Throwable $e) {
                    $this->logger->error('NCbotwebhooks: richObject step=fcInsertFail', ['app' => self::APP_ID, 'error' => $e->getMessage()]);
                    return null;
                }
            } else {
                $fileId = (int)$row['fileid'];
            }

            $fileMimeType = $row !== false ? ($row['mimetype'] ?? $mimeType) : $mimeType;
            $this->logger->info('NCbotwebhooks: richObject step=fileFound', ['app' => self::APP_ID, 'fileId' => $fileId]);

            $this->logger->info('NCbotwebhooks: richObject step=prepareInsert', ['app' => self::APP_ID]);

            // Resolve the actual Node object so createShare() doesn't have to
            // lazily resolve it via getFirstNodeById() which can fail on
            // LazyUserFolder or with manually-inserted filecache entries.
            $node = null;
            try {
                $userFolder = $this->rootFolder->getUserFolder($bot->getUID());
                $node = $userFolder->get($fileCachePath);
                $this->logger->info('NCbotwebhooks: richObject step=nodeResolved', ['app' => self::APP_ID, 'nodeType' => get_class($node)]);
            } catch (\Throwable $e) {
                $this->logger->warning('NCbotwebhooks: richObject step=nodeResolveFail', ['app' => self::APP_ID, 'error' => $e->getMessage()]);
            }

            // NOTE: Share creation is deferred to postToRoom() so we can set
            // the session-based actor overwrite before createShare() fires.
            // buildRichObject() now generates a placeholder shareId that will
            // be replaced after createShare() returns in postToRoom().
            $shareId = null;

            // Compute actual file size from disk (filecache size may be 0 if manually inserted).
            $actualSize = 0;
            try {
                $basePath = $this->config->getSystemValueString('datadirectory', '/var/www/nextcloud/data');
                $filePath = rtrim($basePath, '/') . '/' . ltrim($bot->getUID(), '/') . '/files/' . $fileCachePath;
                if (file_exists($filePath)) {
                    $s = filesize($filePath);
                    if ($s !== false && $s > 0) {
                        $actualSize = $s;
                    }
                    // Update filecache entry with actual size and etag so Talk server
                    // can resolve the fileId for rich object embedding.
                    try {
                        $fileCacheTable = $prefix . 'filecache';
                        $etag = substr(md5_file($filePath) . (string)(int)$this->config->getSystemValueInt('phpfileclient_mapping_localtime', time()), 0, 40);
                        $this->db->executeUpdate(
                            'UPDATE "' . $fileCacheTable . '" SET "size" = ?, "unencrypted_size" = ?, "etag" = ?, "mtime" = ? WHERE "fileid" = ?',
                            [$actualSize, $actualSize, $etag, (int)$this->config->getSystemValueInt('phpfileclient_mapping_localtime', time()), $fileId],
                        );
                        $this->logger->info('NCbotwebhooks: richObject step=filecacheUpdated', ['app' => self::APP_ID, 'fileId' => $fileId, 'size' => $actualSize, 'etag' => $etag]);
                    } catch (\Throwable $e) {
                        $this->logger->warning('NCbotwebhooks: richObject step=filecacheUpdateFail', ['app' => self::APP_ID, 'error' => $e->getMessage()]);
                    }
                }
            } catch (\Throwable $e) {
                // Final fallback: 0
            }

            // TYPE_ROOM shares don't produce a public /s/{token} URL.
            // The file_shared system message with the share ID is the
            // primary mechanism for embedding the image in Talk chat.
            $fullPublicUrl = '';
            $fullDownloadUrl = '';

            return [
                'fileId' => $fileId,
                'mimeType' => $mimeType,
                'publicUrl' => $fullPublicUrl,
                'downloadUrl' => $fullDownloadUrl,
                'shareToken' => null,
                'shareId' => $shareId ?? null,
                'filename' => $safeFilename,
                'fileCachePath' => $fileCachePath,
                'actualSize' => $actualSize,
                'node' => $node,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to build rich object (Exception): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), ['app' => self::APP_ID]);
            $this->logger->info('NCbotwebhooks: buildRichObject EXIT exception: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')', ['app' => self::APP_ID]);
            return null;
        } catch (\Error $e) {
            $this->logger->warning('NCbotwebhooks: buildRichObject ERROR=' . get_class($e) . ' msg=' . $e->getMessage() . ' file=' . $e->getFile() . ':' . $e->getLine(), ['app' => self::APP_ID]);
            return null;
        }
        $this->logger->info('NCbotwebhooks: buildRichObject EXIT success', ['app' => self::APP_ID]);
    }

    // ── Chat API: post message to Talk room ──────────────────────

    /**
     * Post a message to a Talk room.
     *
     * When rich objects (uploaded images) are provided, a file_shared
     * system message is sent alongside the text so Talk's SystemMessage
     * parser embeds the image inline.
     *
     * @param string $roomToken Talk room token
     * @param string $message Message text
     * @param string $senderName Discord sender name (embedded in message text)
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
            $this->logger->error('NCbotwebhooks: bot password not configured', ['app' => self::APP_ID]);
            return false;
        }

        // Check bot is enabled for this room (via AppConfig)
        if (!$this->isBotEnabledForRoom($roomToken)) {
            $this->logger->warning('NCbotwebhooks: bot not enabled for room', [
                'app' => self::APP_ID,
                'room_token' => $roomToken,
            ]);
            return false;
        }

        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            $this->logger->error('NCbotwebhooks: base URL not configured', ['app' => self::APP_ID]);
            return false;
        }

        // Get bot's actual display name from the room participant record.
        // In Talk 14+, actorDisplayName MUST match the participant's display name
        // or the message is silently dropped.
        $botDisplayName = $this->getBotDisplayNameForRoom($roomToken);

        // Basic auth: base64('talk-bot:' . bot_password)
        $credentials = base64_encode('talk-bot:' . $botPassword);

        // Build a file_shared system message with the share ID and metaData
        // so Talk's SystemMessage parser can resolve the file via the room
        // share. RecordingService::shareToChat() uses the same pattern.
        $richObject = $richObjects[0] ?? null;
        $shareId = (string)($richObject['shareId'] ?? '');
        $mimeType = $richObject['mimeType'] ?? 'application/octet-stream';
        $messageType = match (true) {
            str_starts_with($mimeType, 'image/') => 'comment',
            str_starts_with($mimeType, 'video/') => 'comment',
            str_starts_with($mimeType, 'audio/') => 'comment',
            default => 'comment',
        };
        $systemMessage = json_encode([
            'message' => 'file_shared',
            'parameters' => [
                'share' => $shareId !== '' ? $shareId : null,
                'metaData' => [
                    'mimeType' => $mimeType,
                    'messageType' => $messageType,
                ],
            ],
        ]);

        // Get the Room object — required for ChatManager::addSystemMessage().
        // We use getRoomByToken() which works without authentication.
        $room = null;
        try {
            $room = $this->talkManager->getRoomByToken($roomToken);
        } catch (\Exception $e) {
            $this->logger->error('NCbotwebhooks: failed to get room for token ' . $roomToken, [
                'app' => self::APP_ID,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $this->logger->info('NCbotwebhooks: sending file_shared message to Talk', [
            'app' => self::APP_ID,
            'room_token' => $roomToken,
            'system_message' => $systemMessage,
            'message_text' => $message,
            'debug' => [
                'richObject_keys' => array_keys($richObject ?? []),
                'richObject_fileId' => $richObject['fileId'] ?? null,
                'richObject_shareId' => $richObject['shareId'] ?? null,
                'richObject_fileCachePath' => $richObject['fileCachePath'] ?? null,
                'richObject_downloadUrl' => $richObject['downloadUrl'] ?? null,
                'richObject_publicUrl' => $richObject['publicUrl'] ?? null,
                'richObject_shareToken' => $richObject['shareToken'] ?? null,
                'richObject_filename' => $richObject['filename'] ?? null,
                'richObject_mimeType' => $richObject['mimeType'] ?? null,
                'shareId_used' => $shareId,
            ],
        ]);

        // Send the text message (with appended share URLs) via HTTP API so
        // Talk's @RequireParticipant middleware creates/finds the bot
        // participant record, and the message gets proper moderation checks.
        // This provides the human-readable text that the system message
        // does not include.
        if (trim($message) !== '') {
            $textEndpoint = $baseUrl . '/ocs/v2.php/apps/spreed/api/v1/chat/' . $roomToken
                . '?message=' . urlencode($message)
                . ($botDisplayName ? '&actorDisplayName=' . urlencode($botDisplayName) : '');

            try {
                $client = $this->clientService->newClient();
                $textResponse = $client->post($textEndpoint, [
                    'headers' => [
                        'OCS-Expect-Formatted' => 'json',
                        'OCS-APIRequest' => '1',
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Basic ' . $credentials,
                    ],
                    'body' => '{}',
                    'nextcloud' => [
                        'allow_local_address' => true,
                    ],
                ]);

                $textStatus = $textResponse->getStatusCode();
                if ($textStatus >= 200 && $textStatus < 300) {
                    $this->logger->info('NCbotwebhooks: text message posted to room ' . $roomToken, [
                        'app' => self::APP_ID,
                    ]);
                } else {
                    $this->logger->warning('NCbotwebhooks: text message POST returned ' . $textStatus, [
                        'app' => self::APP_ID,
                        'room_token' => $roomToken,
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->warning('NCbotwebhooks: text message POST failed: ' . $e->getMessage(), [
                    'app' => self::APP_ID,
                    'room_token' => $roomToken,
                ]);
            }
        }

        // Overwrite Talk actor so the TYPE_ROOM share created below does not
        // trigger the "guest" system message bug in Talk 23.0.6 where
        // fixMimeTypeOfVoiceMessage() fires on every TYPE_ROOM share.
        $this->setSessionOverwrite('talk-bot');

        // Create the room share AFTER text POST so the session overwrite is
        // active when the ShareCreatedEvent fires. This prevents the guest
        // message from being posted alongside the bot's message.
        if ($richObject !== null && $richObject['fileId'] !== null) {
            $bot = $this->userManager->get('talk-bot');
            if (!$bot) {
                $this->logger->error('NCbotwebhooks: talk-bot user not found, cannot create share', [
                    'app' => self::APP_ID,
                ]);
                $this->clearSessionOverwrite();
                return false;
            }
            try {
                // Switch user context so createShare() can resolve the file
                // path from the bot's filesystem (webhook runs as anonymous).
                $currentUser = $this->userSession->getUser();
                $this->userSession->setUser($bot);

                $share = $this->shareManager->newShare();
                // Use the already-resolved node to bypass getFirstNodeById
                // which can fail on LazyUserFolder or with manually-inserted
                // filecache entries. Fall back to setNodeId if node is null.
                if ($richObject['node'] !== null) {
                    $share->setNode($richObject['node'])
                        ->setShareType(IShare::TYPE_ROOM)
                        ->setSharedBy($bot->getUID())
                        ->setShareOwner($bot->getUID())
                        ->setSharedWith($roomToken)
                        ->setPermissions(\OCP\Constants::PERMISSION_READ);
                } else {
                    $share->setNodeId((int)$richObject['fileId'])
                        ->setShareType(IShare::TYPE_ROOM)
                        ->setSharedBy($bot->getUID())
                        ->setShareOwner($bot->getUID())
                        ->setSharedWith($roomToken)
                        ->setPermissions(\OCP\Constants::PERMISSION_READ);
                }

                $created = $this->shareManager->createShare($share);

                $actualShareId = (int)$created->getId();
                $richObject['shareId'] = $actualShareId;

                // Update the system message payload with the real shareId
                $systemMessage = json_encode([
                    'message' => 'file_shared',
                    'parameters' => [
                        'share' => (string)$actualShareId,
                        'metaData' => [
                            'mimeType' => $mimeType,
                            'messageType' => $messageType,
                        ],
                    ],
                ]);

                $this->logger->info('NCbotwebhooks: room share created with session overwrite', [
                    'app' => self::APP_ID,
                    'shareId' => $actualShareId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('NCbotwebhooks: deferred share creation failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), [
                    'app' => self::APP_ID,
                    'fileId' => (int)$richObject['fileId'],
                    'roomToken' => $roomToken,
                    'botUid' => $bot ? $bot->getUID() : 'null',
                    'trace' => $e->getTraceAsString(),
                ]);
            } finally {
                // Always restore original user context
                $this->userSession->setUser($currentUser);
            }

            // Clear the session overwrite to avoid leaking to other requests
            $this->clearSessionOverwrite();
        }

        // Use ChatManager::addSystemMessage() directly (PHP API) so the
        // system message is created with the correct verb ('object_shared')
        // and the attachment entry is created. The HTTP sendMessage() endpoint
        // only creates regular 'comment' messages — it cannot create system
        // messages.
        //
        // TODO: Re-enable this call when upgrading to a Talk version where
        // fixMimeTypeOfVoiceMessage() properly filters non-voice shares.
        // Currently commented out because:
        // 1. The session overwrite (set above) makes the event listener's
        //    guest message appear as 'talk-bot' instead of 'guest'.
        // 2. This gives us the correct actor without needing our own
        //    addSystemMessage() call.
        // 3. Keeping both would produce a duplicate talk-bot message.
        // The event listener's file_shared message is transformed by
        // ChatManager into object_shared verb and resolved by the
        // SystemMessage parser — images still embed correctly.
        //
        // try {
        //     $bot = $this->userManager->get('talk-bot');
        //     $actorType = Attendee::ACTOR_USERS;
        //     $actorId = 'talk-bot';
        //
        //     $this->chatManager->addSystemMessage(
        //         $room,
        //         null, // participant — not needed for webhook bot; mention perms check uses null-safe operator
        //         $actorType,
        //         $actorId,
        //         $systemMessage,
        //         new \DateTime('now', new \DateTimeZone('UTC')),
        //         true, // sendNotifications
        //     );
        //     $this->logger->info('NCbotwebhooks: system message posted to room ' . $roomToken, [
        //         'app' => self::APP_ID,
        //     ]);
        //     // Verify the system message was created with correct fileId/shareId
        //     $this->logger->info('NCbotwebhooks: system message verification', [
        //         'app' => self::APP_ID,
        //         'fileId' => $richObject['fileId'] ?? null,
        //         'shareId' => $shareId,
        //         'fileCachePath' => $richObject['fileCachePath'] ?? null,
        //         'botUserExists' => $bot !== null,
        //         'botUserId' => $bot ? $bot->getUID() : 'null',
        //     ]);
        // } catch (\Exception $e) {
        //     $this->logger->error('NCbotwebhooks: addSystemMessage failed: ' . $e->getMessage(), [
        //         'app' => self::APP_ID,
        //         'room_token' => $roomToken,
        //     ]);
        //     return false;
        // }

        return true;
    }

    /**
     * Check if the bot is enabled for a specific room (via AppConfig).
     */
    public function isBotEnabledForRoom(string $roomToken): bool {
        $rooms = $this->getRooms();
        return isset($rooms[$roomToken]);
    }

    /**
     * Get the bot's display name as registered in a Talk room.
     * Falls back to 'talk-bot' if not found.
     */
    public function getBotDisplayNameForRoom(string $roomToken): string {
        // Resolve token → room_id via direct DB query (TalkManager::getRoomForToken removed in Talk 14+)
        $roomTable = $this->detectTalkTableFromCatalog('talk_rooms', 'spreed_room');
        if ($roomTable === null) {
            return 'talk-bot';
        }

        try {
            $stmt = $this->db->executeQuery(
                'SELECT id FROM "' . $roomTable . '" WHERE token = ?',
                [$roomToken],
            );
            $roomId = (int)$stmt->fetchOne();
            $stmt->closeCursor();

            if ($roomId === 0) {
                return 'talk-bot';
            }

            $attendee = $this->attendeeMapper->findByActor($roomId, Attendee::ACTOR_USERS, 'talk-bot');
            return $attendee->getDisplayName() ?: 'talk-bot';
        } catch (DoesNotExistException $e) {
            return 'talk-bot';
        } catch (\Exception $e) {
            $this->logger->warning('NCbotwebhooks: failed to get bot display name for room ' . $roomToken, [
                'app' => self::APP_ID,
                'error' => $e->getMessage(),
            ]);
            return 'talk-bot';
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
            // LazyFolder compatibility — use get() with fallback
            $imagesDir = null;
            try {
                $imagesDir = $userFolder->get(self::IMAGES_DIR);
            } catch (\OCP\Files\NotFoundException $e) {
                return 0; // No images directory yet
            }
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
            $validation = $this->validateBotPassword($config['bot_password']);
            if (!$validation['valid']) {
                throw new \Exception($validation['error']);
            }
            $this->setBotPassword($config['bot_password']);
        }

        if (isset($config['retention_days'])) {
            $this->setRetentionDays((int) $config['retention_days']);
        }

        if (isset($config['rooms'])) {
            $rooms = $config['rooms'];
        } else {
            $rooms = $this->getRooms();
        }

        // Remove explicitly disabled rooms from the configured set
        if (isset($config['disabled_rooms']) && is_array($config['disabled_rooms'])) {
            foreach ($config['disabled_rooms'] as $token) {
                unset($rooms[$token]);
            }
        }
        $this->setRooms($rooms);

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
     * Uses AttendeeMapper directly to avoid injecting ParticipantService (17 deps).
     */
    private function ensureBotParticipants(): void {
        $rooms = $this->getRooms();
        if (empty($rooms)) {
            return;
        }

        // Get the talk-bot user
        $botUser = $this->userManager->get('talk-bot');
        if ($botUser === null) {
            $this->logger->warning('NCbotwebhooks: talk-bot user not found', ['app' => self::APP_ID]);
            return;
        }

        // Get Talk DB table prefix
        $sysPrefix = $this->config->getSystemValueString('dbtableprefix', '');
        $talkPrefix = $this->config->getAppValue('spreed', 'databaseprefix', $sysPrefix);
        $attendeeTable = $talkPrefix . 'talk_attendee';

        // Detect Talk rooms table name
        $roomTable = $this->detectTalkTableFromCatalog('talk_rooms', 'spreed_room');
        if ($roomTable === null) {
            $this->logger->warning('NCbotwebhooks: Talk rooms table not found, skipping participant setup', [
                'app' => self::APP_ID,
            ]);
            return;
        }

        foreach (array_keys($rooms) as $token) {
            try {
                // Resolve token → room_id via direct DB query (TalkManager::getRoomForToken removed in Talk 14+)
                $stmt = $this->db->executeQuery(
                    'SELECT id FROM "' . $roomTable . '" WHERE token = ?',
                    [$token],
                );
                $roomId = (int)$stmt->fetchOne();
                $stmt->closeCursor();

                if ($roomId === 0) {
                    $this->logger->warning('NCbotwebhooks: room token not found in database', [
                        'app' => self::APP_ID,
                        'token' => $token,
                    ]);
                    continue;
                }

                // Check if talk-bot is already an attendee
                try {
                    $this->attendeeMapper->findByActor($roomId, Attendee::ACTOR_USERS, 'talk-bot');
                    // Already exists — nothing to do
                    continue;
                } catch (DoesNotExistException $e) {
                    // Not found — will create
                }

                // Create attendee record directly
                $newAttendee = new Attendee();
                $newAttendee->setRoomId($roomId);
                $newAttendee->setActorType(Attendee::ACTOR_USERS);
                $newAttendee->setActorId('talk-bot');
                $newAttendee->setDisplayName('talk-bot');
                $newAttendee->setParticipantType(\OCA\Talk\Participant::PERMISSIONS_DEFAULT);
                $newAttendee->setPermissions(\OCA\Talk\Participant::PERMISSIONS_MAX_DEFAULT);
                $newAttendee->setNotificationLevel(3); // Full notification level
                $newAttendee->setFavorite(false);
                $newAttendee->setArchived(false);

                try {
                    $this->attendeeMapper->insert($newAttendee);
                    $this->logger->info('NCbotwebhooks: added talk-bot as participant in room ' . $token, [
                        'app' => self::APP_ID,
                        'room_id' => $roomId,
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('NCbotwebhooks: failed to insert attendee for room ' . $token, [
                        'app' => self::APP_ID,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->warning('NCbotwebhooks: failed to add talk-bot to room ' . $token, [
                    'app' => self::APP_ID,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process a list of image URLs — download, upload, and create rich objects.
     * Returns array of rich objects.
     */
    private function processImageUrls(array $urls, string $roomToken): array {
        $richObjects = [];
        foreach ($urls as $imageUrl) {
            $this->logger->info('NCbotwebhooks: attempting image download', [
                'app' => self::APP_ID,
                'url' => $imageUrl,
            ]);
            $imageData = $this->downloadImage($imageUrl);
            if ($imageData === null) {
                $this->logger->warning('NCbotwebhooks: image download returned null', [
                    'app' => self::APP_ID,
                    'url' => $imageUrl,
                ]);
                continue;
            }

            $fileName = basename(parse_url($imageUrl, PHP_URL_PATH)) ?: 'attachment';
            $this->logger->info('NCbotwebhooks: uploading image', [
                'app' => self::APP_ID,
                'roomToken' => $roomToken,
                'fileName' => $fileName,
                'mimeType' => $imageData['mimeType'],
                'size' => strlen($imageData['data']),
            ]);
            $uploadPath = $this->uploadImage($roomToken, $fileName, $imageData['data'], $imageData['mimeType']);
            if ($uploadPath !== null) {
                $this->logger->info('NCbotwebhooks: image uploaded', [
                    'app' => self::APP_ID,
                    'path' => $uploadPath,
                ]);
                $this->logger->info('NCbotwebhooks: building rich object', [
                    'app' => self::APP_ID,
                    'path' => $uploadPath,
                ]);
                $richObj = $this->buildRichObject($uploadPath, $imageData['mimeType'], $roomToken);
                if ($richObj !== null) {
                    $this->logger->info('NCbotwebhooks: rich object built', [
                        'app' => self::APP_ID,
                        'richObject' => json_encode($richObj),
                    ]);
                    $richObjects[] = $richObj;
                }
            } else {
                $this->logger->warning('NCbotwebhooks: image upload returned null', [
                    'app' => self::APP_ID,
                    'fileName' => $fileName,
                ]);
            }
        }
        return $richObjects;
    }
}
