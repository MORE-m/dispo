<?php

/**
 * Git-Worktree mit vendor-Symlink: Composer-ClassLoader zeigt auf das Hauptprojekt,
 * Laravel inferBasePath() sonst ohne worktree-spezifische Migrationen (z. B. BL-P4-02b).
 */
$basePath = dirname(__DIR__);

$_ENV['APP_BASE_PATH'] = $basePath;
$_SERVER['APP_BASE_PATH'] = $basePath;

require $basePath.'/vendor/autoload.php';
