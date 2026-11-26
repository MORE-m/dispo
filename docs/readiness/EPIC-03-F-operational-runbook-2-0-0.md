# EPIC-03-F – Operatives Runbook Schema 2.0.0

**Status:** Vorbereitung. **STOP** vor jedem Datenlauf ohne separates PO-GO.
**Datum:** 2026-11-25
**Vertrag:** [`../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md`](../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md)
**Index:** [`EPIC-03-F-operational-readiness-2-0-0.md`](EPIC-03-F-operational-readiness-2-0-0.md)

## Legende zu Befehlen

| Kennzeichnung | Bedeutung |
|---|---|
| **VORHANDEN** | Befehl/Parameter existiert im aktuellen `main`-Code |
| **OFFEN (Slice 6a/6b/7)** | Noch nicht operativ freigegeben oder Parameter fehlt; hier nur als Anforderung notiert |
| **MANUELL** | Operativer Host-/DB-Schritt außerhalb der Artisan-CLI |

In diesem Dokument werden **keine** Befehle ausgeführt. Platzhalter wie `<PAKET>` und `<BACKUP>` sind vor Ort zu ersetzen.

---

## Operative Checkliste

| # | Schritt | GO nötig | Abbruch wenn |
|---|---|---|---|
| 1 | Technischen Stand und CI-Gates prüfen | nein (Preflight) | CI rot, Schema-Mismatch, Deploy ≠ erwarteter SHA |
| 2 | Quell- und Staging-DB identifizieren | nein | DB-Name unklar oder falsch verdrahtet |
| 3 | Ausgangsbestand beider DBs dokumentieren | nein | Zählung nicht reproduzierbar |
| 4 | Staging-Sicherung + Wiederherstellbarkeit | nein | Backup-Gate ≠ PASS |
| 5 | PO-GO Export | **ja, separat** | kein schriftliches GO |
| 6 | Gesamtexport 2.0.0 | nach #5 | Exportfehler, falsche DB |
| 7 | Manifest / Pflichtdateien / SHA256 | nein | Abweichung → STOP |
| 8 | PO-GO genau ein Staging-Dry-Run | **ja, separat** | kein GO oder >1 Dry-Run geplant |
| 9 | Dry-Run-Bericht + Konflikte | nach #8 | conflicts > 0 oder security ≠ PASS |
| 10 | PO-GO Apply | **ja, separat** | kein GO |
| 11 | Apply + Nachkontrolle | nach #10 | Fehler, Schutz-Drift, Pin-Drift |
| 12 | Idempotenzprüfung (kein zweiter Staging-Lauf ohne GO) | nach #11; operativ nur mit GO | zusätzliche Creates/Updates; ungeplanter Zweitlauf |
| 13 | Preisstatus-Phase | **ja, eigenes GO** | ohne GO oder anderes Paket |

---

## 1. Technischen Stand und CI-Gates prüfen

### Ziel

Sicherstellen, dass der ausführende Stand dem freigegebenen `main` entspricht und CI grün ist.

### Prüfungen (MANUELL / Repo)

1. `git rev-parse HEAD` auf dem Deploy-/Export-Host notieren.
2. Prüfen, dass der Stand Merge von Slice 1–5 enthält (Paket, Exporter V2, Importer Phasen A–C).
3. GitHub Actions / CI für den Deploy-SHA: alle Gates grün.
4. Schema beider DBs: gleiche Migrationsanzahl (historische Lesung 23.11.2026: je 79 Migrationen – **nur Referenz**, vor Lauf erneut zählen).
5. Bestätigen: für den geplanten Schritt die passende CLI-Phase freigeschaltet ist (siehe Teil E: **6a** Dry-Run, **6b** Apply, **7** Status). **Aktuell OFFEN:** CLI lehnt `schema_version=2.0.0` ab.

### PASS / STOP

| PASS | STOP |
|---|---|
| Deploy-SHA dokumentiert, CI grün, benötigte Phase (6a/6b/7) freigegeben | CI rot, SHA unklar, benötigte Phase noch gesperrt |

