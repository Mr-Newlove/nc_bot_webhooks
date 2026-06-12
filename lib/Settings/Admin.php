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
            'rooms' => $this->talkService->getAvailableTalkRooms(),
            'authTokens' => $this->talkService->getAuthTokens(),
            'configuredRooms' => $this->talkService->getRooms(),
            'serverUrl' => $this->talkService->getBaseUrl(),
            'senderName' => $this->talkService->getSenderNameDefault(),
            'l10n' => [
                'fetch_rooms' => $this->l10n->t('Fetch Rooms'),
                'error_fetching' => $this->l10n->t('Error fetching rooms'),
                'save_config' => $this->l10n->t('Save Configuration'),
                'config_saved' => $this->l10n->t('Configuration saved'),
                'save_failed' => $this->l10n->t('Configuration save failed'),
                'generate_token' => $this->l10n->t('Generate Token'),
                'revoke_token' => $this->l10n->t('Revoke'),
                'no_rooms' => $this->l10n->t('No rooms found'),
                'no_rooms_msg' => $this->l10n->t('No Talk rooms are available or you don\'t have permission to view them.'),
                'bot_password' => $this->l10n->t('Bot App Password'),
                'bot_password_desc' => $this->l10n->t('App password for the "talk-bot" user. Create one in Settings → talk-bot → Devices & sessions.'),
                'sender_name' => $this->l10n->t('Default Sender Name'),
                'sender_name_desc' => $this->l10n->t('Name used when posting messages as the bot.'),
                'image_retention' => $this->l10n->t('Image Retention (days)'),
                'image_retention_desc' => $this->l10n->t('How long to keep uploaded images before cleanup.'),
                'room_selection' => $this->l10n->t('Room Selection'),
                'room_selection_desc' => $this->l10n->t('Select which Talk rooms should accept webhooks. Enable the bot for a room by checking it.'),
                'auth_tokens' => $this->l10n->t('Auth Tokens'),
                'auth_tokens_desc' => $this->l10n->t('Auth tokens for this room. Used to validate incoming webhook requests.'),
                'no_password' => $this->l10n->t('Enter bot password to enable configuration.'),
            ],
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
