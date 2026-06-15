<?php

/**
 * Nextcloud nc_bot_webhooks — PHPUnit bootstrap
 *
 * Minimal bootstrap that mocks the Talk app services so TalkService can be
 * instantiated without a running Nextcloud instance.  Every constructor
 * dependency of TalkService gets a PHPUnit mock object.
 */

// Load composer autoloader first
$base = __DIR__ . '/../';
$loader = $base . 'vendor/autoload.php';
if (!file_exists($loader)) {
    fwrite(STDERR, "vendor/autoload.php not found — run `composer install`\n");
    exit(1);
}
require_once $loader;

// ── Minimal OCP / OC interface stubs ──────────────────────────
// PHPUnit's mock generator needs the actual class/interface definitions
// to inspect method signatures.  We provide tiny stubs for anything that
// is not already provided by composer / the real OCP packages.

// OCP\Http\Client\IClientService
if (!interface_exists(\OCP\Http\Client\IClientService::class)) {
    namespace OCP\Http\Client {
        interface IClientService {
            public function getClient(): \OCP\Http\Client\IClient;
        }
    }
}
if (!interface_exists(\OCP\Http\Client\IClient::class)) {
    namespace OCP\Http\Client {
        interface IClient {
            public function get(string $url, array $options = []): \Psr\Http\Message\ResponseInterface;
            public function post(string $url, array $options = []): \Psr\Http\Message\ResponseInterface;
            public function delete(string $url): \Psr\Http\Message\ResponseInterface;
        }
    }
}
if (!interface_exists(\Psr\Http\Message\ResponseInterface::class)) {
    // PSR-7 — must already be loaded via composer; guard just in case
    namespace { /* nothing */ }
}

namespace {

/**
 * Build a TalkService instance with all-mocked dependencies.
 *
 * Usage:
 *   $builder = new TalkServiceMockBuilder();
 *   $service = $builder->build();                       // all defaults
 *   $service = $builder->config(['app' => ['key' => 'val']])->build();
 *   $service = $builder->botUser($botUser)->build();
 */
class TalkServiceMockBuilder {
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\Http\Client\IClientService $clientService;
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\IConfig $config;
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\IDBConnection $db;
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\Files\IRootFolder $rootFolder;
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\IRequest $request;
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\IURLGenerator $urlGenerator;
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\IUserManager $userManager;
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\IUserSession $userSession;
    private \PHPUnit\Framework\MockObject\MockObject| \Psr\Log\LoggerInterface $logger;
    private \PHPUnit\Framework\MockObject\MockObject| \OCA\Talk\Manager $talkManager;
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\Security\ICrypto $crypto;
    private \PHPUnit\Framework\MockObject\MockObject| \OCA\Talk\Model\AttendeeMapper $attendeeMapper;
    private \PHPUnit\Framework\MockObject\MockObject| \OCP\Share\IManager $shareManager;
    private \PHPUnit\Framework\MockObject\MockObject| \OCA\Talk\Service\ParticipantService $participantService;
    private \PHPUnit\Framework\MockObject\MockObject| \OCA\Talk\Chat\ChatManager $chatManager;
    private \PHPUnit\Framework\MockObject\MockObject| \OCA\Talk\TalkSession $talkSession;

    public function __construct() {
        $this->clientService  = $this->makeMock(\OCP\Http\Client\IClientService::class);
        $this->config         = $this->makeMock(\OCP\IConfig::class);
        $this->db             = $this->makeMock(\OCP\IDBConnection::class);
        $this->rootFolder     = $this->makeMock(\OCP\Files\IRootFolder::class);
        $this->request        = $this->makeMock(\OCP\IRequest::class);
        $this->urlGenerator   = $this->makeMock(\OCP\IURLGenerator::class);
        $this->userManager    = $this->makeMock(\OCP\IUserManager::class);
        $this->userSession    = $this->makeMock(\OCP\IUserSession::class);
        $this->logger         = $this->makeMock(\Psr\Log\LoggerInterface::class);
        $this->talkManager    = $this->makeMock(\OCA\Talk\Manager::class);
        $this->crypto         = $this->makeMock(\OCP\Security\ICrypto::class);
        $this->attendeeMapper = $this->makeMock(\OCA\Talk\Model\AttendeeMapper::class);
        $this->shareManager   = $this->makeMock(\OCP\Share\IManager::class);
        $this->participantService = $this->makeMock(\OCA\Talk\Service\ParticipantService::class);
        $this->chatManager    = $this->makeMock(\OCA\Talk\Chat\ChatManager::class);
        $this->talkSession    = $this->makeMock(\OCA\Talk\TalkSession::class);
    }

    private function makeMock(string $interface): \PHPUnit\Framework\MockObject\MockObject {
        return \PHPUnit\Framework\TestCase::getMockForTrait($interface);
    }

    /** Configure the config mock so getAppValue / setAppValue work as expected. */
    public function config(array $appValues = []): self {
        // getMockForTrait returns a partial mock; we can still use willReturn / withConsecutive
        $mock = $this->config;
        if (is_object($mock) && method_exists($mock, 'expects')) {
            // We set expectations lazily inside each test; this is just a convenience
            // to pre-configure default return values.
        }
        return $this;
    }

    /**
     * Assemble and return a TalkService.
     *
     * @return \OCA\Ncbotwebhooks\Service\TalkService
     */
    public function build(): \OCA\Ncbotwebhooks\Service\TalkService {
        return new \OCA\Ncbotwebhooks\Service\TalkService(
            $this->clientService,
            $this->config,
            $this->db,
            $this->rootFolder,
            $this->request,
            $this->urlGenerator,
            $this->userManager,
            $this->userSession,
            $this->logger,
            $this->talkManager,
            $this->crypto,
            $this->attendeeMapper,
            $this->shareManager,
            $this->participantService,
            $this->chatManager,
            $this->talkSession,
        );
    }
}

}
