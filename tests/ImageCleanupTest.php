<?php

namespace OCA\Ncbotwebhooks\Test;

use OCA\Ncbotwebhooks\Cron\ImageCleanup;
use OCA\Ncbotwebhooks\Service\TalkService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ImageCleanup cron job.
 *
 * Tests the full run() flow: bot user check, directory check, purge execution.
 */
class ImageCleanupTest extends TestCase {
    /**
     * Build an ImageCleanup with all dependencies mocked.
     */
    private function makeImageCleanup(
        TalkService $talkService,
        IRootFolder $rootFolder,
        IUserManager $userManager,
        LoggerInterface $logger,
    ): ImageCleanup {
        return new ImageCleanup(
            $talkService,
            $rootFolder,
            $userManager,
            $logger,
        );
    }

    // =========================================================================
    // Early exit paths
    // =========================================================================

    public function testRunReturnsEarlyWhenNoBotUser(): void {
        $talkService = $this->createMock(TalkService::class);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')
            ->willReturn(null); // no bot user

        $logger = $this->createMock(LoggerInterface::class);
        // Should NOT log anything since we exit early
        $logger->expects($this->never())
            ->method('info');

        $rootFolder = $this->createMock(IRootFolder::class);

        $cleanup = $this->makeImageCleanup($talkService, $rootFolder, $userManager, $logger);
        $cleanup->run(); // should not throw
    }

    public function testRunReturnsEarlyWhenNoImagesDirectory(): void {
        $talkService = $this->createMock(TalkService::class);
        $userManager = $this->createMock(IUserManager::class);

        $botUser = $this->createMock(\OCP\IUser::class);
        $botUser->method('getUID')
            ->willReturn('talk-bot');
        $userManager->method('get')
            ->willReturn($botUser);

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')
            ->willReturnCallback(function () {
                $folder = $this->createMock(\OCP\Files\Folder::class);
                $folder->expects($this->once())
                    ->method('get')
                    ->willThrowException(new NotFoundException());
                return $folder;
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())
            ->method('info');

        $cleanup = $this->makeImageCleanup($talkService, $rootFolder, $userManager, $logger);
        $cleanup->run(); // should not throw
    }

    public function testRunReturnsEarlyOnRootFolderException(): void {
        $talkService = $this->createMock(TalkService::class);
        $userManager = $this->createMock(IUserManager::class);

        $botUser = $this->createMock(\OCP\IUser::class);
        $botUser->method('getUID')
            ->willReturn('talk-bot');
        $userManager->method('get')
            ->willReturn($botUser);

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')
            ->willThrowException(new \Exception('filesystem error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())
            ->method('info');

        $cleanup = $this->makeImageCleanup($talkService, $rootFolder, $userManager, $logger);
        $cleanup->run(); // should not throw
    }

    // =========================================================================
    // Success paths
    // =========================================================================

    public function testRunPurgesOldImagesAndLogsCount(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('purgeOldImages')
            ->willReturn(5); // purged 5 files

        $userManager = $this->createMock(IUserManager::class);

        $botUser = $this->createMock(\OCP\IUser::class);
        $botUser->method('getUID')
            ->willReturn('talk-bot');
        $userManager->method('get')
            ->willReturn($botUser);

        $rootFolder = $this->createMock(IRootFolder::class);
        $imagesFolder = $this->createMock(\OCP\Files\Folder::class);
        $userFolder = $this->createMock(\OCP\Files\Folder::class);

        $userFolder->method('get')
            ->willReturn($imagesFolder);

        $rootFolder->method('getUserFolder')
            ->willReturn($userFolder);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                'nc_bot_webhooks: purged 5 old image files',
                ['app' => 'nc_bot_webhooks'],
            );

        $cleanup = $this->makeImageCleanup($talkService, $rootFolder, $userManager, $logger);
        $cleanup->run();
    }

    public function testRunDoesNotLogWhenNoFilesPurged(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('purgeOldImages')
            ->willReturn(0); // no files purged

        $userManager = $this->createMock(IUserManager::class);

        $botUser = $this->createMock(\OCP\IUser::class);
        $botUser->method('getUID')
            ->willReturn('talk-bot');
        $userManager->method('get')
            ->willReturn($botUser);

        $rootFolder = $this->createMock(IRootFolder::class);
        $imagesFolder = $this->createMock(\OCP\Files\Folder::class);
        $userFolder = $this->createMock(\OCP\Files\Folder::class);

        $userFolder->method('get')
            ->willReturn($imagesFolder);

        $rootFolder->method('getUserFolder')
            ->willReturn($userFolder);

        $logger = $this->createMock(LoggerInterface::class);
        // Should NOT log when count is 0
        $logger->expects($this->never())
            ->method('info');

        $cleanup = $this->makeImageCleanup($talkService, $rootFolder, $userManager, $logger);
        $cleanup->run();
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testRunWithNullArgument(): void {
        $talkService = $this->createMock(TalkService::class);
        $talkService->method('purgeOldImages')
            ->willReturn(3);

        $userManager = $this->createMock(IUserManager::class);

        $botUser = $this->createMock(\OCP\IUser::class);
        $botUser->method('getUID')
            ->willReturn('talk-bot');
        $userManager->method('get')
            ->willReturn($botUser);

        $rootFolder = $this->createMock(IRootFolder::class);
        $imagesFolder = $this->createMock(\OCP\Files\Folder::class);
        $userFolder = $this->createMock(\OCP\Files\Folder::class);

        $userFolder->method('get')
            ->willReturn($imagesFolder);

        $rootFolder->method('getUserFolder')
            ->willReturn($userFolder);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info');

        $cleanup = $this->makeImageCleanup($talkService, $rootFolder, $userManager, $logger);
        $cleanup->run(null); // null argument should work the same as no argument
    }
}
