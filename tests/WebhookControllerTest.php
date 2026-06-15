<?php

namespace OCA\Ncbotwebhooks\Test;

use OCA\Ncbotwebhooks\Controller\WebhookController;
use OCA\Ncbotwebhooks\Service\TalkService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IAppManager;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WebhookController HTTP endpoints.
 *
 * The controller reads from php://input internally, which can't be mocked
 * directly. We test:
 * - Auth failure path (returns 401, no input needed)
 * - getRooms (returns structured room list)
 * - getRooms error handling (returns 500)
 * - $_GET fallback for receive (when php://input is empty)
 * - $_POST fallback for receiveApprise
 * - debug endpoint (disabled/enabled)
 * - saveConfig/saveBotPassword edge cases
 *
 * The payload parsing / mapping logic is covered by TalkServiceTest.
 */
class WebhookControllerTest extends TestCase {
    /**
     * Build a WebhookController with all dependencies mocked.
     */
    private function makeController(TalkService $talkService): WebhookController {
        return new WebhookController(
            'nc_bot_webhooks',
            $this->createMock(IRequest::class),
            $talkService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IAppManager::class),
            $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IConfig::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(IClientService::class),
            $this->createMock(IShareManager::class),
        );
    }

    /**
     * Build a TalkService mock with given method return values.
     */
    private function makeTalkServiceMock(array $methods): TalkService {
        $mock = $this->createMock(TalkService::class);
        foreach ($methods as $method => $return) {
            if (is_callable($return)) {
                $mock->method($method)
                    ->willReturnCallback($return);
            } else {
                $mock->method($method)
                    ->willReturn($return);
            }
        }
        return $mock;
    }

    // =========================================================================
    // receive (Discord webhook)
    // =========================================================================

    public function testReceiveReturns401ForInvalidAuthToken(): void {
        $talkService = $this->makeTalkServiceMock([
            'validateAuthToken' => false,
        ]);
        $controller = $this->makeController($talkService);

        $response = $controller->receive('room123', 'badtoken');

        $this->assertEquals(401, $response->getStatus());
        $this->assertEquals('Unauthorized', $response->getData()['error']);
    }

    public function testReceiveReturns400ForEmptyBody(): void {
        $talkService = $this->makeTalkServiceMock([
            'validateAuthToken' => true,
        ]);
        $controller = $this->makeController($talkService);

        // With no php://input available, json_decode('') fails → 400
        $response = $controller->receive('room123', 'validtoken');

        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals('Invalid JSON', $response->getData()['error']);
    }

    public function testReceiveUsesGETFallbackWhenInputIsEmpty(): void {
        $talkService = $this->makeTalkServiceMock([
            'validateAuthToken' => true,
            'mapPayload' => function ($payload) {
                return [
                    'message' => $payload['content'] ?? '',
                    'senderName' => 'Bot',
                    'displayName' => 'Bot',
                    'richObjects' => [],
                ];
            },
            'getSenderNameDefault' => 'Bot',
            'prependDisplayName' => function ($name, $msg) {
                return $msg;
            },
            'postToRoom' => true,
        ]);

        $controller = $this->makeController($talkService);

        // Simulate empty php://input with $_GET fallback
        $_GET = [
            'content' => 'GET fallback message',
        ];

        $response = $controller->receive('room123', 'validtoken');

        $this->assertEquals(201, $response->getStatus());
        $this->assertEquals('ok', $response->getData()['status']);

        // Clean up
        $_GET = [];
    }

    public function testReceiveGETFallbackWithEmbeds(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('validateAuthToken')
            ->willReturn(true);
        $talkService->method('mapPayload')
            ->willReturnCallback(function ($payload) {
                return [
                    'message' => $payload['content'] ?? '',
                    'senderName' => 'Bot',
                    'displayName' => 'Bot',
                    'richObjects' => [],
                ];
            });
        $talkService->method('getSenderNameDefault')
            ->willReturn('Bot');
        $talkService->method('prependDisplayName')
            ->willReturnCallback(function ($name, $msg) {
                return $msg;
            });
        $talkService->method('postToRoom')
            ->willReturn(true);

        $controller = $this->makeController($talkService);

        $_GET = [
            'content' => 'Test with embeds',
            'embeds' => '[{"title":"Embed Title","description":"Embed desc"}]',
        ];

        $response = $controller->receive('room123', 'validtoken');

        $this->assertEquals(201, $response->getStatus());

        // Clean up
        $_GET = [];
    }

    // =========================================================================
    // receiveApprise
    // =========================================================================

    public function testReceiveAppriseReturns401ForInvalidAuthToken(): void {
        $talkService = $this->makeTalkServiceMock([
            'validateAuthToken' => false,
        ]);
        $controller = $this->makeController($talkService);

        $response = $controller->receiveApprise('room123', 'badtoken');

        $this->assertEquals(401, $response->getStatus());
        $this->assertEquals('Unauthorized', $response->getData()['error']);
    }

    public function testReceiveAppriseReturns400ForEmptyBody(): void {
        $talkService = $this->makeTalkServiceMock([
            'validateAuthToken' => true,
        ]);
        $controller = $this->makeController($talkService);

        // No php://input → no data → 400
        $response = $controller->receiveApprise('room123', 'validtoken');

        $this->assertEquals(400, $response->getStatus());
    }

    public function testReceiveAppriseNotifyDelegatesToReceiveApprise(): void {
        $talkService = $this->makeTalkServiceMock([
            'validateAuthToken' => false,
        ]);
        $controller = $this->makeController($talkService);

        $response = $controller->receiveAppriseNotify('room123', 'badtoken');

        $this->assertEquals(401, $response->getStatus());
        $this->assertEquals('Unauthorized', $response->getData()['error']);
    }

    public function testReceiveAppriseUsesPOSTFallbackWithNotifications(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('validateAuthToken')
            ->willReturn(true);
        $talkService->method('mapApprisePayload')
            ->willReturn([
                'message' => 'POST fallback message',
                'senderName' => 'Apprise',
                'displayName' => 'Apprise',
                'richObjects' => [],
            ]);
        $talkService->method('getSenderNameDefault')
            ->willReturn('Bot');
        $talkService->method('prependDisplayName')
            ->willReturnCallback(function ($name, $msg) {
                return $msg;
            });
        $talkService->method('postToRoom')
            ->willReturn(true);

        $controller = $this->makeController($talkService);

        // Simulate $_POST fallback (form-encoded with notifications wrapper)
        $_POST = [
            'notifications' => [
                [
                    'body' => 'POST fallback message',
                    'type' => 'success',
                ],
            ],
        ];

        $response = $controller->receiveApprise('room123', 'validtoken');

        $this->assertEquals(201, $response->getStatus());

        // Clean up
        $_POST = [];
    }

    public function testReceiveApprisePOSTFallbackWithImageType(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('validateAuthToken')
            ->willReturn(true);
        $talkService->method('mapApprisePayload')
            ->willReturn([
                'message' => '',
                'senderName' => 'Apprise',
                'displayName' => 'Apprise',
                'richObjects' => ['richObject' => []],
            ]);
        $talkService->method('getSenderNameDefault')
            ->willReturn('Bot');
        $talkService->method('prependDisplayName')
            ->willReturnCallback(function ($name, $msg) {
                return $msg;
            });
        $talkService->method('postToRoom')
            ->willReturn(true);

        $controller = $this->makeController($talkService);

        $_POST = [
            'notifications' => [
                [
                    'body' => 'Image notification',
                    'type' => 'image',
                    'attachments' => ['https://example.com/image.png'],
                ],
            ],
        ];

        $response = $controller->receiveApprise('room123', 'validtoken');

        $this->assertEquals(201, $response->getStatus());

        // Clean up
        $_POST = [];
    }

    public function testReceiveAppriseGETFallbackWithNotifications(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('validateAuthToken')
            ->willReturn(true);
        $talkService->method('mapApprisePayload')
            ->willReturn([
                'message' => 'GET fallback message',
                'senderName' => 'Apprise',
                'displayName' => 'Apprise',
                'richObjects' => [],
            ]);
        $talkService->method('getSenderNameDefault')
            ->willReturn('Bot');
        $talkService->method('prependDisplayName')
            ->willReturnCallback(function ($name, $msg) {
                return $msg;
            });
        $talkService->method('postToRoom')
            ->willReturn(true);

        $controller = $this->makeController($talkService);

        // $_GET with notifications array
        $_GET = [
            'notifications' => [
                [
                    'body' => 'GET fallback message',
                    'type' => 'notice',
                ],
            ],
        ];

        $response = $controller->receiveApprise('room123', 'validtoken');

        $this->assertEquals(201, $response->getStatus());

        // Clean up
        $_GET = [];
    }

    // =========================================================================
    // saveConfig / saveBotPassword
    // =========================================================================

    public function testSaveConfigReturns500WhenTalkServiceFails(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('validateBotPassword')
            ->willThrowException(new \Exception('crypto error'));

        $controller = $this->makeController($talkService);

        $response = $controller->saveConfig([
            'bot_password' => 'test',
            'retention_days' => '90',
            'sender_name' => 'Bot',
            'rooms' => [],
            'auth_tokens' => [],
        ]);

        $this->assertEquals(500, $response->getStatus());
    }

    public function testSaveConfigReturns400WhenInvalidConfig(): void {
        $talkService = $this->createMock(TalkService::class);

        $controller = $this->makeController($talkService);

        $response = $controller->saveConfig(null);

        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals('Invalid config', $response->getData()['error']);
    }

    public function testSaveConfigReturns200WithAuthTokens(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('validateBotPassword')
            ->willReturn(true);
        $talkService->method('saveConfig')
            ->willReturn(null);
        $talkService->method('getAuthTokens')
            ->willReturn(['room1' => 'token1']);

        $controller = $this->makeController($talkService);

        $response = $controller->saveConfig([
            'bot_password' => 'validpassword',
            'retention_days' => '30',
            'sender_name' => 'TestBot',
            'rooms' => ['room1'],
            'auth_tokens' => ['room1' => 'token1'],
        ]);

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('ok', $response->getData()['status']);
        $this->assertEquals(['room1' => 'token1'], $response->getData()['auth_tokens']);
    }

    public function testSaveBotPasswordRejectsInvalidPassword(): void {
        $talkService = $this->makeTalkServiceMock([
            'validateBotPassword' => false,
        ]);
        $controller = $this->makeController($talkService);

        $response = $controller->saveBotPassword('invalid');

        $this->assertEquals(400, $response->getStatus());
    }

    public function testSaveBotPasswordAcceptsValidPassword(): void {
        $talkService = $this->makeTalkServiceMock([
            'validateBotPassword' => true,
        ]);
        $controller = $this->makeController($talkService);

        $response = $controller->saveBotPassword('validpassword123');

        $this->assertEquals(200, $response->getStatus());
    }

    // =========================================================================
    // getRooms
    // =========================================================================

    public function testGetRoomsReturnsEmptyArrayWhenNoRooms(): void {
        $talkService = $this->makeTalkServiceMock([
            'getAvailableTalkRooms' => [],
            'getRooms' => [],
            'detectTalkTableFromCatalog' => null,
        ]);
        $controller = $this->makeController($talkService);

        $response = $controller->getRooms();

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals([], $response->getData());
    }

    public function testGetRoomsReturnsRoomListWithConfiguredFlag(): void {
        $talkService = $this->makeTalkServiceMock([
            'getAvailableTalkRooms' => [
                'abc123' => 'Test Room',
                'def456' => 'Another Room',
            ],
            'getRooms' => ['abc123' => true], // only abc123 configured
            'detectTalkTableFromCatalog' => 'talk_rooms',
        ]);

        $dbConn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $dbConn->method('executeQuery')
            ->willReturn(null);
        $talkService->method('getDbConnection')
            ->willReturn($dbConn);

        $controller = $this->makeController($talkService);

        $response = $controller->getRooms();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertNotEmpty($data);

        $roomA = null;
        $roomB = null;
        foreach ($data as $room) {
            if ($room['token'] === 'abc123') $roomA = $room;
            if ($room['token'] === 'def456') $roomB = $room;
        }

        $this->assertNotNull($roomA);
        $this->assertTrue($roomA['configured']);
        $this->assertTrue(isset($roomA['token']));
        $this->assertTrue(isset($roomA['name']));
        $this->assertTrue(isset($roomA['type_label']));

        $this->assertNotNull($roomB);
        $this->assertFalse($roomB['configured']);
    }

    public function testGetRoomsReturns500OnException(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('getAvailableTalkRooms')
            ->willThrowException(new \Exception('DB connection failed'));

        $controller = $this->makeController($talkService);

        $response = $controller->getRooms();

        $this->assertEquals(500, $response->getStatus());
        $this->assertStringContainsString('DB connection failed', $response->getData()['error']);
    }

    // =========================================================================
    // debug endpoint
    // =========================================================================

    public function testDebugReturns403WhenDisabled(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(false);

        $talkService = $this->createMock(TalkService::class);
        $controller = new WebhookController(
            'nc_bot_webhooks',
            $this->createMock(IRequest::class),
            $talkService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IAppManager::class),
            $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IConfig::class),
            $appConfig,
            $this->createMock(IClientService::class),
            $this->createMock(IShareManager::class),
        );

        $response = $controller->debug();

        $this->assertEquals(403, $response->getStatus());
        $this->assertStringContainsString('disabled', $response->getData()['error']);
    }

    public function testDebugReturns200WhenEnabled(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(true);

        $talkService = $this->createMock(TalkService::class);
        $talkService->method('getBotUser')
            ->willReturn(null);
        $talkService->method('hasBotPassword')
            ->willReturn(false);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')
            ->willReturn('admin');
        $user->method('isAdmin')
            ->willReturn(true);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')
            ->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')
            ->willReturn(true);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')
            ->willReturn('');

        $controller = new WebhookController(
            'nc_bot_webhooks',
            $this->createMock(IRequest::class),
            $talkService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IAppManager::class),
            $userSession,
            $groupManager,
            $config,
            $appConfig,
            $this->createMock(IClientService::class),
            $this->createMock(IShareManager::class),
        );

        $response = $controller->debug();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['debug_enabled']);
        $this->assertEquals('admin', $data['user']);
        $this->assertTrue($data['user_is_admin']);
    }

    public function testDebugNonAdminUser(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(true);

        $talkService = $this->createMock(TalkService::class);
        $talkService->method('getBotUser')
            ->willReturn(null);
        $talkService->method('hasBotPassword')
            ->willReturn(false);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')
            ->willReturn('regular_user');
        $user->method('isAdmin')
            ->willReturn(false);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')
            ->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')
            ->willReturn(false);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')
            ->willReturn('');

        $controller = new WebhookController(
            'nc_bot_webhooks',
            $this->createMock(IRequest::class),
            $talkService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IAppManager::class),
            $userSession,
            $groupManager,
            $config,
            $appConfig,
            $this->createMock(IClientService::class),
            $this->createMock(IShareManager::class),
        );

        $response = $controller->debug();

        $this->assertEquals(200, $response->getStatus());
        $this->assertFalse($response->getData()['user_is_admin']);
    }
}
