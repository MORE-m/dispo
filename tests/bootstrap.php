<?php

/**
 * Git-Worktree mit vendor-Symlink: Composer-ClassLoader zeigt auf das Hauptprojekt,
 * Laravel inferBasePath() sonst ohne worktree-spezifische Migrationen (z. B. BL-P4-02b).
 */
$basePath = dirname(__DIR__);

$_ENV['APP_BASE_PATH'] = $basePath;
$_SERVER['APP_BASE_PATH'] = $basePath;

// Symlink-.env aus dem Hauptprojekt darf APP_ENV=local nicht in Unit-Tests durchsetzen
// (sonst greift CSRF und PreventRequestForgery::runningUnitTests() ist false).
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');

require $basePath.'/vendor/autoload.php';
