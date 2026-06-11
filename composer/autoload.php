<?php

// Generated autoload file for ncdiscordhook
// Provides PSR-4 autoloading for OCA\NCdiscordhook\

$baseDir = dirname(__DIR__);

spl_autoload_register(function ($class) use ($baseDir) {
    // OCA\NCdiscordhook\ prefix
    $prefix = 'OCA\\NCdiscordhook\\';
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
