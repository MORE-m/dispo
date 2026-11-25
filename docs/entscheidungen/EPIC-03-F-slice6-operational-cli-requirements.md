# EPIC-03-F – Slice 6: Operative CLI-Anforderungen

**Status:** Nur Anforderungen. **Keine Implementierung in diesem Dokument/PR.**
**Datum:** 2026-11-25
**Vertrag:** [`EPIC-03-F-master-data-transfer-2-0-0.md`](EPIC-03-F-master-data-transfer-2-0-0.md)
**Ist-Code (Main nach PR #275):** Export V2 CLI vorhanden; Import V2 inhaltlich A–C in Tests; **operativer CLI-Import 2.0.0 gesperrt**

## 1. Ist-Zustand (verbindlich ablesen)

### Export – VORHANDEN

```text
php artisan dispo:export-master-data
  --path=
  --schema=1.0.0|2.0.0   (Default 1.0.0)
  --inventory=*            (nur 1.0.0; bei 2.0.0 verboten)
  --git-sha=
```

### Import – VORHANDEN für 1.0.0, GESPERRT für 2.0.0

```text
php artisan dispo:import-master-data {path}
  --dry-run | --apply      (genau eine Option)
  --confirm-database=
```

Aktuelles Verhalten bei `schema_version=2.0.0`:

- Command und `MasterDataImporterV2::import()` werfen:
  `Import für schema_version=2.0.0 ist operativ nicht freigegeben (Status-Phase und Staging-GO fehlen).`
- Phasen A–C laufen nur über isolierte Test-Hooks (`executePhasesABCForIsolatedTests` u. a.).

### Bereits im Service (nicht operativ verdrahtet)

- Schreib-Whitelist fail-closed
- Schutz-Snapshots und Diff
- Preislisten-Pin-Guard
- Security-Block im Report (`package_integrity`, `protection_snapshots`, `price_list_pins`, `whitelist`, `referential_integrity`, `result`)
- Forbidden-Test-DB-Namen für isolierte Ausführung
- Draft-only beim Inhalts-Apply

---

## 2. Ziel von Slice 6

Operative Freigabe des Schema-`2.0.0`-Pfads für **einen kontrollierten Staging-Ablauf**, ohne die Status-Phase still an den Inhalts-Apply zu hängen.

Slice 6 implementiert CLI-/Gate-Vertrag. Operative Ausführung bleibt an separate PO-GOs gebunden (Runbook).

---

## 3. Anforderungsliste

### 3.1 DB-Allowlist und `--confirm-database`

| ID | Anforderung | Priorität |
|---|---|---|
| CLI-01 | `--confirm-database` bleibt Pflicht für Dry-Run und Apply | muss |
| CLI-02 | Wert muss **exakt** dem aktiven `DB_DATABASE` entsprechen | muss |
| CLI-03 | Operative Allowlist für schreibende Läufe: ausschließlich `spn26jz7_dispo_staging` (konfigurierbar nur über klar dokumentierte Server-Config, nicht per freiem CLI-Override auf Quelle/Prod) | muss |
| CLI-04 | Apply gegen `dispo_mat_core`, `dispo`, Produktion und Alias-Namen → harter Abbruch | muss |
| CLI-05 | Dry-Run darf gegen Staging laufen; Dry-Run gegen Quelle nur wenn ausdrücklich erlaubt und schreibfrei verifiziert – Empfehlung: Dry-Run ebenfalls nur Staging-Allowlist | soll |

### 3.2 Trennung Dry-Run, Apply und Status-Phase

| ID | Anforderung | Priorität |
|---|---|---|
| CLI-10 | Genau eine Aktionsoption: `--dry-run` **oder** `--apply` **oder** Status-Aktion | muss |
| CLI-11 | `--apply` = nur Inhaltsphasen A–C (Schutz), Preise nur `draft`; **kein** Statuswechsel | muss |
| CLI-12 | Status-Phase als eigene Option, z. B. `--apply-price-status` (Name final in Implementierung festlegen) | muss |
| CLI-13 | Status-Phase nie implizit nach Apply | muss |
| CLI-14 | Schema-Erkennung aus Manifest; `2.0.0` → Importer V2; `1.0.0` → unverändert V1 | muss |
| CLI-15 | V1-Verhalten und Tests bleiben grün | muss |

### 3.3 Backup-Nachweis

| ID | Anforderung | Priorität |
|---|---|---|
| CLI-20 | `--apply` und Status-Phase verlangen Nachweis einer frischen Staging-Sicherung | muss |
| CLI-21 | Form offen, z. B. `--require-backup-proof=<path-or-token>` der auf Gate-PASS + Dump-Metadaten prüft | muss |
| CLI-22 | Ohne gültigen Nachweis → STOP vor Schreibbeginn | muss |
| CLI-23 | Dry-Run: Backup-Nachweis optional, aber Report soll warnen wenn fehlend | soll |

### 3.4 Paket-SHA256-Bindung

| ID | Anforderung | Priorität |
|---|---|---|
| CLI-30 | Vor jedem Dry-Run/Apply/Status: Manifest + `checksums.sha256` validieren | muss |
| CLI-31 | Optional/empfohlen Pflicht: `--require-package-sha256=<hex>` muss zum geladenen Paket passen | muss für Apply+Status; soll für Dry-Run |
| CLI-32 | Status-Phase nur gegen dasselbe SHA256-Paket wie der freigegebene Apply | muss |
| CLI-33 | Abweichung → STOP, keine Teilverarbeitung | muss |

### 3.5 Sicherheitsbericht

| ID | Anforderung | Priorität |
|---|---|---|
| CLI-40 | JSON-Report wie bisher persistieren (Pfad ausgeben) | muss |
| CLI-41 | Security-Block mit: package_integrity, protection_snapshots, protected_table_counts, price_list_pins, whitelist, referential_integrity, result PASS/STOP | muss |
| CLI-42 | Exit-Code ≠ 0 bei STOP/Konflikten | muss |
| CLI-43 | Keine PII/Secrets im Report (Digests/Counts, keine Passwort-Hashes) | muss |
| CLI-44 | Zusammenfassung creates/unchanged/updates/conflicts auf STDOUT | muss |

### 3.6 Integrität und Idempotenz

| ID | Anforderung | Priorität |
|---|---|---|
| CLI-50 | Dry-Run plant Phasen A–C schreibfrei inkl. virtueller Referenzauflösung | muss |
| CLI-51 | Konflikte → kein Schreiben | muss |
| CLI-52 | Apply in einer Transaktion für Inhaltsphasen; Rollback bei Fehler | muss |
| CLI-53 | Zweiter Lauf desselben Pakets: 0 Creates, 0 Updates, conflicts=0 | muss |
| CLI-54 | Whitelist-Guard vor und während Apply | muss |
| CLI-55 | Pin- und Schutz-Snapshot-Prüfung vor Schreiben und nach Plan/Apply | muss |

### 3.7 Klare Abbruchbedingungen

Sofortiger Abbruch (nicht abschließende Liste, Vertrag Abschnitt 13 gilt):

1. Schema nicht `2.0.0` für diesen Pfad / Manifest ungültig
2. SHA256-/Checksum-Fehler
3. `--confirm-database` mismatch oder DB nicht allowlistet
4. Backup-Nachweis fehlt/ungültig (Apply/Status)
5. Konflikte an Fachschlüssel, Fingerabdruck, Metadaten, Regelmenge
6. Schutz-Snapshot- oder Pin-Drift
7. Schreibversuch außerhalb Whitelist
8. Paket enthält ausgeschlossene Entitäten
9. Status-Phase ohne eigenes Flag/GO-Pfad oder gegen anderes Paket
10. Versuch, fehlende Produktionspreise zu erfinden (kein Generator im CLI)

---

## 4. Vorgeschlagene CLI-Oberfläche (nur Entwurf)

```text
# Dry-Run Staging
php artisan dispo:import-master-data <PAKET> \
  --dry-run \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX>

# Inhalts-Apply
php artisan dispo:import-master-data <PAKET> \
  --apply \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX> \
  --require-backup-proof=<PROOF>

# Status-Phase (separates GO)
php artisan dispo:import-master-data <PAKET> \
  --apply-price-status \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX> \
  --require-backup-proof=<PROOF>
```

Parameternamen sind Vorschläge; verbindlich ist die Semantik.

---

## 5. Explizit nicht Teil von Slice 6

- Operativer Export-/Import-Lauf gegen echte DBs im Implementierungs-PR
- Automatische Konflikt-Reparatur
- Dateitransfer von Inventar-Logos
- CRM-/User-Import
- Änderung am Vertrag 1.0.0-Verhalten außer Schema-Routing
- UI für den Transfer

---

## 6. Abnahmekriterien Slice 6 (Code)

1. Feature-Tests: CLI Dry-Run 2.0.0 schreibfrei gegen isolierte Test-DB.
2. Feature-Tests: Apply 2.0.0 mit Allowlist/Confirm/Backup/SHA Gates.
3. Feature-Tests: Status-Phase getrennt; greift keine Pins; nur draft→active/archived nach Regeln.
4. Negativtests: Quelle/Prod Apply, Checksum-Mismatch, Whitelist-Verstoß, Pin-Konflikt.
5. V1-Suite unverändert grün.
6. Docs/Runbook-Befehle von „OFFEN“ auf „VORHANDEN“ aktualisieren (Folge-PR oder derselbe Slice-PR).

---

## 7. Abhängigkeit zum operativen Dry-Run

Ohne Erfüllung von CLI-01–CLI-55 (Mindestumfang muss-Zeilen) ist der erste kontrollierte Staging-Dry-Run für Schema 2.0.0 **nicht** freigabefähig, selbst wenn Export V2 bereits existiert.
