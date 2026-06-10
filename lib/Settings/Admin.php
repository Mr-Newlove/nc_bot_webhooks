<?php

namespace OCA\NCdiscordhook\Settings;

use OCA\NCdiscordhook\Service\TalkService;
use OCP\IL10N;
use OCP\Settings\ISettings;

class Admin implements ISettings {
    private TalkService $talkService;
    private IL10N $l10n;

    public function __construct(TalkService $talkService, IL10N $l10n) {
        $this->talkService = $talkService;
        $this->l10n = $l10n;
    }

    public function getForm(): string {
        $params = [
            'hasBotPassword' => $this->talkService->hasBotPassword(),
            'retentionDays' => $this->talkService->getRetentionDays(),
            'rooms' => $this->talkService->getRooms(),
            'authTokens' => $this->talkService->getAuthTokens(),
        ];

        $template = \OC::$server->get(\OCP\ITemplate\ITemplateFactory::class)->load('ncdiscordhook', 'adminSettings');
        foreach ($params as $key => $value) {
            $template->assign($key, $value);
        }

        return $template->fetchPage();
    }

    public function getPriority(): int {
        return 10;
    }

    public function getSection(): string {
        return 'server';
    }
}
