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

    /*
    |--------------------------------------------------------------------------
    | Master-Data-Transfer – Proof-/Receipt-Signatur (EPIC-03-F Slice 6b-H / 7)
    |--------------------------------------------------------------------------
    |
    | HMAC-Geheimnis für Backup-Proofs und Apply-Receipts. Leer = abgeleitet aus
    | APP_KEY (HMAC-Domain-Separation). Schutz gegen versehentliche/freie Edits,
    | nicht gegen einen kompromittierten Host.
    |
    | master_data_transfer_instance_id: optionale Instanzidentität; wenn gesetzt,
    | muss sie im Backup-Proof identisch sein.
    | master_data_transfer_backup_proof_max_age_minutes: maximales Alter eines Proofs.
    | master_data_transfer_receipt_dir: Ablageort der Receipts (null = storage/app/master-data-transfer/receipts).
    |
    */

    'master_data_transfer_proof_secret' => env('MASTER_DATA_TRANSFER_PROOF_SECRET'),

    'master_data_transfer_instance_id' => env('MASTER_DATA_TRANSFER_INSTANCE_ID'),

    'master_data_transfer_backup_proof_max_age_minutes' => (int) env('MASTER_DATA_TRANSFER_BACKUP_PROOF_MAX_AGE_MINUTES', 1440),

    'master_data_transfer_receipt_dir' => null,

];
