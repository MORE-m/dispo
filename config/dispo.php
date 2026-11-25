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

    /*
    |--------------------------------------------------------------------------
    | OA-TKP Wizard Development Capability (PO-CALC-TKP-1D2a)
    |--------------------------------------------------------------------------
    |
    | Soft-Open für Method Options (nur Kalkulationswizard), HTTP Preview und
    | Pricing Metadata – ohne Registry-Release und ohne HTTP Store/Update.
    | Default false (Production). Tests setzen die Config explizit; kein
    | Environment-/NODE_ENV-Businesshack.
    |
    */

    'oa_tkp_wizard_capability' => (bool) env('OA_TKP_WIZARD_CAPABILITY', false),

    /*
    |--------------------------------------------------------------------------
    | Staging-Mail-Empfänger-Guard (EPIC-01-MAIL-1)
    |--------------------------------------------------------------------------
    |
    | Mode „off“ (Default): kein Eingriff – Produktion unverändert.
    | Mode „allowlist“: nur exakte Adressen aus DISPO_MAIL_RECIPIENT_ALLOWLIST
    | (Komma-getrennt). To/Cc/Bcc vollständig; leere/ungültige Liste = fail-closed.
    | Unbekannte Mode-Werte = invalid (Tippfehler).
    | APP_ENV=staging + Live-Mailer (nicht log/array) erfordert Mode allowlist;
    | off/fehlend/invalid → Versand gesperrt. Keine Domain-Wildcards.
    | Siehe docs/betrieb/staging-mail-recipient-guard.md.
    |
    */

    'mail_recipient_guard' => env('DISPO_MAIL_RECIPIENT_GUARD', 'off'),

    'mail_recipient_allowlist' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('DISPO_MAIL_RECIPIENT_ALLOWLIST', '')),
    ), static fn (string $email): bool => $email !== '')),

    /*
    |--------------------------------------------------------------------------
    | Master-Data-Transfer – Test-Only DB-Identität (EPIC-03-F Slice 6a)
    |--------------------------------------------------------------------------
    |
    | Nur für synthetische Tests: simuliert die operative Ziel-DB-Identität,
    | ohne die echte Verbindungs-DB umzubiegen und ohne CLI-Override.
    | Wird ausschließlich bei APP_ENV=testing ausgewertet; sonst ignoriert.
    |
    */

    'master_data_transfer_testing_operational_database' => null,

];
