<?php

namespace OCA\Ncbotwebhooks;

use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Navigation\INavigationProvider;

class NavigationProvider implements INavigationProvider {
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly IUserSession $userSession,
        private readonly IConfig $config,
        private readonly IL10N $l10n,
    ) {}

    public function getNavigation(): array {
        $user = $this->userSession->getUser();
        if ($user === null || !$user->isAdmin()) {
            return [];
        }

        return [
            [
                'id' => 'nc_bot_webhooks',
                'app_id' => 'nc_bot_webhooks',
                'type' => 'settings',
                'name' => $this->l10n->t('NCbotwebhooks'),
                'href' => $this->urlGenerator->linkToRoute('settings.AdminSettings#index'),
                'icon' => $this->urlGenerator->imagePath('nc_bot_webhooks', 'app.svg'),
                'order' => 0,
            ],
        ];
    }
}