---

## 2. Quell- und Staging-Datenbanken eindeutig identifizieren

| Rolle | Erwarteter DB-Name | Verwendung |
|---|---|---|
| Quelle | `dispo_mat_core` | nur Export (lesend) |
| Staging | `spn26jz7_dispo_staging` | Dry-Run / Apply / Status |
| Verboten für Apply | `dispo_mat_core`, `dispo`, Produktion | jeder Apply → STOP |

### Vorhandene CLI-Guards (Schema 1.0.0-Importer)

Apply gegen `dispo_mat_core` / `dispo` ist im V1-Importer blockiert. V2 hat zusätzlich `FORBIDDEN_TEST_DATABASES` für isolierte Tests.

### OFFEN (Slice 6a/6b)

- Explizite Allowlist nur `spn26jz7_dispo_staging` für operative Läufe (6b Apply zwingend; 6a Dry-Run empfohlen/soll)
- Pflicht `--confirm-database` muss exakt dem aktiven Staging-Namen entsprechen
- Fail-closed, wenn `DB_DATABASE` ≠ confirm

### PASS / STOP

| PASS | STOP |
|---|---|
| Quelle und Staging namentlich und per App-Config eindeutig | Verwechslung, Apply-Ziel ≠ Staging |

---

## 3. Ausgangsbestand beider Datenbanken dokumentieren

Vor jedem operativen Fenster eine **frische** Lesung der 25 Whitelist-Tabellen plus Schutztabellen (siehe Matrix B und C).

Historische Referenzwerte vom **23.11.2026** stehen in der Fach-Readiness und in Matrix B. Sie sind **keine aktuellen Ist-Werte**.

Dokumentieren je Seite:

- Count je Fachtabelle
- Spot-/Produktions-/OA-TKP-Status-Summary (active / archived / draft)
- Schutz-Counts: users, CRM, calculations (+Kinder), dispo_orders (+Kinder), Snapshots, Nummernkreise
- Pin-Übersicht der Staging-Vorgänge (Spot-/Produktionslisten-IDs und Versionen)

### PASS / STOP

| PASS | STOP |
|---|---|
| Ausgangsprotokoll mit Zeitstempel und DB-Namen abgelegt | Nur historische Zahlen ohne Neuablesung |

---

## 4. Vollständige Staging-Sicherung und Wiederherstellbarkeit

### MANUELL (Staging-Host, Muster aus EPIC-03-C)

Historischer Pfad aus Pilot-Prep (Referenz, vor Ort bestätigen):

- Backup-Verzeichnis: `/files/dispo-staging/backups/` (außerhalb Webroot, mode 600)
- Gate-Datei: `BACKUP_GATE.txt` → muss **PASS** zeigen
- Restore-Pfad historisch: `same_db_import_verified` (kein CREATE-DATABASE-Privileg für Temp-DB)

### Drei getrennte Nachweisebenen: A Integrität / B Provenienz / C Restore

| Ebene | Frage | Wer prüft | Stand |
|---|---|---|---|
| **A – Integrität** | Ist der Dump vollständig und unverändert? | Code (`MasterDataBackupProofV2`): `BACKUP_GATE.txt`=PASS, Dump vorhanden, Größe + SHA256 passen | VORHANDEN |
| **B – Provenienz** | Stammt der Dump nachweislich von der richtigen DB/Umgebung/Instanz, zum richtigen Zeitpunkt, aus einem echten Backup-Prozess und passt er zu diesem Paket/Apply? | Code: signierte `DUMP_META.json` (Proof-Version 2, HMAC), siehe unten | VORHANDEN (Slice 6b-Härtung) |
| **C – Restore-Verifikation** | Lässt sich der Dump tatsächlich wiederherstellen? | **MANUELL**, nie durch den Importer; siehe „Optionale Trockenrestore-Prüfung" | Dokumentiert, nicht automatisiert |

A und B beweisen **nicht**, dass ein Restore funktioniert (C). Ohne C bleibt Restore-Fähigkeit eine dokumentierte Annahme, kein technischer Beweis.

