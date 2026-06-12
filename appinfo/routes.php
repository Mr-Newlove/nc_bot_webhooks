<?php

return [
    'routes' => [
        [
            'name' => 'webhook#receive',
            'url' => '/discord-webhook/{roomToken}/{token}',
            'verb' => 'POST',
        ],
        [
            'name' => 'webhook#saveConfig',
            'url' => '/save-config',
            'verb' => 'POST',
        ],
        [
            'name' => 'webhook#getRooms',
            'url' => '/rooms',
            'verb' => 'GET',
        ],
        [
            'name' => 'webhook#saveBotPassword',
            'url' => '/save-bot-password',
            'verb' => 'POST',
        ],
        [
            'name' => 'webhook#debug',
            'url' => '/debug',
            'verb' => 'GET',
            'type' => 'noCsrf',
        ],
        [
            'name' => 'webhook#receiveApprise',
            'url' => '/apprise-webhook/{roomToken}/{token}',
            'verb' => 'POST',
        ],
        [
            'name' => 'webhook#receiveAppriseNotify',
            'url' => '/apprise-webhook/{roomToken}/notify/{token}',
            'verb' => 'POST',
        ],
    ],
];
