# Readiness: BL-P2-02 / MAT-* – Kombinationstabelle → operative Kalkulation

> **Nachzug 02.10.2026:** Dieser Bericht bleibt als **historischer Befund** vom
> 29.09.2026 erhalten. Die Umsetzung ist seither auf `main` erfolgt:
> Kombinationstabellen-Admin/Freeze (**PR #108**), Matrix Desired-State
> (**PR #109**, ~201 aktive Regeln), Initialkatalog 14/42 (**PR #110**).
> Aktueller Status: [`docs/fortschritt.md`](../fortschritt.md). Weiter offen u. a.
> Hinweistexte, MAT-003-Vollabnahme, Nicht-Spot-Methoden; kein Deploy durch die
> Merges. **Keine REP-007-Readiness** in diesem Nachzug.

**Status (historisch):** Readiness **nicht** erfüllt für Produktionscode  
**Stand:** 2026-09-29  
**Basis-HEAD:** `origin/main` @ `363def9932e6473904cfe09a02fa4a8193804c68` (Merge PR #104)  
**Arbeitsmodus:** read-only Analyse; **kein** Feature-Code in diesem Ergebnis  
**AT-13:** parallel isoliert – siehe Abschnitt 0  

## Kurzfazit

Die Hypothese **`BL-P2-02 / MAT-*` als kleinster vertikaler nächster Slice** ist **fachlich richtig und bestätigt**. Sie bringt den größten operativen Fortschritt Richtung Kalkulation/Dispo.  
Umsetzung ist **jetzt blockiert** durch:

1. UX-GATE-D: Kombinationstabellen-Admin weiterhin **gesperrt** (`BLK-006`);
2. fehlende **vollständige Matrix-Lieferdaten** (`docs/initialdaten.md`);
3. offene **PO-/UX-Entscheidung** zur Sichtbarkeit des Buchungskennzeichens in der Kalkulation;
4. Schema-/Persistenzlücke: `booking_code`, `Einplanung durch`, Hinweistext fehlen an `inventory_medium_rules` und am Dispo-/Calc-Freeze.

**Kein Produktionscode** bis Gate + Daten + Sichtbarkeitsentscheidung vorliegen.

---

## 0. AT-13- und Repository-Stand (Phase 0)

| Quelle | Befund |
|--------|--------|
| `origin/main` | `363def9` – Merge PR #104 (`docs/status-after-pr103`); davor PR #105 (Laravel CVE), PR #103 (BL-P9-02c) |
| lokales `dispo-main` | Branch `main`, **5 Commits hinter** `origin/main` (Stand Check) |
| Feature-Workspace `dispo/` | `docs/status-after-pr86` (veraltet ggü. `main`) – **nicht** als Basis genutzt |
| AT-13 Worktree | `dispo-wt-at13-po-gate` → `feat/bl-p7-02a-at13-cc-invalidation` @ `ecdb532` |
| AT-13 Remote | `origin/feat/bl-p7-02a-at13-cc-invalidation` = gleicher Tip wie lokal; PR-Head `docs/at13-freigabeinvalidierung-po-gate` zeigt denselben Tip |
| PR #106 | **OPEN** (nicht mehr docs-only/closed): Feature PO-AT13-CC-1 + Doku; CI zum Checkzeitpunkt: `e2e-spt008` pass, `ci` fail, `mysql` pending |
| Entscheidung AT-13 | `docs/entscheidungen/AT-13-freigabeinvalidierung-kundenbestaetigung.md` im AT-13-Branch: Status **Akzeptiert** (PO-AT13-CC-1) – **noch nicht** auf `main` |

**Isolation:** AT-13 läuft in eigenem Worktree/Branch. Dieser Docs-Branch basiert ausschließlich auf `origin/main` und überschreibt keine AT-13-Dateien.

**Historischer Hinweis:** Der zuletzt sichtbare Stand „PR #106 closed, docs-only, Zielstatus offen“ ist **überholt**. Lokal und remote existiert inzwischen Feature-Code inkl. akzeptierter Zielstatus-Entscheidung `at_disposition → draft`.

---

## 1. Readiness-Bericht

### Legende

| Status | Bedeutung |
|--------|-----------|
| **umgesetzt** | auf `main`, belegt durch Code/Doku/Tests |
| **teilweise** | Fundament vorhanden, Fachvertrag unvollständig |
| **offen** | spezifiziert, nicht gebaut |
| **blockiert** | Gate, Daten oder PO-Entscheidung fehlt |
| **PO-Entscheidung erforderlich** | ohne Entscheidung kein belastbarer Slice |

---

### A. Kombinationstabelle und Buchungskennzeichen

| Thema | Status | Beleg |
|-------|--------|-------|
| Tabelle `inventory_medium_rules` | **teilweise** | Migration `database/migrations/2026_08_29_130000_create_calculation_domain_tables.php`: Unique `(inventory_id, advertising_medium_id)`, `is_active`, Länge, Aufschlag, Rabatt/AE; später `component_calculation_strategy` (BL-P4-02c) |
| Buchungskennzeichen / Einplanung / Hinweis | **offen** | Felder fehlen im Model `app/Models/InventoryMediumRule.php` und Schema; Datenmodell verlangt sie (`docs/datenmodell.md` § Kombinationstabelle) |
| Admin CRUD Kombinationen (MAT-001/002) | **offen / blockiert** | Kein List-/Create-/Edit-Admin für Kombinationen. Einzig `InventoryMediumRuleAdminController::update` ändert **nur** `component_calculation_strategy` (Inventar-Detail). Admin-Hub: „Kombinationstabellen bleiben gesperrt“ (`resources/js/pages/administration/index.tsx`) |
| Filter MAT-002 | **offen** | keine UI/API |
| Aktivierung/Deaktivierung | **teilweise** | Spalte `is_active` existiert; Runtime-Neuanlage prüft aktive Regel (`CatalogResolver`); Admin-Lifecycle fehlt |
| Historischer Freeze der Kombinationsattribute | **teilweise / offen** | Calc speichert `inventory_medium_rule_id`; **nicht** booking/planning/hint. Dispo-Snapshot (`DispoOrderSnapshotMapper`) friert Inventar + Werbemittel + Kategorie, **nicht** Buchungskennzeichen/Einplanung/Hinweis |
| Serverseitige Ableitung, nicht manuell überschreibbar | **offen** | kein Persistenzpfad; Anforderung klar in `docs/anforderungskatalog.md` 6.2 |
| Verwendung in Kalkulation | **teilweise** | Whitelist aktiv: nur aktive Regel erlaubt Position (`CatalogResolver`); ohne Buchungs-/Zuständigkeitsfelder |
| Verwendung im Dispoauftrag | **offen** | Positionen ohne booking/planning/hint-Spalten (`dispo_order_positions`) |
| Startdaten Matrix | **blockiert** | `docs/initialdaten.md`: „Vor Produktivsetzung noch zu liefern – vollständige Kombinationstabelle…“; „konkrete Zuordnung ausschließlich durch gelieferte Matrix“; **keine** Matrix-Datei im Repo |
| Sender vs. Kombi | **umgesetzt (PO)** | `PO-BL-P2-01-KOMBI`: Kombis = eigenständige Inventare **ohne** Sender-Mitgliedschaften; `BL-P2-01b` entfällt. Slice darf **keine** Memberships ergänzen |
| 14-Inventar-Seeder | **offen** | Inventarliste in `initialdaten.md` beschrieben; kein kanonischer Produktiv-Seeder der 14 Inventare (BL-P2-01a explizit ohne Auto-Import) |
| MAT-003 Single-Spot-Verbote | **offen** | nur dokumentiert; hängt an vollständiger Matrix |
| MAT-004 Massenimport | **bewusst V2** | nicht im Slice |

**Fazit A:** Hypothese bestätigt – der operative Engpass ist die **fehlende fachliche Kombinationstabelle** (Admin + Attribute + Freeze + Dispo), nicht weitere Spot-Classic-Rechenwege.

---

### B. Fachlicher Konflikt: Sichtbarkeit Buchungskennzeichen

| Perspektive | Inhalt |
|-------------|--------|
| **Kanonische Doku (verbindlich heute)** | `docs/anforderungskatalog.md` 6.2: Buchungskennzeichen „Automatisch, Pflicht im Dispoauftrag, gesperrt, nicht preisrelevant, **in der Kalkulation noch nicht sichtbar**“ |
| **Dispo-Pflicht** | Gleiches Kapitel + DSP-Positionstabelle: Kennzeichen und `Einplanung durch` **im Dispoauftrag** sichtbar/pflichtig |
| **PO-Wunsch (Nachtauftrag)** | Kennzeichen bei operativer Kalkulation „berücksichtigen“ – Auslegung **Anzeige in Calc** vs. nur serverseitige Ableitung/Freeze unklar |
| **UX-/Datenfolgen einer Calc-Anzeige** | Read-only Feld in Wizard/Zusammenfassung; Snapshot-Spalte oder Freeze aus Regel zum Positionszeitpunkt; Audit; kein Edit; Leerzustand wenn Altpositionen ohne Freeze; UX-GATE-D Teilfreigabe nötig, weil Calc-UI betroffen |
| **Empfehlung** | **A (Default):** Serverseitig ableiten + im **Dispo** anzeigen/einfrieren; in der **Kalkulation nicht anzeigen**, bis explizite UX-GATE-D-Teilfreigabe. So bleibt kanonische Doku gültig und operativer Nutzen entsteht sofort. **B:** Calc-Anzeige nur nach dokumentierter PO-Entscheidung + UX-GATE-D-Teilfreigabe (eigener Mini-Scope). |
| **Nicht erlaubt ohne Entscheidung** | Manuell änderbares Kennzeichen; stille Doku-Änderung durch Code |

→ **PO-Entscheidung erforderlich** – Vorlage: `docs/entscheidungen/PO-MAT-BOOKING-VIS-1-buchungskennzeichen-sichtbarkeit.md` (Status: Vorgeschlagen).

---

### C. Werbemittel- und Rechenmethoden-Matrix (Ist auf `main`)

#### C.1 Methodenstammdaten (`calculation_methods`, Migration ADV-001c1)

| Key | Name | Stammdatum | Engine-Profil `spot_classic` | Registry-Status | aktuelle Algo-Version | Wizard-Auswahl | echte Engine-Rechnung | Freeze/Hydrate |
|-----|------|------------|------------------------------|-----------------|----------------------|----------------|----------------------|----------------|
| `average` | Durchschnitt | geseedet | ja | **released** | `v1` | ja (Spot Classic) | **ja** (`CalculationEngine`) | ja |
| `calendar` | Kalenderplaner | geseedet | ja | **released** | `v1` | ja | **ja** (BL-P4-02b) | ja |
| `fixed_price` | Festpreis | geseedet | ja | **planned** (nicht released) | — | **nicht** als Methode | Festpreis läuft als `pricing_settlement_mode` auf average/calendar (BL-P4-02d) | ja (Settlement) |
| `tkp` | TKP | geseedet | **kein** Engine-Paar | — | — | nein | **nein** (Phase 6) | — |
| `free_position` | Freie Preisposition | geseedet | **kein** Engine-Paar | — | — | nein | **nein** (Phase 5/6) | — |

Registry: `app/Support/Calculation/EngineProfileRegistry.php`.  
`CalculationKind` enum enthält nur `spot_classic`.

#### C.2 Oberkategorien / typische Werbemittel-Lage

| Oberkategorie (Key) | Katalog-Admin | Methodenzuordnung | operative Calc auf `main` | Gate |
|---------------------|---------------|-------------------|---------------------------|------|
| `spots` | ADV-001b | average/calendar/fixed_price (Kategorie); Default average | Spot Classic Average/Calendar + Komponenten/Tandem/Tridem/Festpreis-Settlement/Standardangebote | UX-GATE-B + D-Teilfreigaben |
| `special_advertising_formats` (SWF) | Kategorie existiert | keine freigegebene Engine | **offen** (Phase 5; UX-GATE-C blockiert) | UX-GATE-C |
| `online_audio` | Kategorie existiert | TKP geplant, nicht implementiert | **offen** (Phase 6) | UX-GATE-C |
| `social_online` | Kategorie existiert | — | **offen** (Phase 6) | UX-GATE-C |
| `events_promotion` | Kategorie existiert | — | **offen** (Phase 6) | UX-GATE-C |
| `barter` | Kategorie existiert | — | **offen** | — |

Werbemittelkatalog laut `initialdaten.md` (42 Einträge) ist **beschrieben**, aber nicht als vollständiger Produktivkatalog geseedet (E2E nutzt typisch `spot_classic`).

#### C.3 Abgrenzung „Methode sichtbar“ ≠ „Rechnung fertig“

- ADV-001c3/c4: Methodenstammdaten, Assignments, Wizard-Optionen, Freeze-Keys **umgesetzt**.
- SWF / OA / Social / Events: **nicht** durch sichtbare Methodenauswahl „fertig“.
- Abbinder/SPT-013: **bewusst zurückgestellt** – nicht Teil des empfohlenen Slice.

---

### D. Gate, Daten, Tests

| Thema | Status | Beleg |
|-------|--------|-------|
| UX-GATE-C | **blockiert** für Nicht-Spot-Elemente | `docs/ux-ui-gate.md`: Trailer/SWF/Influencer/Social |
| UX-GATE-D Kombinationstabelle | **gesperrt** | `docs/ux-ui-gate.md`, `docs/blocker-und-entscheidungslog.md` BLK-006, Admin-Hub-Text |
| Teilfreigaben Katalog/Inventar/Preisliste | **erteilt** (andere Scopes) | ersetzen **nicht** die Kombi-Admin-Freigabe |
| Matrix-Lieferdaten | **fehlen** | `docs/initialdaten.md` |
| Tests Whitelist/Regel-ID | **teilweise** | u. a. `CalculationSnapshotBlockerTest` (inaktive Kombi), Spot-*/ADV-001c*-Suites; **keine** MAT-Admin-/Booking-/Planning-Tests |
| SQLite vs MySQL | bestehende Pattern | Feature + `*MysqlTest` für Spot/Export; neuer Slice analog, volle MySQL-Suite **nicht** als Erstschritt |
| E2E | Pattern vorhanden | dedizierte Playwright-Config/Port je Slice; gezielt, nicht All-Suite |

**Readiness-Ergebnis:** **nicht ready** für Feature-Implementierung.

---

## 2. Empfehlung: ein vertikaler Slice

### Slice-ID (Vorschlag): `BL-P2-02a` / Arbeitstitel `MAT-CORE-1`

**Ziel:** Kombinationstabelle als pflegbare Whitelist inkl. Buchungskennzeichen, `Einplanung durch`, Hinweis, Aktivstatus; serverseitige Ableitung in Calc→Freeze→Dispo; **ohne** alle Werbemittel-Rechenverfahren.

**Warum größter operativer Fortschritt jetzt:**

- Spot Classic rechnen bereits; der operative Bruch liegt zwischen Stammdaten-Whitelist und Dispo-Pflichtfeldern.
- Ohne Matrix bleiben MAT-003, Planungsverbote und korrekte Dispo-Kennzeichen unmöglich.
- Weitere Methoden (SWF/OA/Social) brauchen dieselbe Kombinationsbasis – zuerst Fundament, dann Rechenwege.

### Nicht-Ziele (explizit)

- UX-GATE-C / Phase 5–6 Rechenengines
- Abbinder/SPT-013
- MAT-004 Massenimport/Matrix-Tools
- Sender-Mitgliedschaften an Kombis
- Manuell editierbare Buchungskennzeichen
- Calc-Anzeige des Kennzeichens **ohne** PO-MAT-BOOKING-VIS-1 = Option B
- AT-13 / Notifications / CRM
- Erfinden von Matrixzeilen

### Abhängigkeiten vor Code

| # | Abhängigkeit | Wer |
|---|--------------|-----|
| 1 | UX-GATE-D Teilfreigabe Kombi-Admin (Scope `BL-P2-02a`) | PO |
| 2 | Lieferdatei/Matrix: Inventar × Werbemittel × Kennzeichen × Einplanung × Hinweis × aktiv | PO/Fach |
| 3 | PO-MAT-BOOKING-VIS-1 (Calc sichtbar ja/nein) | PO |
| 4 | AT-13 Merge optional parallel; Feature-Branch immer von aktuellem `origin/main`, nicht aus AT-13-Worktree | Dev |

### Betroffene Anforderungen

`MAT-001`–`MAT-003`, `ADV-001` (Restnur soweit Kombi), Dispo-Positionsfelder (DSP Aufbau), Freeze/`PRI-004`-Stabilität, `PO-BL-P2-01-KOMBI`.

### Datenmodell (Soll für Slice)

Erweiterung `inventory_medium_rules` (additive Migration):

- `booking_code` (string, Pflicht bei aktiv)
- `planning_responsibility` / kanonischer Key für „Einplanung durch“
- `hint_text` (nullable/text)
- `sort` (falls noch nicht vorhanden)
- Freeze auf Calc-Position **und** Dispo-Position: Werte zum Übernahme-/ Freeze-Zeitpunkt, **nicht** live nachziehen bei Admin-Änderung
- Unique Inventar×Medium bleibt; Soft-Deactivate statt Hard Delete

### API / Rechte / Audit

- Admin-only (`access-administration`)
- CRUD einzeln; Optimistic Locking analog Inventar/Katalog
- Audit create/update/activate/deactivate
- Calc/Dispo: Kennzeichen/Einplanung **nicht** im Request überschreibbar (Server setzt aus Regel bzw. Freeze)

### UI

- Admin: Liste + Filter (Inventar, Medium, Kategorie, Einplanung, Kennzeichen) + Detail/Edit + Aktiv-Toggle
- Leer-/Fehler-/Konfliktzustände analog Katalog
- Calc: Whitelist-Auswahl unverändert; Kennzeichen nur bei PO-Option B read-only
- Dispo: read-only Anzeige aus Snapshot

### Snapshot-/Versionsregeln

- Neue Positionen: aktuelle aktive Regelwerte einfrieren
- Bestehende Positionen/Aufträge: unverändert lesbar; fehlende Histofelder = Legacy-Leer, kein Backfill aus Live-Regel
- Deaktivierte Regel: keine Neuanlage; Historie lesbar

### Akzeptanzkriterien (Slice)

1. Admin legt/ändert/deaktiviert Kombination inkl. Kennzeichen/Einplanung/Hinweis.
2. Calc erlaubt nur aktive erlaubte Kombinationen.
3. Dispoauftrag zeigt eingefrorene Kennzeichen/Einplanung/Hinweis; nicht editierbar.
4. Admin-Änderung mutiert keine historischen Snapshots.
5. Planungsverbot „darf nicht geplant werden“ blockiert Übergabe/Auswahl fail-closed (laut Matrix).
6. Keine Membership-Logik für Kombis.
7. Keine erfundenen Matrixdaten in Seeder ohne PO-Lieferung (Testfactories ok).

### Tests (gezielt)

- Pest Feature: CRUD, Filter, Locking, Audit, fail-closed inaktiv/fehlend
- Freeze/Hydrate: Admin ändert Regel → alte Calc/Dispo unverändert
- Dispo-Adoption: Kennzeichen aus Freeze
- SQLite Feature zuerst; MySQL-Concurrency nur wenn Locking-Pfad kritisch
- Vitest Admin-UI; Playwright schmaler Smoke (eigener Port/Config)
- **Nicht** sofort volle MySQL-Suite

### Risiken / Rollback

- Additive Schemaänderung → Rollback = Migration down + Feature-Flag/Route nicht deployen
- Legacy-Positionen ohne Freeze-Felder → UI muss Leerzustand tragen
- Ohne Matrix-Lieferung nur Admin-Gerüst möglich → **kein** „vollständiger“ MAT-Slice vortäuschen

### Folgeslices (Reihenfolge)

1. **BL-P2-02a** – MAT-CORE (dieser Slice) nach Freigaben  
2. **BL-P2-02b** – Seed/Import der gelieferten Matrix (kein MAT-004-Massenwerkzeug; kontrollierter Seed) + MAT-003-Verifikation  
3. Optional **PO-MAT-BOOKING-VIS-1 B** – Calc-Anzeige  
4. ADV-001 Rest-Defaults (Feldsets/Rabatt) soweit nötig  
5. UX-GATE-C / Phase 5 – SWF/Produktion (`tkp`/`free_position` später Phase 6)  
6. Phase 6 – Online Audio / Social / Events  
7. SPT-013 Abbinder nur bei expliziter Neupriorisierung  

---

## 3. Entscheidung: Produktionscode in dieser Nacht?

| Kriterium | Erfüllt? |
|-----------|----------|
| AT-13 isoliert | **ja** (eigener Worktree) |
| UX-GATE-D Kombi-Admin | **nein** |
| Matrix-Daten | **nein** |
| PO Sichtbarkeit Kennzeichen | **offen** (Empfehlung A dokumentiert) |
| Fachliche Annahmen als Code | **verboten** |

→ **Docs-only.** Kein Feature-Branch mit App-Code, kein Merge, kein Deploy.

---

## 4. Verifikation dieses Dokuments

- Analyse-Worktree: detached `origin/main` @ `363def9`  
- Docs-Branch: `docs/bl-p2-02-mat-kombination-readiness`  
- AT-13-Worktree unberührt  