### Backup-Proof Version 2 (Pflichtformat)

`--require-backup-proof=<PROOF_DIR>` (Apply) bzw. Backup B bei den Preisstatus-Modi zeigt auf ein Verzeichnis mit:

- `BACKUP_GATE.txt` (erste nicht-leere Zeile exakt `PASS`)
- `DUMP_META.json` mit **Pflichtfeldern**: `proof_version=2`, `backup_run_id`, `phase`, `created_at`, `database_name` (aus `SELECT DATABASE()` zum Erstellzeitpunkt, `database_name_source=select_database`), `target_environment`, `instance_identity` (optional; Pflichtabgleich wenn `MASTER_DATA_TRANSFER_INSTANCE_ID` gesetzt), `package_sha256`, `dump_filename`, `dump_sha256`, `dump_bytes`, `restore_path`, `backup_process_result=PASS`, `content_receipt_id` / `content_receipt_signature` (nur Backup B), `signature` (HMAC-SHA256 über kanonisches JSON)
- die Dump-Datei

Geprüft wird: Signatur gültig (nicht frei editierbar), `database_name` = aktive Staging-DB, `target_environment` = aktive `APP_ENV`, Instanz (falls konfiguriert), Paket-SHA256 identisch, Phase passt, `created_at` nicht in der Zukunft und nicht älter als `MASTER_DATA_TRANSFER_BACKUP_PROOF_MAX_AGE_MINUTES` (Default 1440). **Alte Proofs ohne Version 2 + Signatur werden abgelehnt.**

Geheimnis: `MASTER_DATA_TRANSFER_PROOF_SECRET` (optional); leer = aus `APP_KEY` abgeleitet. Die Signatur schützt gegen freie/versehentliche Edits und Handbauten, **nicht** gegen einen kompromittierten Host.

Phasen:

| Phase | Zweck | Bindung |
|---|---|---|
| `pre_content_apply` (Backup A) | vor Inhalts-Apply (`--apply`) | an Paket-SHA256 |
| `post_content_apply` (Backup B) | vor Preisstatus-Phase (Slice 7) | an Content-Apply-Receipt; `created_at` ≥ `completed_at` des Receipts |

### Proof erzeugen (Staging-Ops-Helper, schreibt keine DB, führt keinen Dump aus)

Dump wird extern erstellt (z. B. `mysqldump` des Hosts). Danach den Proof mit dem Helper erzeugen — er ermittelt die DB-Identität über dieselbe Guard-Kette wie Apply (`--confirm-database`, Allowlist, `SELECT DATABASE()`) und prüft einen ggf. vorhandenen `mysqldump`-Header (`Database:`) gegen die aktive DB:

```bash
# Backup A (vor Inhalts-Apply)
php artisan dispo:master-data-backup-proof <DUMP_DATEI> \
  --output-dir=<NEUES_PROOF_DIR> \
  --phase=pre_content_apply \
  --package-sha256=<HEX> \
  --confirm-database=spn26jz7_dispo_staging \
  --restore-path=same_db_import_verified
```

Backup B (`--phase=post_content_apply --content-receipt=<RECEIPT.json>`) wird mit dem Content-Apply-Receipt (Abschnitt 11/13) erzeugt; der Helper verifiziert das Receipt (Signatur, Paket, DB, Umgebung). Der Helper verweigert bestehende Proof-Verzeichnisse (kein Überschreiben). Tests synthetisieren Proofs ausschließlich über denselben Builder (`MasterDataBackupProofBuilderV2`).

### Optionale Trockenrestore-Prüfung (Ebene C, MANUELL, NICHT gegen Staging)

Nur auf einer **isolierten temporären Datenbank** (nicht `spn26jz7_dispo_staging`, nicht `dispo_mat_core`):

