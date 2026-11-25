# EPIC-03-F – Schutz- und Abnahmematrix Schema 2.0.0

**Status:** Vorbereitung. Vorher-/Nachher-Prüfung für geschützte Staging-Bestände.
**Datum:** 2026-11-25
**Vertrag:** Abschnitte 5 und 7 in [`../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md`](../entscheidungen/EPIC-03-F-master-data-transfer-2-0-0.md)
**Code-Bezug (Main nach PR #275):** `MasterDataProtectedStateSnapshotV2`, `MasterDataPriceListPinGuardV2`, Security-Block in `MasterDataImportReport`

## Grundsatz

Zähler allein reichen nicht. Identische Datensatzanzahl kann Inhalts- oder Referenzänderungen verdecken. Deshalb:

1. **Count** vor/nach
2. **Kanonischer Digest** über fachlich relevante Spalten (ohne reine Zeitstempel laut Guard)
3. **Stichproben / fachliche Anker** (IDs, Pins, Nummern, Status)

Im Importer V2 Phase C liefert der Security-Report bereits Digests/Counts für ausgeschlossene Tabellen und Pin-Statistiken. Operative Abnahme verlangt zusätzlich die hier genannten Belege.

Historische Staging-Anker aus EPIC-03-C/D (Referenz, keine Ist-Zusage): Admin vorhanden; Testkunde; Kalkulationen `K-2026-00001` / `K-2026-00002`; Dispo `DA-2026-00001-01`; RH-Spotlisten 2026/2027 r1 aktiv; Produktionsliste 2026.

---

## A. Staging-Admin und Benutzer

| Prüfung | Vorher | Nachher | Inhaltsänderung trotz gleicher Anzahl erkennen | PASS | STOP | Beleg |
|---|---|---|---|---|---|---|
| `users` Count | dokumentieren | identisch | Digest über fachliche Spalten inkl. Rolle, Aktiv-Flag, E-Mail, 2FA-/Verify-Flags; **nicht** ignorieren: `deleted_at`, `email_verified_at`, `two_factor_confirmed_at` | Count+Digest gleich; Admin-Identität gleich | Drift in Digest/Count/Admin-Feldern | Security-Report `protected_table_counts.users`; Admin-Stichprobe (id, email, role, is_active) |
| Admin-Login/Rolle | Admin aktiv | unverändert | Rollen-/Flag-Vergleich | Admin unverändert | Admin deaktiviert, Rolle/Passwort-Hash geändert | Stichprobe vor/nach; kein Hash-Dump ins Ticket |

---

## B. CRM-Daten

| Prüfung | Vorher | Nachher | Inhaltsänderung trotz gleicher Anzahl | PASS | STOP | Beleg |
|---|---|---|---|---|---|---|
| `crm_accounts` | Count + Digest | identisch | Identität/Referenzfelder ohne Secrets; Salesforce-IDs und Testkunden-Keys | unverändert | jede Drift | Security-Report + Testkunden-Stichprobe |
| `crm_account_versions` | Count + Digest | identisch | Versionsinhalt/FK zum Konto | unverändert | Drift | Security-Report |
| `crm_imports` / `crm_conflicts` | Count + Digest | identisch | Lauf-/Konfliktinhalt | unverändert | Drift | Security-Report |
| `crm_shared_email_domains` | Count + Digest | identisch | Domain-Inhalt | unverändert (hist. bereits gleich) | Drift | Security-Report |

---

## C. Vorhandene Kalkulationen und Kinder

| Prüfung | Vorher | Nachher | Inhaltsänderung trotz gleicher Anzahl | PASS | STOP | Beleg |
|---|---|---|---|---|---|---|
| `calculations` | Count + Digest | identisch | Nummern, Status, Snapshot-/Preislisten-Referenzen, CRM-/User-FKs | unverändert | Drift | Security-Report; Stichprobe K-Nummern |
| Calc-Kinder (field values, positions, components, discounts, OA-Allocations, Targeting, Planner, Production lines, Time ranges) | Counts + Digests je Tabelle | identisch | Positionsinhalt, Preislisten-Pins, Snapshot-FKs | alle Kinder unverändert | Drift in einer Kindtabelle | Security-Report je Tabelle |
| Fachanker Beträge/Pins | dokumentierte N/N und Pin-IDs | gleich | Betrags-/Pin-Abweichung bei gleichem Count | Anker gleich | Betrag oder Pin geändert | Browser-/SQL-Stichprobe laut Abnahme |

---

## D. Bestehende Dispoaufträge

| Prüfung | Vorher | Nachher | Inhaltsänderung trotz gleicher Anzahl | PASS | STOP | Beleg |
|---|---|---|---|---|---|---|
| `dispo_orders` | Count + Digest | identisch | Nummer, Status, Snapshot-/Preis-Refs | unverändert | Drift | Security-Report; DA-Nummer |
| Dispo-Kinder (positions, field values, comments, uploads, status events, approvals) | Counts + Digests | identisch | Positions-/Kommentar-/Statusinhalt | unverändert | Drift | Security-Report |

---

## E. Konfigurations-Snapshots

| Prüfung | Vorher | Nachher | Inhaltsänderung trotz gleicher Anzahl | PASS | STOP | Beleg |
|---|---|---|---|---|---|---|
| `configuration_snapshots` + Sources/Fields/Rules | Counts + Digests | identisch | eingefrorene Feldstände der Testvorgänge | unverändert | Drift | Security-Report |
| `snapshot_field_definitions` / `snapshot_field_rules` | Counts + Digests | identisch | Snapshot-Feldinhalt | unverändert | Drift | Security-Report |

Snapshots werden nicht importiert; jede Änderung ist Schutzverletzung.

---

## F. Nummernkreise

| Prüfung | Vorher | Nachher | Inhaltsänderung trotz gleicher Anzahl | PASS | STOP | Beleg |
|---|---|---|---|---|---|---|
| `calculation_number_sequences` | Wert/Count | identisch | Sequenzwert geändert trotz Count=1 | unverändert | Drift | Digest + Rohwert Stichprobe |
| `dispo_order_number_sequences` | Wert/Count | identisch | Sequenzwert | unverändert | Drift | Digest + Rohwert |
| `standard_offer_number_sequences` | Wert/Count | identisch | Sequenzwert | unverändert | Drift | Digest |

---

## G. Preislisten-Pins

| Prüfung | Vorher | Nachher | Inhaltsänderung trotz gleicher Anzahl | PASS | STOP | Beleg |
|---|---|---|---|---|---|---|
| Pin-Bindings aller Staging-Calcs/Dispos | binding_count, list_count, Listen-IDs/Versionen/Status/Fingerabdrücke | identisch | Pin zeigt auf andere Listen-ID/Version bei gleichem Count | Pin-Guard PASS | Pin-Guard STOP / Diff | Report `price_list_pins`; explizite ID-Liste der Anker-Vorgänge |
| Konflikt Paket vs. gepinnte Listen | — | Paket darf gepinnte Listen inhaltlich nicht brechen | Fingerabdruck-Konflikt im Paket | keine Pin-Konflikte | Konfliktmeldung | Dry-Run/Apply conflicts |

---

## H. Aktive und archivierte RH-Preislisten

| Prüfung | Vorher | Nachher | Inhaltsänderung trotz gleicher Anzahl | PASS | STOP | Beleg |
|---|---|---|---|---|---|---|
| RH Spot 2026 r1 / 2027 r1 active | Status, Metadaten, Fingerabdruck, Item-Count | unverändert | Item-Preise geändert bei gleichem Listencount | Unchanged + Fingerabdruck gleich | conflict oder stiller Inhaltstausch | Report-Zeile + Fingerabdruck-Stichprobe |
| RH Produktion 2026 | unverändert | unverändert | unit_price/Flags geändert | Unchanged | conflict | Report + Stichprobe |
| Archivierte Quell-Revisionen (z. B. RH Spot 2027 r2) | auf Staging ggf. fehlend | Create nur als **draft**, nicht auto-active/archived | Status fälschlich active | draft Create ok | Status ohne Status-GO geändert | Report action + status Spalte |

---

## Inhaltsänderung trotz gleicher Anzahl – Methodenübersicht

| Methode | Was sie entdeckt | Was sie nicht ersetzt |
|---|---|---|
| Tabellen-Digest (Schutz-Snapshot) | Spalteninhalt/Referenzen über alle Zeilen | fachliche Interpretation einzelner Vorgänge |
| Pin-Capture/Diff | Preislistenbindung an Vorgängen | neue fachliche Pins nach Status-Phase |
| Fachanker-Stichprobe | Beträge, Nummern, sichtbarer Status | Vollabdeckung aller Zeilen |
| Fingerabdruck Preislisten | Item-/Metadaten-Drift | Storage-Binärdateien (Logos) |

---

## Abnahmezeitpunkte

1. Vor Dry-Run (Baseline)
2. Nach Dry-Run-Planung (schreibfrei; Digests müssen gleich bleiben)
3. Nach Apply
4. Vor Status-Phase erneut
5. Nach Status-Phase (nur Status der nicht gepinnten Drafts; Schutzbestände weiter unverändert)

Jedes STOP bricht den Lauf ab. Keine automatische Reparatur.
