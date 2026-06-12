<?php

return [
    'routes' => [
        [
            'name' => 'webhook#receive',
            'url' => '/bot-webhook/{roomToken}/{token}',
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
    ],
];