1. Temporäre DB anlegen, z. B. `dispo_restore_check` (falls kein CREATE-Privileg: ein anderer Host/Instanz).
2. `mysql dispo_restore_check < <DUMP_DATEI>`; Fehlerfrei = Mindestnachweis.
3. Stichproben: Tabellenanzahl, Count der 25 Whitelist-Tabellen und Schutztabellen gegen das Ausgangsprotokoll (Abschnitt 3).
4. Temp-DB wieder löschen; Ergebnis (Datum, Prüfer, Counts) im Betriebsprotokoll festhalten.
5. **Kein** Restore auf Staging im Rahmen der Prüfung. Ein echter Staging-Restore ist ein eigener, separat freizugebender Recovery-Schritt (siehe Risiken/Recovery).

### Mindestanforderungen an die Sicherung

1. Frischer Dump **vor** Apply.
2. Dateiname, Größe, SHA256 des Dumps im Proof.
3. Restore-Weg schriftlich bestätigt (`restore_path`), Ebene C separat nachgewiesen oder bewusst als offenes Risiko akzeptiert.
4. Keine Secrets im Ticket-Body; nur Pfad/Hash-Referenzen.

### PASS / STOP

| PASS | STOP |
|---|---|
| Frisches Backup + Gate PASS + Restore-Pfad klar | Gate FAIL, Dump fehlt, Restore unklar |

Siehe auch [`EPIC-03-F-risks-recovery-2-0-0.md`](EPIC-03-F-risks-recovery-2-0-0.md).

---

## 5. Separates PO-GO für den echten Export

Schriftliches GO mit:

- Quelle = `dispo_mat_core`
- Schema = `2.0.0`
- Gesamtpaket (kein Inventar-Teilpaket)
- Export-SHA / App-Stand
- Hinweis: kein Staging-Schreiben in diesem Schritt

Ohne GO → **STOP**.

---

## 6. Gesamtexport Schema 2.0.0

### VORHANDEN

```bash
php artisan dispo:export-master-data \
  --schema=2.0.0 \
  --path=<PAKET_DIR> \
  --git-sha=$(git rev-parse HEAD)
```

Hinweise laut aktuellem Code:

- Default ohne `--schema` ist weiterhin `1.0.0` – für EPIC-03-F **immer** `--schema=2.0.0` setzen.
- `--inventory` ist bei `2.0.0` **verboten** (CLI wirft Fehler).
- Export schreibt JSON unter `data/`, plus `manifest.json` und `checksums.sha256`.

### Nach Export notieren

- `package_path`
- Manifest-Felder: `schema_version`, `epic`, `source_database`, `git_sha`, `exported_at`, Counts
- `source_price_status_summary`
- Flags: `includes_users=false`, `includes_crm=false`, `includes_calculations=false`, `includes_dispo_orders=false`, `includes_online_audio=true`, `price_status_on_apply=draft`

### PASS / STOP

| PASS | STOP |
|---|---|
| Export OK, Schema 2.0.0, Pflichtflags korrekt | Exportfehler, falsches Schema, Teilpaket |

---

## 7. Manifest, Pflichtdateien und SHA256 prüfen

### Pflichtdateien (Vertrag)

```text
manifest.json
checksums.sha256
data/organization.json
data/calculation_methods.json
data/advertising_categories.json
data/advertising_media.json
data/advertising_category_calculation_methods.json
data/advertising_medium_calculation_methods.json
data/inventories.json
data/inventory_medium_rules.json
data/price_lists.json
data/production_price_lists.json
data/oa_platforms.json
data/oa_platform_medium_rules.json
data/oa_targeting_definitions.json
data/oa_tkp_price_lists.json
data/oa_adserver_surcharges.json
data/field_definitions.json
data/field_definition_revisions.json
data/field_definition_revision_options.json
data/field_rules.json
data/field_sets.json
data/field_set_versions.json
data/field_set_version_fields.json
data/field_set_assignments.json
```

### MANUELL – Prüfsummen

```bash
cd <PAKET_DIR>
# Beispiel: alle Dateien außer checksums.sha256 gegen checksums.sha256 prüfen
# (konkretes Verifikationskommando der Plattform nutzen; Abweichung = STOP)
shasum -a 256 -c checksums.sha256
```

