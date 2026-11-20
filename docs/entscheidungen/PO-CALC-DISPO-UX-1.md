# PO-CALC-DISPO-UX-1 – Calc→Dispo UX: operative Felder und Wizard-Werbeelemente

Status: **Akzeptiert**  
Datum der PO-Entscheidung: **17.11.2026**  
Readiness-Basis: `origin/main` @ `8a7c72de34ef1cad9e2db59dff2406a1281033c8`  
Kennung: `PO-CALC-DISPO-UX-1`  
Bezug: DSP-DCP-001, DSP-002/003, CRM-001/002, PRI-003, AT-13 / PO-AT13-CC-1,
PO-CALC-DISPO-LIFECYCLE-1

## Kurzfazit

Acht verbindliche PO-Entscheidungen für die Verschiebung operativer Angaben in den
Dispoauftrag sowie für Wizard-/Dispo-UX. Bestehende Calc→Dispo-Snapshot-, CRM-/Meridian-
und DSP-DCP-001-Verträge werden **nicht** rückwirkend umgedeutet.

Umsetzung in fünf PRs; **dieser Slice (PR1)** betrifft ausschließlich die
Wizard-Werbeelement-UX.

---

## PO-UX-01 – Operativer Kampagnenzeitraum

**Entscheidung: Variante B**

- Neues editierbares operatives Dispo-Kopffeld für den Kampagnenzeitraum (Umsetzung in **PR5**).
- Neue Kalkulationen ohne allgemeine Zeitraum-Eingabe.
- Calc-Origin-`campaign_period` bleibt für historische Daten erhalten.
- `derived_campaign_period_*` bleibt unverändert; DSP-DCP-001 unverändert.
- Keine rückwirkende Snapshot-Änderung; OA-/Flight-Zeiträume bleiben eigenständig.
- Operative Eingabe darf Flight-Daten nicht still überschreiben.
- Eindeutige UI-Bezeichnung, nicht verwechselbar mit dem abgeleiteten Zeitraum.

## PO-UX-02 – Bestandsschutz und Übergabe

**Entscheidung: Vorhandene Werte übernehmen, neue Kalkulationen leer.**

- Bestehende Calc-Werte (Agentur, RE, Briefing, `campaign_period`) bleiben erhalten.
- Neuer Dispo aus solcher Calc übernimmt diese Werte weiterhin (Freeze kompatibel).
- Neue Calcs bieten die Eingaben nicht mehr an; neue Dispos starten ohne stille RE-Vorbelegung.
- Keine historische Datenbereinigung; bestehende Dispos unverändert.
- Neue operative Änderungen nur am jeweiligen Dispo.
- Revisionen übernehmen operative Kopfdaten des **Vorgänger-Dispos**, nicht veraltete Calc-Werte.

## PO-UX-03 – Änderungen nach Freigabe

**Entscheidung: Nach Freigabe sperren, Korrektur über Revision.**

- Agentur, RE, Briefing und operativer Kampagnenzeitraum nach Freigabe nicht direkt änderbar.
- Bearbeitung nur in zulässigen Status vor Freigabe; serverseitige Autorisierung.
- Korrektur über bestehenden Revisionsprozess mit korrekter Dispo-Wert-Übernahme.
- Keine neue allgemeine Freigabeinvalidierung in diesem Paket; AT-13 unverändert.
- Statusmatrix vor PR3 anhand der Workflow-Verträge festlegen.

## PO-UX-04 – Pflichtfelder

**Entscheidung: Rechnungsempfänger spätestens bei Einreichung, weitere Felder bedingt.**

- Agentur optional.
- Rechnungsempfänger vor „Zur Freigabe einreichen“ erforderlich (Kunde oder zugeordnete Agentur).
- Ohne Agentur darf RE nicht „Agentur“ sein.
- Briefing-Text grundsätzlich optional (bestehende Sonderregeln bleiben).
- Operativer Kampagnenzeitraum nur werbeform-/regelbedingt Pflicht; keine pauschale Zusatzpflicht.
- Entwurf vor vollständiger Eingabe speicherbar; Einreichungsfehler am Feld.

## PO-UX-05 – Budget-Werbeelemente

**Entscheidung: Inventar bei neuen Budget-Werbeelementen leer lassen.**

Manueller Pfad:

- Werbeelement 1: keine automatische Inventarauswahl.
- Weitere Elemente: Inventar des unmittelbar vorherigen Elements als Default, jederzeit änderbar.

Budget-Assistent:

- Neue Elemente ohne Inventar; keine Übernahme; Uniqueness unverändert; kein „nächstes freies Inventar“.

## PO-UX-06 – Kunde im Dispoauftrag

**Entscheidung: Kunde im Dispoauftrag nur anzeigen.**

- Auswahl nur in der Kalkulation; Dispo übernimmt beim Create; Dispo read-only.
- Keine Kundenwechsel-Funktion am Dispo; CRM-/Snapshot-Verträge bleiben.

## PO-UX-07 – Standardangebote

**Entscheidung: Neue Übernahmen ohne Agentur, Pflege erst im Dispo.**

