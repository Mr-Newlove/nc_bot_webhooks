<?php

namespace OCA\NCdiscordhook\Settings;

use OCA\NCdiscordhook\Service\TalkService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\Settings\ISettings;

class Admin implements ISettings {
    private TalkService $talkService;
    private IL10N $l10n;

    public function __construct(TalkService $talkService, IL10N $l10n) {
        $this->talkService = $talkService;
        $this->l10n = $l10n;
    }

    public function getForm(): TemplateResponse {
        \OCP\Util::addStyle('ncdiscordhook', 'adminSettings');
        \OCP\Util::addScript('ncdiscordhook', 'settings');

        $params = [
            'hasBotPassword' => $this->talkService->hasBotPassword(),
            'retentionDays' => $this->talkService->getRetentionDays(),
            'rooms' => $this->talkService->getRooms(),
            'authTokens' => $this->talkService->getAuthTokens(),
        ];

        $response = new TemplateResponse('ncdiscordhook', 'adminSettings', $params);
        return $response;
    }

    public function getPriority(): int {
        return 10;
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getIcons(): array {
        $urlGenerator = \OC::$server->get(\OCP\IURLGenerator::class);
        return [
            $urlGenerator->imagePath('ncdiscordhook', 'app.svg'),
        ];
    }
}
