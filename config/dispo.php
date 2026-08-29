<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Privater Dateispeicher
    |--------------------------------------------------------------------------
    |
    | V1 nutzt den lokalen Laravel-Disk. Produktiv kann DISPO_FILES_DISK=s3
    | gesetzt werden, ohne die Fachlogik zu ändern (ADR-001, ADR-002).
    |
    */

    'files_disk' => env('DISPO_FILES_DISK', 'local'),

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Berlin'),

];