- Adoption neuer Calc ohne Agentur-/RE-Pflege; Historie bleibt.
- Vertragsanpassung in **PR4**; **keine** Änderung in PR1.

## PO-UX-08 – Position der Freigabeaktion

**Entscheidung: „Zur Freigabe einreichen“ ganz unten.**

Reihenfolge am Seitenende: Kundenbestätigung → Material hochladen → Dateien → Zur Freigabe einreichen.  
Umsetzung in **PR2**. Genehmigen/Ablehnen nicht ohne weitere Prüfung ändern.

---

## Scope und Nicht-Scope

### Scope dieses Entscheidungspakets

- Operative Kopfdaten stärker am Dispo; Calc auf Kalkulation reduzieren.
- Wizard-Werbeelement-UX (PR1).
- Dispo-Layout und Kommunikations-Sidebar (PR2).
- Dispo-Edit Agentur/RE/Briefing (PR3).
- Calc-Bereinigung inkl. Create/Revision/Adoption (PR4).
- Operativer Kampagnenzeitraum Variante B (PR5).

### Nicht-Scope / unverändert

- DSP-DCP-001 / `derived_campaign_period_*`
- AT-13 Invalidierungslogik
- Preisformeln, Meridian-/SF-Nachtragsregeln (fachlich), Materialupload-Fachlogik
- Keine Mutation von `dispo_mat_core` in diesem PO-Paket

---

## PR-Aufteilung

| PR | Inhalt |
|---|---|
| **PR1** (dieser Slice) | Kein Inventar-Default; Expand aller Inventare; Vorgänger-Inventar bei manuellem Add; Budget ohne Inventar-Copy; Entfernen im Header |
| **PR2** | Dispo-Layout, sticky Kommunikation, Endreihenfolge inkl. Freigabe-Aktion |
| **PR3** | Agentur/RE/Briefing am Dispo editierbar; RE-Pflicht vor Submit; Schreibschutz nach Freigabe |
| **PR4** | Calc-UI bereinigen; Create/Revision/Adoption kompatibel |
| **PR5** | Operatives Dispo-Zeitraumfeld (Variante B) |

**Abhängigkeit PR4 ↔ PR5:** Vor Umsetzung erneut prüfen, damit kein Zwischenzustand entsteht, in dem ein für die Einreichung nötiger Zeitraum weder erfasst noch angezeigt werden kann.

---

## Bestandsschutz (verbindlich)

- Keine rückwirkende Änderung eingefrorener Dispo-Snapshots.
- Keine automatische Löschung bestehender Calc-Werte.
- Bestehende Dispoaufträge bleiben unverändert.
- Calc→Dispo-Freeze für vorhandene Daten kompatibel.

---

## Technisch noch zu klären (nicht PR1)

- Konkrete Field-Definition / Seed für operatives Dispo-Zeitraumfeld (PR5).
- Statusmatrix für Editierbarkeit vor Freigabe (PR3).
- Revisions-Erbe operativer Dispo-Kopfdaten im Detail (PR3/PR4).
- UI-Label des operativen Zeitraums vs. Derived vs. OA-Flight (PR5).

---

## Umsetzungsstand PR1

