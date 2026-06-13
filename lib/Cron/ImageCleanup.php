<?php

namespace OCA\Ncbotwebhooks\Cron;

use OCA\Ncbotwebhooks\Service\TalkService;
use OCP\BackgroundJob\IJob;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ImageCleanup implements IJob {
    private TalkService $talkService;
    private IRootFolder $rootFolder;
    private IUserManager $userManager;
    private LoggerInterface $logger;

    public function __construct(
        TalkService $talkService,
        IRootFolder $rootFolder,
        IUserManager $userManager,
        LoggerInterface $logger,
    ) {
        $this->talkService = $talkService;
        $this->rootFolder = $rootFolder;
        $this->userManager = $userManager;
        $this->logger = $logger;
    }

    public function run($argument = null): void {
        // Check if bot user exists
        $bot = $this->userManager->get('talk-bot');
        if (!$bot) {
            return;
        }

        // Check if images directory exists
        try {
            $userFolder = $this->rootFolder->getUserFolder($bot->getUID());
            $imagesDir = $userFolder->getFolder('nc_bot_webhooks-images');
        } catch (\Exception $e) {
            return;
        }

        $count = $this->talkService->purgeOldImages();

        if ($count > 0) {
            $this->logger->info(
                'NCbotwebhooks: purged ' . $count . ' old image files',
                ['app' => 'nc_bot_webhooks'],
            );
        }
    }
}
