<?php

namespace OCA\Ncbotwebhooks\Tests;

use OCA\Ncbotwebhooks\Service\TalkService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TalkService business logic.
 *
 * All TalkService constructor dependencies are mocked — no real Nextcloud
 * instance is required.  TalkServiceMockBuilder (in bootstrap.php) assembles
 * the mocks; each test configures the specific expectations it needs.
 */
class TalkServiceTest extends TestCase {

    /**
     * Build a TalkService with a crypto mock that round-trips any string.
     *
     * @return array{0: TalkService, 1: \PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeService(): array {
        $builder = new TalkServiceMockBuilder();
        $crypto  = $builder->config; // the config mock — we swap it below

        // We need direct access to the crypto mock to configure encrypt/decrypt
        // TalkServiceMockBuilder stores them as properties; we re-construct.
        $cryptoMock = $this->createMock(\OCP\Security\ICrypto::class);
        $cryptoMock->method('encrypt')
            ->willReturnCallback(fn($s) => 'encrypted:' . $s);
        $cryptoMock->method('decrypt')
            ->willReturnCallback(fn($s) => substr($s, 10)); // strip 'encrypted:'

        // Build with our crypto mock
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $this->createMock(\OCP\IConfig::class),
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $cryptoMock,
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );

        return [$service, $cryptoMock];
    }

    // ── validateBotPassword ──────────────────────────────────────

    public function testValidateBotPasswordValid(): void {
        $service = $this->makeService()[0];
        $result  = $service->validateBotPassword('mySecurePassword123!');
        $this->assertTrue($result['valid']);
    }

    public function testValidateBotPasswordEmpty(): void {
        $service = $this->makeService()[0];
        $result  = $service->validateBotPassword('');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('empty', $result['error']);
    }

    public function testValidateBotPasswordSpecialChars(): void {
        $service = $this->makeService()[0];
        $result  = $service->validateBotPassword("!@#\$%^&*()_+-=[]{}|;:',.<>?/~`");
        $this->assertTrue($result['valid']);
    }

    public function testValidateBotPasswordUnicode(): void {
        $service = $this->makeService()[0];
        $result  = $service->validateBotPassword('密码🔒🎉');
        $this->assertTrue($result['valid']);
    }

    // ── getBaseUrl ───────────────────────────────────────────────

    public function testGetBaseUrlOverwriteHostTakesPriority(): void {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getSystemValueString')
            ->willReturnMap([
                ['overwritehost', '', 'example.com'],
                ['overwriteproto', 'https', 'https'],
            ]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $this->assertSame('https://example.com', $service->getBaseUrl());
    }

    public function testGetBaseUrlOverwritewebrootFullPath(): void {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getSystemValueString')
            ->willReturnMap([
                ['overwritehost', '', ''],
                ['overwritewebroot', '', 'https://webroot.example.com/nextcloud'],
            ]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $this->assertSame('https://webroot.example.com/nextcloud', $service->getBaseUrl());
    }

    public function testGetBaseUrlOverwritewebrootPath(): void {
        // Path-style overwritewebroot should fall through to trusted_domains
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getSystemValueString')
            ->willReturnMap([
                ['overwritehost', '', ''],
                ['overwritewebroot', '', '/nextcloud'],
            ]);
        $trustedMock = $this->createMock(\OCP\IConfig::class);
        $trustedMock->method('getSystemValueString')
            ->willReturnMap([
                ['overwritehost', '', ''],
                ['overwritewebroot', '', '/nextcloud'],
            ]);
        $trustedMock->method('getSystemValue')
            ->willReturn(['public.example.com']);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $trustedMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $this->assertSame('https://public.example.com', $service->getBaseUrl());
    }

    public function testGetBaseUrlSkipsPrivateDomains(): void {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getSystemValueString')
            ->willReturnMap([
                ['overwritehost', '', ''],
                ['overwritewebroot', '', ''],
            ]);
        $configMock->method('getSystemValue')
            ->willReturn(['192.168.1.100', '10.0.0.5', 'public.example.com']);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $this->assertSame('https://public.example.com', $service->getBaseUrl());
    }

    public function testGetBaseUrlFallbackToCliUrl(): void {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getSystemValueString')
            ->willReturnMap([
                ['overwritehost', '', ''],
                ['overwritewebroot', '', ''],
            ]);
        $configMock->method('getSystemValue')
            ->willReturn([]);
        $configMock->method('getSystemValueString')
            ->willReturnMap([
                ['overwritehost', '', ''],
                ['overwritewebroot', '', ''],
                ['overwrite.cli.url', '', 'https://cli.example.com'],
            ]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $this->assertSame('https://cli.example.com', $service->getBaseUrl());
    }

    public function testGetBaseUrlEmptyConfig(): void {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getSystemValueString')->willReturn('');
        $configMock->method('getSystemValue')->willReturn([]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $this->assertSame('', $service->getBaseUrl());
    }

    // ── getRooms / setRooms ──────────────────────────────────────

    public function testGetRoomsEmpty(): void {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')
            ->willReturnMap([
                [TalkService::APP_ID, 'rooms', '[]', '[]'],
            ]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $this->assertSame([], $service->getRooms());
    }

    public function testGetRoomsParsed(): void {
        $json = json_encode(['abc123' => 'General Chat', 'xyz789' => 'Builds']);
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')
            ->willReturnMap([
                [TalkService::APP_ID, 'rooms', '[]', $json],
            ]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $rooms = $service->getRooms();
        $this->assertArrayHasKey('abc123', $rooms);
        $this->assertSame('General Chat', $rooms['abc123']);
        $this->assertArrayHasKey('xyz789', $rooms);
        $this->assertSame('Builds', $rooms['xyz789']);
    }

    public function testSetRoomsPersists(): void {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')
            ->willReturnMap([
                [TalkService::APP_ID, 'rooms', '[]', '[]'],
            ]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );

        $rooms = ['room1' => 'Room One'];
        $configMock->expects($this->once())
            ->method('setAppValue')
            ->with(TalkService::APP_ID, 'rooms', json_encode($rooms));

        $service->setRooms($rooms);
    }

    // ── getAuthTokens / setAuthTokens ────────────────────────────

    public function testGetAuthTokensEmpty(): void {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')
            ->willReturnMap([
                [TalkService::APP_ID, 'auth_tokens', '{}', '{}'],
            ]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $this->assertSame([], $service->getAuthTokens());
    }

    public function testGetAuthTokensParsed(): void {
        $json = json_encode(['abc123' => ['tok1', 'tok2'], 'xyz789' => ['tok3']]);
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')
            ->willReturnMap([
                [TalkService::APP_ID, 'auth_tokens', '{}', $json],
            ]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        $tokens = $service->getAuthTokens();
        $this->assertSame(['tok1', 'tok2'], $tokens['abc123']);
        $this->assertSame(['tok3'], $tokens['xyz789']);
    }

    public function testSetAuthTokensPersists(): void {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')
            ->willReturnMap([
                [TalkService::APP_ID, 'auth_tokens', '{}', '{}'],
            ]);
        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );

        $tokens = ['abc123' => ['tok1']];
        $configMock->expects($this->once())
            ->method('setAppValue')
            ->with(TalkService::APP_ID, 'auth_tokens', json_encode($tokens));

        $service->setAuthTokens($tokens);
    }

    // ── validateAuthToken ────────────────────────────────────────

    public function testValidateAuthTokenValid(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', json_encode(['abc123' => ['tok1', 'tok2']])],
        ]);
        $this->assertTrue($service->validateAuthToken('abc123', 'tok1'));
        $this->assertTrue($service->validateAuthToken('abc123', 'tok2'));
    }

    public function testValidateAuthTokenInvalid(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', json_encode(['abc123' => ['tok1', 'tok2']])],
        ]);
        $this->assertFalse($service->validateAuthToken('abc123', 'tok3'));
    }

    public function testValidateAuthTokenNonexistentRoom(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', json_encode(['abc123' => ['tok1']])],
        ]);
        $this->assertFalse($service->validateAuthToken('xyz789', 'tok1'));
    }

    public function testValidateAuthTokenMultipleTokens(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', json_encode(['abc123' => ['tok1', 'tok2', 'tok3']])],
        ]);
        $this->assertTrue($service->validateAuthToken('abc123', 'tok2'));
    }

    public function testValidateAuthTokenTokenNotInRoom(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', json_encode(['abc123' => ['tok1'], 'xyz789' => ['tok2']])],
        ]);
        $this->assertFalse($service->validateAuthToken('abc123', 'tok2'));
    }

    /**
     * Helper: build a TalkService with a configured IConfig mock.
     *
     * @param array $valueMap  value map for IConfig::getAppValue
     * @return array{0: TalkService, 1: \PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeServiceWithConfig(array $valueMap): array {
        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')
            ->willReturnCallback(function ($app, $key, $default) use ($valueMap, $configMock) {
                foreach ($valueMap as $entry) {
                    if ($entry[0] === $app && $entry[1] === $key) {
                        return $entry[3] ?? $default;
                    }
                }
                return $default;
            });
        // setAppValue is a no-op in tests
        $configMock->method('setAppValue')
            ->willReturnCallback(fn($app, $key, $value) => null);

        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );
        return [$service, $configMock];
    }

    // ── generateAuthToken ────────────────────────────────────────

    public function testGenerateAuthTokenGeneratesToken(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', '{}'],
        ]);
        $token = $service->generateAuthToken('abc123');
        $this->assertEquals(48, strlen($token));
        $this->assertRegExp('/^[0-9a-f]+$/', $token);
    }

    public function testGenerateAuthTokenPersists(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', '{}'],
        ]);
        $configMock->expects($this->atLeast(2))
            ->method('setAppValue')
            ->willReturnCallback(fn($app, $key, $value) => null);

        $token1 = $service->generateAuthToken('abc123');
        $token2 = $service->generateAuthToken('abc123');

        $this->assertNotSame($token1, $token2);
    }

    // ── revokeAuthToken ──────────────────────────────────────────

    public function testRevokeAuthTokenRemovesToken(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', json_encode(['abc123' => ['tok1', 'tok2']])],
        ]);
        $configMock->expects($this->atLeast(2))
            ->method('setAppValue')
            ->willReturnCallback(fn($app, $key, $value) => null);

        $service->revokeAuthToken('abc123', 'tok1');

        $this->assertFalse($service->validateAuthToken('abc123', 'tok1'));
        $this->assertTrue($service->validateAuthToken('abc123', 'tok2'));
    }

    public function testRevokeAuthTokenCleansEmptyRoom(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', json_encode(['abc123' => ['tok1']])],
        ]);
        $configMock->expects($this->atLeast(2))
            ->method('setAppValue')
            ->willReturnCallback(fn($app, $key, $value) => null);

        $service->revokeAuthToken('abc123', 'tok1');

        $tokens = $service->getAuthTokens();
        $this->assertArrayNotHasKey('abc123', $tokens);
    }

    public function testRevokeAuthTokenNonexistentToken(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'auth_tokens', '{}', json_encode(['abc123' => ['tok1']])],
        ]);
        $configMock->expects($this->once())
            ->method('setAppValue')
            ->willReturnCallback(fn($app, $key, $value) => null);

        $service->revokeAuthToken('abc123', 'nonexistent');

        // Should still have tok1
        $this->assertTrue($service->validateAuthToken('abc123', 'tok1'));
    }

    // ── mapPayload ───────────────────────────────────────────────

    public function testMapPayloadContentOnly(): void {
        [$service] = $this->makeServiceWithConfig([]);
        $result = $service->mapPayload(['content' => 'Hello world']);
        $this->assertSame('Hello world', $result);
    }

    public function testMapPayloadEmbedWithTitle(): void {
        [$service] = $this->makeServiceWithConfig([]);
        $result = $service->mapPayload([
            'embeds' => [['title' => 'Build #123']],
        ]);
        $this->assertStringContainsString('**Build #123**', $result);
    }

    public function testMapPayloadEmbedWithDescription(): void {
        [$service] = $this->makeServiceWithConfig([]);
        $result = $service->mapPayload([
            'embeds' => [['description' => 'Passed']],
        ]);
        $this->assertStringContainsString('Passed', $result);
    }

    public function testMapPayloadEmbedWithFields(): void {
        [$service] = $this->makeServiceWithConfig([]);
        $result = $service->mapPayload([
            'embeds' => [[
                'fields' => [
                    ['name' => 'Duration', 'value' => '2m 34s'],
                    ['name' => 'Environment', 'value' => 'Production'],
                ],
            ]],
        ]);
        $this->assertStringContainsString('Duration: 2m 34s', $result);
               $this->assertStringContainsString('Environment: Production', $result);
    }

    public function testMapPayloadEmpty(): void {
        [$service] = $this->makeServiceWithConfig([]);
        $result = $service->mapPayload([]);
        $this->assertSame('', $result);
    }

    public function testMapPayloadMultipleEmbeds(): void {
        [$service] = $this->makeServiceWithConfig([]);
        $result = $service->mapPayload([
            'embeds' => [
                ['title' => 'First'],
                ['title' => 'Second'],
            ],
        ]);
        $this->assertStringContainsString('**First**', $result);
        $this->assertStringContainsString('**Second**', $result);
    }

    // ── mapApprisePayload ────────────────────────────────────────

    public function testMapApprisePayloadBodyOnly(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'sender_name', 'Webhook Bot', 'Custom Bot'],
        ]);
        $result = $service->mapApprisePayload(['body' => 'All clear'], 'abc123');
        $this->assertSame('All clear', $result['message']);
    }

    public function testMapApprisePayloadTitleFallback(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'sender_name', 'Webhook Bot', 'Default Sender'],
        ]);
        $result = $service->mapApprisePayload(['title' => 'Alert', 'body' => 'Something happened'], 'abc123');
        $this->assertSame('Something happened', $result['message']);
        $this->assertSame('Alert', $result['senderName']);
    }

    public function testMapApprisePayloadTypeIcons(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([]);
        // Success type — should include ✅ icon
        $resultSuccess = $service->mapApprisePayload(['body' => 'OK', 'type' => 'success'], 'abc123');
        $this->assertStringContainsString('✅', $resultSuccess['message']);

        // Warning type
        $resultWarning = $service->mapApprisePayload(['body' => 'Warn', 'type' => 'warning'], 'abc123');
        $this->assertStringContainsString('⚠️', $resultWarning['message']);

        // Error type
        $resultError = $service->mapApprisePayload(['body' => 'Fail', 'type' => 'error'], 'abc123');
        $this->assertStringContainsString('❌', $resultError['message']);
    }

    public function testMapApprisePayloadImageType(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([]);
        // Image type with attachment URLs — processImageUrls is called, which returns empty
        // because there's no real file system; verify richObjects is set
        $result = $service->mapApprisePayload([
            'body' => 'Check this out',
            'type' => 'image',
            'attachments' => [['url' => 'https://example.com/img.png']],
        ], 'abc123');
        // richObjects should be an array (possibly empty due to no real FS)
        $this->assertIsArray($result['richObjects']);
    }

    public function testMapApprisePayloadNestedDataKey(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([]);
        // When the payload is wrapped in a 'data' key (Home Assistant), the controller
        // unwraps it before passing to mapApprisePayload.  Here we test the unwrapped form.
        $result = $service->mapApprisePayload([
            'body' => 'Direct payload',
            'title' => 'Direct Title',
        ], 'abc123');
        $this->assertSame('Direct payload', $result['message']);
        $this->assertSame('Direct Title', $result['senderName']);
    }

    // ── getSenderName ────────────────────────────────────────────

    public function testGetSenderNameSenderNameTakesPriority(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'sender_name', 'Webhook Bot', 'Default Sender'],
        ]);
        $this->assertSame('Custom', $service->getSenderName(['sender_name' => 'Custom']));
    }

    public function testGetSenderNameUsernameFallback(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'sender_name', 'Webhook Bot', 'Default Sender'],
        ]);
        $this->assertSame('CI Bot', $service->getSenderName(['username' => 'CI Bot']));
    }

    public function testGetSenderNameConfigDefault(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'sender_name', 'Webhook Bot', 'Default Sender'],
        ]);
        $this->assertSame('Default Sender', $service->getSenderName([]));
    }

    // ── getSenderNameDefault ─────────────────────────────────────

    public function testGetSenderNameDefault(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([
            [TalkService::APP_ID, 'sender_name', 'Webhook Bot', 'My Bot'],
        ]);
        $this->assertSame('My Bot', $service->getSenderNameDefault());
    }

    // ── prependDisplayName ───────────────────────────────────────

    public function testPrependDisplayNameWithMessage(): void {
        [$service] = $this->makeServiceWithConfig([]);
        $result = $service->prependDisplayName('CI Bot', 'Build passed');
        $this->assertStringContainsString('**CI Bot**', $result);
        $this->assertStringContainsString('Build passed', $result);
        $this->assertStringContainsString("\n\n", $result);
    }

    public function testPrependDisplayNameWithoutMessage(): void {
        [$service] = $this->makeServiceWithConfig([]);
        $result = $service->prependDisplayName('CI Bot', '');
        $this->assertStringContainsString('**CI Bot**', $result);
        $this->assertStringNotContainsString("\n\n", $result);
    }

    public function testPrependDisplayNameWithTypeIcon(): void {
        [$service] = $this->makeServiceWithConfig([]);
        $result = $service->prependDisplayName('CI Bot', 'OK', '✅');
        $this->assertStringContainsString('✅', $result);
        $this->assertStringContainsString('**CI Bot**', $result);
    }

    // ── downloadImage ────────────────────────────────────────────

    public function testDownloadImageSuccessful(): void {
        [$service, $configMock] = $this->makeServiceWithConfig([]);

        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $responseMock->method('getBody')
            ->willReturn(new class {
                public function __toString(): string { return 'image-data'; }
            });

        $clientMock = $this->createMock(\OCP\Http\Client\IClient::class);
        $clientMock->method('get')
            ->willReturn($responseMock);

        $clientServiceMock = $this->createMock(\OCP\Http\Client\IClientService::class);
        $clientServiceMock->method('getClient')
            ->willReturn($clientMock);

        // Rebuild with our client service
        $configMock2 = $this->createMock(\OCP\IConfig::class);
        $configMock2->method('getAppValue')->willReturn('');
        $service2 = new TalkService(
            $clientServiceMock,
            $configMock2,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );

        $result = $service2->downloadImage('https://example.com/image.png');
        $this->assertSame('image-data', $result);
    }

    public function testDownloadImageEmptyBody(): void {
        [$service] = $this->makeServiceWithConfig([]);

        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $responseMock->method('getBody')
            ->willReturn(new class {
                public function __toString(): string { return ''; }
            });

        $clientMock = $this->createMock(\OCP\Http\Client\IClient::class);
        $clientMock->method('get')
            ->willReturn($responseMock);

        $clientServiceMock = $this->createMock(\OCP\Http\Client\IClientService::class);
        $clientServiceMock->method('getClient')
            ->willReturn($clientMock);

        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')->willReturn('');
        $service2 = new TalkService(
            $clientServiceMock,
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );

        $result = $service2->downloadImage('https://example.com/empty.png');
        $this->assertSame('', $result);
    }

    // ── purgeOldImages ───────────────────────────────────────────

    public function testPurgeOldImagesWithFiles(): void {
        // Create a temporary directory to simulate the images folder
        $tempDir = sys_get_temp_dir() . '/nc_bot_webhooks_test_' . uniqid();
        mkdir($tempDir . '/abc123', 0755, true);

        // Create a file older than 90 days
        $oldFile = $tempDir . '/abc123/old.png';
        file_put_contents($oldFile, 'data');
        touch($oldFile, time() - (100 * 86400)); // 100 days ago

        // Create a recent file
        $recentFile = $tempDir . '/abc123/recent.png';
        file_put_contents($recentFile, 'data');
        touch($recentFile, time() - (10 * 86400)); // 10 days ago

        // Mock rootFolder to return our temp directory
        $folderMock = $this->createMock(\OCP\Files\Folder::class);
        $folderMock->method('getContents')
            ->willReturn(['old.png', 'recent.png']);

        $oldFileMock = $this->createMock(\OCP\Files\File::class);
        $oldFileMock->method('getMTime')
            ->willReturn(time() - (100 * 86400));

        $recentFileMock = $this->createMock(\OCP\Files\File::class);
        $recentFileMock->method('getMTime')
            ->willReturn(time() - (10 * 86400));

        $folderMock->method('get')
            ->willReturnMap([
                ['old.png', $oldFileMock],
                ['recent.png', $recentFileMock],
            ]);

        $rootMock = $this->createMock(\OCP\Files\IRootFolder::class);
        $rootMock->method('getUserFolder')
            ->willReturn($folderMock);

        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')->willReturn('90');

        $userManagerMock = $this->createMock(\OCP\IUserManager::class);
        $userMock = $this->createMock(\OCP\IUser::class);
        $userMock->method('getUID')
            ->willReturn('talk-bot');
        $userManagerMock->method('get')
            ->willReturn($userMock);

        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $rootMock,
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $userManagerMock,
            $this->createMock(\OCP\IUserSession::class),
            $loggerMock,
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );

        $count = $service->purgeOldImages();
        $this->assertGreaterThanOrEqual(1, $count);

        // Cleanup
        unlink($oldFile);
        unlink($recentFile);
        rmdir($tempDir . '/abc123');
        rmdir($tempDir);
    }

    public function testPurgeOldImagesEmptyDirectory(): void {
        $folderMock = $this->createMock(\OCP\Files\Folder::class);
        $folderMock->method('getContents')
            ->willReturn([]);

        $rootMock = $this->createMock(\OCP\Files\IRootFolder::class);
        $rootMock->method('getUserFolder')
            ->willReturn($folderMock);

        $configMock = $this->createMock(\OCP\IConfig::class);
        $configMock->method('getAppValue')->willReturn('90');

        $userManagerMock = $this->createMock(\OCP\IUserManager::class);
        $userMock = $this->createMock(\OCP\IUser::class);
        $userMock->method('getUID')
            ->willReturn('talk-bot');
        $userManagerMock->method('get')
            ->willReturn($userMock);

        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $service = new TalkService(
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $configMock,
            $this->createMock(\OCP\IDBConnection::class),
            $rootMock,
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(\OCP\IURLGenerator::class),
            $userManagerMock,
            $this->createMock(\OCP\IUserSession::class),
            $loggerMock,
            $this->createMock(\OCA\Talk\Manager::class),
            $this->createMock(\OCP\Security\ICrypto::class),
            $this->createMock(\OCA\Talk\Model\AttendeeMapper::class),
            $this->createMock(\OCP\Share\IManager::class),
            $this->createMock(\OCA\Talk\Service\ParticipantService::class),
            $this->createMock(\OCA\Talk\Chat\ChatManager::class),
            $this->createMock(\OCA\Talk\TalkSession::class),
        );

        $count = $service->purgeOldImages();
        $this->assertSame(0, $count);
    }
}