Zusätzlich:

- Jede Pflichtdatei vorhanden (leere Arrays sind erlaubt)
- Manifest-Counts stimmen mit Dateiinhalten überein
- Keine Users/CRM/Calcs/Dispo im Paket
- Paket-SHA256 (Gesamtpaket oder kanonischer Digest) für spätere Bindung notieren

### OFFEN (Slice 6a/6b/7)

- CLI-Parameter, der den Paket-SHA256 beim Import erzwingt (`--require-package-sha256=` o. ä.; für Apply/Status Pflicht laut 6b/7)

### PASS / STOP

| PASS | STOP |
|---|---|
| Manifest + SHA256 + Pflichtdateien ok | Fehlende Datei, Checksum-Mismatch, falsche Flags |

---

## 8. Separates PO-GO für genau einen Staging-Dry-Run

Schriftliches GO mit:

- Ziel-DB = `spn26jz7_dispo_staging`
- Genau **ein** Dry-Run gegen das freigegebene Paket (SHA256 gebunden)
- Kein Apply in diesem GO
- Erwartete Auswertung gegen Matrix B/C

Ohne GO → **STOP**. Mehrere parallele Dry-Runs gegen Staging sind nicht vorgesehen.

---

## 9. Dry-Run-Bericht und Konflikte auswerten

### VORHANDEN (Schema 1.0.0-Muster; Syntax)

```bash
php artisan dispo:import-master-data <PAKET_DIR> \
  --dry-run \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX>
```

### VORHANDEN (Slice 6a) – Dry-Run

Im aktuellen Stand (nach Slice 6a) gilt:

- `ImportMasterDataCommand` erkennt `schema_version=2.0.0` und routet `--dry-run` an `MasterDataImporterV2::dryRunOperational()`.
- Pflicht: `--confirm-database=spn26jz7_dispo_staging` und `--require-package-sha256=<64-hex>`.
- `--apply` ist für `2.0.0` freigegeben (Slice 6b) mit Pflicht `--require-package-sha256` und `--require-backup-proof`.
- `--apply-price-status` bleibt für `2.0.0` gesperrt (Slice 7).
- Isolierte Test-Hooks (`executePhasesABCForIsolatedTests`) bleiben getrennt und unverändert fail-closed.

Beispiel (nach Export und PO-GO; `<HEX>` = `MasterDataPackage::computePackageSha256`):

```bash
php artisan dispo:import-master-data <PAKET_DIR> \
  --dry-run \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX>
```

Auch den früheren Zielbefehl in Schritt 9 um `--require-package-sha256` ergänzen.

### Bericht auswerten

Erwartung laut Vertrag / historischer Readiness (Referenz, nicht Ist):

- Viele Creates für fehlende Inventare, Medien, Regeln, OA, Draft-Preise
- Unchanged für bereits passende RH-/Gleich-Bestände
- `update` nur für erlaubte Kategorie-Metadaten
- `conflicts = 0`
- Security-Block: `package_integrity`, `protection_snapshots`, `price_list_pins`, `whitelist`, `referential_integrity` → PASS; `result` → PASS

Jedes Konflikt → **STOP**, keine automatische Reparatur.

Vergleich gegen [`EPIC-03-F-data-reconciliation-matrix-2-0-0.md`](EPIC-03-F-data-reconciliation-matrix-2-0-0.md) und [`EPIC-03-F-protection-acceptance-matrix-2-0-0.md`](EPIC-03-F-protection-acceptance-matrix-2-0-0.md).

---

## 10. Separates PO-GO für Apply

Schriftliches GO nur wenn:

- Dry-Run PASS, conflicts = 0
- Security PASS
- Frisches Backup Gate PASS
- Paket-SHA256 unverändert zum Dry-Run-Paket
- Keine unerwartete Staging-Änderung seit Dry-Run

Ohne GO → **STOP**.

---

## 11. Apply und vollständige Nachkontrolle

### VORHANDEN (Slice 6b; Status = Slice 7 weiterhin OFFEN)

