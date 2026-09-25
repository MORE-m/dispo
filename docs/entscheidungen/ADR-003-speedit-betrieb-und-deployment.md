# ADR-003 – Speedit-Betrieb und Deployment

- **Status:** Akzeptiert
- **Datum:** 29. August 2026
- **Entscheider:** bestätigt

## Kontext

V1 läuft intern bei more Marketing. Das Hosting erfolgt über Speedit mit Apache,
PHP und MySQL. Redis und ein produktiver Objektspeicher sind keine V1-Voraussetzung
(ADR-001, ADR-002). Lange Arbeiten (Import, Export, E-Mail) laufen asynchron.
Secrets dürfen nicht im Repository liegen.

## Entscheidung

### Laufzeitumgebung

- Apache-Webhosting bei Speedit
- PHP 8.3
- MySQL (InnoDB, utf8mb4)
- Composer und SSH-Zugang zur Instanz
- Node.js nur lokal bzw. in CI für den Asset-Build; kein Node-Prozess in Produktion

DocumentRoot zeigt auf `public/`. PHP-Erweiterungen müssen Laravel 13 abdecken
(unter anderem `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`,
`openssl`, `pdo_mysql`, `session`, `tokenizer`, `xml`, `gd` oder `imagick` für
Dompdf).

### Scheduler und Queue

- Laravel-Scheduler über einen **minütlichen Cronjob**. Der PHP-CLI-Pfad auf
  Speedit ist **vor Produktivsetzung zu verifizieren**, Beispiel:
  `* * * * * php /pfad/zur/app/artisan schedule:run`
- Queue-Treiber: `database`
- Abarbeitung durch regelmäßig geplanten Worker:
  `php artisan queue:work --stop-when-empty --max-time=50 --timeout=45 --tries=3 --backoff=30`
- `--timeout` (45 s) bleibt kleiner als `retry_after` (90 s, `DB_QUEUE_RETRY_AFTER`)
- `--max-time=50` beendet den Prozess vor dem nächsten Cron-Tick
- `--tries=3` und `--backoff=30` (Sekunden zwischen Versuchen) für Wiederholungen
- Überlappende Queue-Läufe werden verhindert (`withoutOverlapping`)
- Fehlgeschlagene Jobs landen in `failed_jobs` und werden erneut versucht
- E-Mail-Versand läuft über dieselbe Queue; Fehler rollen den Fachstatus nicht
  zurück (`NOT-002`)

### Dateien und E-Mail

- Private Dateiablage über Laravel Filesystem (lokaler Disk außerhalb von `public/`)
- Keine direkt öffentlichen Datei-URLs
- SMTP-Konfiguration ausschließlich über Umgebungsvariablen

### Konfiguration und Secrets

- Keine Secrets, produktiven Zugangsdaten oder personenbezogenen Echtdaten im
  Repository
- `.env` nur auf dem Server; `.env.example` ohne Geheimnisse versioniert
- Staging und Produktion sind getrennte Umgebungen mit eigenen Datenbanken,
  Dateispeichern, Queues und SMTP-Absendern

### Deployment

Vorbereiteter Prozess über **GitHub Actions und SSH**:

1. CI (Formatierung, PHPStan, Pest, TypeScript, Frontendtests, Produktionsbuild)
   muss grün sein.
2. Artefakt bzw. Checkout auf dem Zielhost per SSH.
3. `composer install --no-dev --optimize-autoloader` (Produktion).
4. Frontend-Assets sind bereits in CI gebaut und werden mit ausgeliefert.
5. `php artisan migrate --force` nur nach Backup.
6. `php artisan config:cache`, `route:cache`, `view:cache`.
7. Queue-Worker bzw. nächster `queue:work`-Lauf verwendet den neuen Code.
8. Health-Check der Anwendung.

PHP-Runtime für Dispo-Uploads (UPL-006 / BL-P9-01): fachlich **50 MB**
pro Datei. Die Anwendung setzt `php.ini` nicht selbst. Für echte
multipart-Uploads auf dem Host:

- `upload_max_filesize >= 50M`
- `post_max_size > 50M` (empfohlen mind. `55M` wegen Multipart-Overhead)

Rollback:

1. Vor jedem produktiven Release Datenbank- und Datei-Backup.
2. Bei Fehlschlag vorheriges Release-Verzeichnis bzw. Git-Stand wiederherstellen.
3. Datenbank-Rollback nur mit dokumentierter Migration; destruktive Migrationen
   ohne Rücksetzplan sind unzulässig.
4. Caches leeren bzw. neu aufbauen und Health-Check wiederholen.

### Backup und Restore

- Automatisierte Backups von MySQL und der privaten Dateiablage
- Restore-Verfahren dokumentiert und vor Produktivsetzung getestet
- Aufbewahrungsfrist vor Go-Live festlegen (siehe offene Punkte)

## Begründung

Speedit erfüllt Apache, PHP 8.3, MySQL, Composer und Cron. Eine Datenbank-Queue
vermeidet Redis als Betriebsabhängigkeit. GitHub Actions hält Qualität vor dem
SSH-Deploy prüfbar.

## Konsequenzen

- Betriebshandbuch und Cron müssen vor Go-Live verifiziert werden.
- Queue-Durchsatz hängt am Cron-Intervall; für V1-Volumen ausreichend.
- Dateispeicherpfad muss außerhalb des Webroots und im Backup enthalten sein.

## Vor Produktivsetzung zu verifizieren

Diese Punkte blockieren das Scaffolding **nicht**:

- exakte MySQL-/MariaDB-Version
- genaue produktive Domain
- SMTP-Zugang und Absender
- SSH-Zielpfad
- Cronjob-Konfiguration
- PHP-CLI-Pfad für Scheduler und Queue-Worker
- Backup-Aufbewahrung
- verfügbare PHP-Erweiterungen
- maximaler Speicherplatz
- `APP_DEBUG=false`, HTTPS und `SESSION_SECURE_COOKIE=true`

## Verworfene Alternativen in V1

- Redis-pflichtiger Betrieb
- dauerhaft laufender `queue:work`-Daemon ohne Speedit-taugliche Alternative
- Secrets in Git
- gemeinsames Staging/Produktion-System
