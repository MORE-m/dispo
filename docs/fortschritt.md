# Fortschritt V1

Stand: 29. August 2026

## Aktuelle Phase

Phase 0 ist lokal nachgearbeitet, aber **nicht endgültig abgenommen**.
Endgültige Abnahme setzt voraus, dass nach Push/PR die GitHub-Actions-Jobs
`ci` und `mysql` grün sind. **Halt vor Phase 1** wegen `BL-GATE-UXUI`.

## Aktuelle Aufgabe

Keine Implementierungsaufgabe. Nächster Schritt: Push/PR für GitHub Actions;
danach Product-Owner-Freigabe des UX/UI-Gates. Keine Fachseiten.

## Zuletzt abgeschlossene Aufgabe

Phase-0-Nacharbeit: Kontoselbstlöschung entfernt, PrivateFileStorage-Tests
isoliert, Traceability korrigiert.

## Technisch vorbereitet, noch nicht fachlich vollständig erfüllt

Diese IDs sind in der Projektbasis angelegt oder vorbereitet. Die vollständige
fachliche Erfüllung erfolgt erst in den späteren Backlog-Paketen:

- `AUTH-001` / `AUTH-003` – Headless-Rollen und Gate `access-administration`;
  vollständig in `BL-P1-02` (und Folgepakete)
- `UPL-005` – physisches Löschen nur für temporäre Pfade in `PrivateFileStorage`;
  Upload-Archivierung vollständig in `BL-P9-01`
- Kapitel 24 (Sicherheit, Betrieb, Dateien ohne öffentliche URL) in der Projektbasis
- `GEN-003` intern UTC, Anzeige `Europe/Berlin` (Konfiguration)
- Zugang V1 (Headless): Login E-Mail/Passwort, Logout, Passwort-Reset,
  Passwortänderung; keine öffentliche Registrierung; keine Passkeys/2FA;
  kein E-Mail-Verifizierungsflow; keine Kontoselbstlöschung

## Ausgeführte Prüfungen und Ergebnisse

Frische Kopie `/tmp/dispo-fresh-clone-p0-v3` (nach `composer install`, `npm ci`,
`npm run build` und vorhandenen Laravel-Storage-Platzhaltern):

- SQLite: alle vier Migrationen; Pest 39 Tests, **36 bestanden**, 3 übersprungen, 95 Assertions
- MySQL: alle vier Migrationen; Pest **39/39**, 98 Assertions
- Pint, PHPStan (0 Fehler): bestanden
- `npm run check`: bestanden
- `npm run types:check` (nach Build/Wayfinder): bestanden
- Vitest: 2 Tests bestanden
- Playwright: 2/2 bestanden
- `composer audit` / `npm audit --omit=dev`: keine Advisories / 0 Schwachstellen
- nach Pest keine Datei `storage/app/private/health-check/smoke.txt`

GitHub Actions (`ci`, `mysql`) auf GitHub: noch nicht ausgeführt.

## Bekannte technische Schulden

- Starter-Kit-Login und Welcome sind **provisorisch**, kein abgenommenes Fach-UI
- Factory-Default-Rolle `sales` nur für Tests, keine produktive Nutzeranlage
- PHPStan lokal mit `--memory-limit=1G` (CI ebenso)
- Speedit-Betriebsparameter (inkl. PHP-CLI-Pfad) und Initialkataloge weiterhin
  vor Produktivsetzung zu verifizieren (BLK-001, BLK-002)
- `laravel/passkeys` bleibt Composer-Transitivabhängigkeit von Fortify, wird
  nicht auto-discovered und registriert keine Routen

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-003 | UX/UI-Gate: Product-Owner-Freigabe und Artefakte fehlen – **blockiert Phase-1-Fachoberflächen** |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy, inkl. PHP-CLI-Pfad |
| BLK-004 | Phase-0-Abnahme: GitHub-Actions-Jobs `ci` und `mysql` nach Push/PR noch nicht grün nachgewiesen |

## Exakt nächste ausführbare Aufgabe

Push oder Pull Request, damit `ci` und `mysql` auf GitHub laufen. Fachoberflächen
erst nach `BL-GATE-UXUI`.
