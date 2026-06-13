<?php

// Generated autoload file for nc_bot_webhooks
// Provides PSR-4 autoloading for OCA\Ncbotwebhooks\

$baseDir = dirname(__DIR__);

spl_autoload_register(function ($class) use ($baseDir) {
    // OCA\Ncbotwebhooks\ prefix
    $prefix = 'OCA\\Ncbotwebhooks\\';
    $prefixLen = strlen($prefix);
    if (strncmp($class, $prefix, $prefixLen) !== 0) {
        return;
    }

    $relativeClass = substr($class, $prefixLen);
    $file = $baseDir . '/lib/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
