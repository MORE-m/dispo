# ADR-002 – Technologie-Stack

- **Status:** Akzeptiert
- **Datum:** 29. August 2026
- **Entscheider:** bestätigt

## Änderungsprotokoll

| Datum | Art | Inhalt |
|---|---|---|
| 29. August 2026 | Erstfassung akzeptiert | Laravel, Inertia/React, MySQL, Pest, privater Dateispeicher |
| 29. August 2026 | Konkretisierung | Offene Punkte geschlossen; PHP-Produktionsziel 8.3; Test- und Betriebsstack festgelegt |

Die Konkretisierung schließt die in der Erstfassung unter „Noch zu bestätigen“
geführten Punkte. PHP 8.3 ist Mindest- und Produktionsziel (Speedit). PHP 8.4 bleibt
zulässig, ist aber keine V1-Voraussetzung. Diese Datei bleibt das akzeptierte
Stack-ADR; spätere Richtungswechsel erfordern ein neues ADR.

## Kontext

Das System benötigt starke serverseitige Fachlogik, relationale Transaktionen,
Excel-Importe, PDF-/Tabellenexporte, Queues, Rollen, Uploads sowie eine interaktive
Oberfläche für Kalenderplaner und dynamische Felder. Die Entwicklung erfolgt
überwiegend mit Cursor; Laravel-Erfahrung und vorhandene Laravel-Infrastruktur sind
bereits vorhanden.

## Entscheidung

| Ebene | Verbindlich für V1 |
|---|---|
| Architektur | modularer Laravel-Monolith (siehe ADR-001) |
| Backend | Laravel 13.x |
| Sprache | PHP 8.3 als Mindest- und Produktionsziel |
| Datenbank | MySQL mit InnoDB und utf8mb4 |
| Weboberfläche | Inertia 3 mit React 19 und TypeScript |
| Styling/Komponenten | Tailwind CSS 4; projektlokale shadcn/ui-Komponenten |
| Authentifizierung | lokaler Login mit E-Mail und Passwort sowie Passwort-Reset; öffentliche Self-Registration deaktiviert; keine verpflichtende 2FA |
| Queue, Cache, Sessions | jeweils Datenbanktreiber; **kein Redis** als V1-Voraussetzung |
| Dateien | privater lokaler Laravel-Filesystem-Disk; keine öffentlichen Datei-URLs; Zugriff nur über autorisierte Controller oder temporär autorisierte Downloads; Adapter austauschbar (später S3 ohne Fachlogikänderung) |
| E-Mail | SMTP; Versand über Queue; Wiederholungen; Versandprotokoll (`NOT-002`) |
| PDF | Dompdf mit separaten Blade-Templates (`REP-005`) |
| Excel | Laravel-13-kompatible Version von Laravel Excel / PhpSpreadsheet |
| Node.js | nur Entwicklung und Asset-Build, kein Produktionsprozess |
| Tests PHP | Pest |
| Tests Frontend | Vitest und Testing Library |
| Tests Browser | Playwright für Smoke-Tests |
| CI | GitHub Actions |
| Qualität | Laravel Pint, Prettier, PHPStan/Larastan, TypeScript-Prüfung |
| Zeit | interne Speicherung in UTC; Anzeige in `Europe/Berlin` (`GEN-003`) |
| Identifikatoren | technische ULIDs plus separate lesbare Vorgangsnummern (`TEC-001`) |
| Kaufmännische Werte | Geld-, Prozent- und TKP-Werte ausschließlich als `DECIMAL` |

## Browsermatrix

| Client | Unterstützung |
|---|---|
| Chrome | letzte zwei stabile Hauptversionen |
| Edge | letzte zwei stabile Hauptversionen |
| Safari | letzte zwei Hauptversionen |
| Firefox | aktuelle stabile Version |
| Geräte | Desktop/Laptop primär; Tablet sinnvoll nutzbar |
| Internet Explorer | keine Unterstützung |

## Warum Inertia statt separater API-SPA

- komplexe UI ohne doppelte Authentifizierungs-/API-Infrastruktur,
- serverseitige Laravel-Policies und Validierungen bleiben zentral,
- React eignet sich für Planer, dynamische Formulare und Regelbuilder,
- ein Deployment für Backend und Frontend.

## Adminoberfläche

Die fachlichen Adminmodule sollen dieselbe Design- und Berechtigungsbasis wie die
Hauptanwendung verwenden. Ein zusätzliches Adminframework darf nur eingesetzt
werden, wenn Versionierung, Vorschau, Audit und individuelle UX nicht dadurch
umgangen werden.

## Verworfene Alternativen in V1

- PostgreSQL als Pflicht-Datenbank
- Redis als Pflicht für Queue, Cache oder Sessions
- S3 oder anderer Objektspeicher als V1-Betriebsvoraussetzung
- öffentliche Datei-URLs
- verpflichtende 2FA
- öffentliche Benutzer-Selbstregistrierung
- Node.js als laufender Produktionsprozess

## Noch offen (blockiert Scaffolding nicht)

Betriebsdetails vor Produktivsetzung stehen in ADR-003, insbesondere exakte
Datenbankversion, Domain, SMTP-Zugang, SSH-Pfad, Cron, Backup-Aufbewahrung,
PHP-Erweiterungen und Speicherplatz. Der Stack selbst ist verbindlich.