Umgesetzt und gemergt (`366c535`, PR #253) gemäß PO-UX-05 (manuell + Budget) und den UX-Punkten Entfernen im Header.

## Umsetzungsstand PR2

Gemergt (PR #254, `bc59dcd`):

- Zweispaltiges Desktop-Layout ab `xl` mit sticky Kommunikations-Sidebar.
- Mobile: Kommunikation im normalen Seitenfluss vor dem Endblock.
- Endreihenfolge: Kundenbestätigung → Material → Dateien → „Zur Freigabe einreichen“.
- Genehmigen/Ablehnen bleiben oben; rein darstellungsbezogene `mode`-Prop an `DispoOrderApprovalActions`.

## Verbindliche PR3-Ergänzungen (PO-PR3-01…03, 19.11.2026)

- **PO-PR3-01:** Dispositionsrechte unverändert; keine neuen Rollen/Policies.
- **PO-PR3-02:** Vorläufige Agenturen unter denselben Wizard-/CRM-Regeln erlaubt.
- **PO-PR3-03:** Direkte Bearbeitung nur in `draft`; `approval_rejected` nur über Revision.
- `invoice_recipient` bleibt nullable; Pflicht erst bei Einreichung.
- Agentur entfernen bei RE=`agency` → RE atomar `null` (kein stilles `customer`).
- Revision übernimmt operative Kopfdaten (Agentur/RE/Briefing) vom Vorgänger-Dispo.
- Keine neue Approval-Invalidierung.

## Umsetzungsstand PR3

Gemergt (PR #256, `86cafd6`):

- Eigenständiger Header-Update-Pfad (`dispo-orders.update-header`).
- CRM-Binder mit Dispo-RE-Null-Semantik; Kunde read-only.
- Submit-Gate für Rechnungsempfänger.
- Revision erbt operative Kopfdaten vom Vorgänger.
- Frontend: `DispoOrderHeaderEditor` in der Kopfdaten-Card; PR2-Layout unverändert.

## Verbindliche PR4-Ergänzungen (PO-PR4-01…03)

- **PO-PR4-01:** Kampagnenzeitraum bleibt bis PR5 im Kalkulations-Wizard
  (Übergangsvariante A). `campaign_period`, Calc-Origin-Capture,
  `derived_campaign_period_*` und OA-Flight unverändert.
- **PO-PR4-02:** Neue Kalkulationen und Standardangebot-Adoptions speichern
  `invoice_recipient = null` (kein stilles `customer`-Default). RE wird erst
  im Dispoauftrag festgelegt; Draft ohne RE erlaubt, Einreichung verlangt RE.
- **PO-PR4-03:** Agentur, Rechnungsempfänger und Briefing sind im Wizard weder
  editierbar noch read-only sichtbar (neu und historisch). Gespeicherte Werte
  bleiben erhalten (Ausblenden ≠ Löschen).

### Preserve-on-absent (verbindlich)

- Fehlender Payload-Key für Briefing/Agentur/RE überschreibt historische Werte nicht.
- Explizites Senden (inkl. `null`, wo der Vertrag es erlaubt) bleibt unterscheidbar.
- Wiederholtes Speichern ohne operative Keys führt nicht zu Datenverlust.

### Calc→Dispo und Adoption

- Neue Calc→Dispo: Agentur leer, RE=`null`, Briefing leer; Kunde übernommen.
- Historische Calc→Dispo: vorhandene operative Werte weiterhin übernommen.
- Standardangebot-Adoption: keine operative Agenturvorbelegung, RE=`null`,
  Quell-Standardangebot unverändert.
- Revisionen: unverändert vom Vorgänger-Dispo (PR3).

## Umsetzungsstand PR4

Gemergt (PR #257, `eaa21ad`):

- Wizard ohne Agentur/RE/Briefing; Kampagnenzeitraum blieb bis PR5 (PO-PR4-01).
- `CalculationWriter` mit `allowUnsetInvoiceRecipient` und Briefing
  preserve-on-absent.
- Standardangebot-Adoption ohne Agentur-/RE-Vorbelegung.

## Verbindliche PR5-Ergänzungen (PO-PR5-01…07)

- **PO-PR5-01:** Kein neues globales Submit-Gate für den operativen Zeitraum.
  Draft und Einreichung ohne Zeitraum bleiben zulässig; bestehende
  werbeform-/regelbedingte Pflichten (inkl. OA-Flight, Capture-Gaps, RE) unverändert.
- **PO-PR5-02:** Einmaliges Prefill beim erstmaligen Calc→Dispo-Create nur für
  historische Kalkulationen mit vollständigem gültigem Calc-Origin-Zeitraum.
  Kriterium: `origin_standard_offer_version_id IS NULL` **und**
  `created_at < OperationalCampaignPeriodContract::LEGACY_CALC_CREATED_BEFORE`
  **und** vollständiges gültiges `campaign_period`. Adoption-Calcs und neue Calcs
  nach Cutover erhalten kein Prefill. Keine spätere Synchronisierung.
- **PO-PR5-03:** Kein Backfill bestehender Dispoaufträge; neue Spalten nullable.
- **PO-PR5-04:** Paar `operational_campaign_starts_on` / `operational_campaign_ends_on`,
  Format `Y-m-d`; beide null oder beide gesetzt; Start ≤ Ende; gleiche Tage ok;
  keine offenen Zeiträume; keine TZ-Verschiebung.
- **PO-PR5-05:** Rein informativ; steuert keine Freigabe, OA-Disposition, Abschluss,
  Abrechnung, Derived oder Flights.
- **PO-PR5-06:** Integration in den PR3-Header-Editor; Draft-only; bestehende Policy
  und `lock_version`; keine neuen Rollen.
- **PO-PR5-07:** Revision erbt den operativen Zeitraum vom Vorgänger-Dispo
  (inkl. `null/null`); kein erneutes Calc-Prefill.

### Datenmodell PR5

- Native Spalten an `dispo_orders`: `operational_campaign_starts_on`,
  `operational_campaign_ends_on` (date, nullable, nicht fillable).
- Calc-Origin `campaign_period`, `derived_campaign_period_*` und
  OA-`operational_flight_*` bleiben eigenständig.
- Capture-Zeile `campaign_period` weiterhin bei Create (auch null/null);
  Gaps nur bei fehlender Zeile.

### Wizard PR5

- Allgemeine `campaign_period`-Eingabe entfernt (neu und historisch).
- Key wird nicht gesendet (Preserve-on-absent); kein `campaign_period: null`.

## Umsetzungsstand PR5

Implementiert in Branch `feat/po-calc-dispo-ux-1-pr5-operational-period` (Draft):

- Migration + Modell + Header-Update + Create-Prefill + Revisionserbe.
- Dispo-Header-Editor mit operativem Zeitraum; OA-Label präzisiert
  („Positions-Flight OA-TKP“).
- Wizard ohne allgemeines Zeitraumfeld.
