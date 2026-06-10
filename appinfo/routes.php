<?php
return [
    'routes' => [
        [
            'name' => 'webhook#receive',
            'url' => '/webhook/{roomToken}/{authToken}',
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
    ],
];