```bash
php artisan dispo:import-master-data <PAKET_DIR> \
  --apply \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX> \
  --require-backup-proof=<PROOF_DIR>
```

`--require-backup-proof` zeigt auf ein **Backup-Proof-Verzeichnis (Version 2, Phase `pre_content_apply`)** gemäß Abschnitt 4 (Gate, signierte `DUMP_META.json`, Dump). Proofs im alten Format (ohne Provenienz/Signatur), für eine andere DB/Umgebung/Instanz, ein anderes Paket oder älter als das Maximalalter werden **vor dem ersten Schreibzugriff** abgelehnt.

Preise nur als `draft`. Status-Phase bleibt **Slice 7** und ist nicht Teil von `--apply`.

### Nachkontrolle

1. Import-Report: creates/unchanged/conflicts
2. Security-Report erneut PASS
3. Schutzmatrix C: Vorher/Nachher Digests und Stichproben
4. Fachmatrix B: Stichproben Creates und Unchanged
5. Neue Preise nur als `draft`
6. Bestehende aktive/archivierte RH-Listen unverändert bei gleichem Fingerabdruck
7. Inventar-`logo_path`: Pfadwerte im DB-Feld; fehlende Binärdateien im Storage separat melden (kein Auto-Fix)

---

## 12. Idempotenzprüfung

Ziel: Nachweis, dass dasselbe unveränderte Paket keine zusätzlichen Creates/Updates erzeugt.

**Operativ (Staging):** Der Idempotenz-Recheck darf **keinen zweiten** Staging-Dry-Run und **keinen zweiten** Apply auslösen, ohne dass dafür ein **separates PO-GO** vorliegt. Schritt 8 (genau ein Dry-Run) und Schritt 10/11 (Apply) bleiben die einzigen freigegebenen operativen Läufe, bis ein neues GO etwas anderes erlaubt.

**Synthetische Tests:** Automatische Idempotenzprüfungen in Feature-/Unit-Tests (isolierte Test-DB, keine Staging-Verbindung) sind davon unabhängig und weiterhin vorgesehen.

Wenn ein operatives Idempotenz-GO erteilt wird, gilt gegen dasselbe unveränderte Paket:

- keine zusätzlichen Creates
- keine Updates
- conflicts = 0
- Schutz-Snapshots und Pins unverändert

Abweichung → **STOP**, Recovery laut Teil D.

---

## 13. Spätere Preisstatus-Phase (eigenes GO)

Nicht Teil von Schritt 11.

Erlaubt laut Vertrag nur:

- `draft` → `active`, wenn `source_status=active` und Fingerabdruck/Metadaten unverändert
- `draft` → `archived`, wenn `source_status=archived` und Fingerabdruck/Metadaten unverändert

Nicht erlaubt:

- Statuswechsel an bereits active/archived Listen
- Statuswechsel an gepinnten Listen
- anderes oder verändertes Paket
- Inhaltsänderungen in der Status-Phase

### OFFEN (Slice 7)

```bash
# OFFEN – Beispielvertrag, Parameter noch nicht implementiert:
php artisan dispo:import-master-data <PAKET_DIR> \
  --apply-price-status \
  --confirm-database=spn26jz7_dispo_staging \
  --require-package-sha256=<HEX>
```

Ohne separates PO-GO → **STOP**. Slice 7 ist keine Voraussetzung für Dry-Run (6a) oder Inhalts-Apply (6b).

---

## Pakettransfer (MANUELL)

Zwischen Export-Host und Staging:

1. Paket außerhalb Document Root ablegen
2. SHA256 nach Transfer erneut prüfen
3. Keine Secrets im Paket (Vertrag schließt Users/CRM aus)

---

## Was dieses Runbook bewusst nicht tut

- Keine echten Exporte/Imports
- Keine DB-Verbindungen im Rahmen der Doku-Erstellung
- Keine CLI-Implementierung
- Keine automatische Korrektur von Konflikten
- Keine Erfindung fehlender Produktionspreise 2027
