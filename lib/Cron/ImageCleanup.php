<?php

namespace OCA\NCdiscordhook\Cron;

use OCA\NCdiscordhook\Service\TalkService;
use OCP\BackgroundJob\IJob;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;

class ImageCleanup implements IJob {
    private TalkService $talkService;
    private IRootFolder $rootFolder;
    private IUserManager $userManager;

    public function __construct(
        TalkService $talkService,
        IRootFolder $rootFolder,
        IUserManager $userManager,
    ) {
        $this->talkService = $talkService;
        $this->rootFolder = $rootFolder;
        $this->userManager = $userManager;
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
            $imagesDir = $userFolder->getFolder('NCdiscordhook-images');
        } catch (\Exception $e) {
            return;
        }

        $count = $this->talkService->purgeOldImages();

        if ($count > 0) {
            \OC::$server->getLogger()->info(
                'NCdiscordhook: purged ' . $count . ' old image files',
                ['app' => 'ncdiscordhook'],
            );
        }
    }
}
