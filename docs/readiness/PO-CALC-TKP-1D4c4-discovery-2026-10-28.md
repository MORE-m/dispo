# PO-CALC-TKP-1D4c-4 – Discovery: Admin-Preisverwaltung und Mindest-TKP

Stand: 28. Oktober 2026 (**Discovery / Architektur – keine Implementierung**)  
Base: `14de0f218a7842b46e658942bd0c384a989eb360` (`origin/main`, PR #195 gemergt)  
Worktree: `dispo-wt-po-calc-tkp-1d4c4` / Branch `docs/po-calc-tkp-1d4c4-admin-min-discovery`

**STOP:** Kein Code, kein PR, kein Import, kein Deploy, keine Registry-Freigabe, kein D4d.

---

## 0. Ausgangsbasis (geprüft)

| Signal | Ist |
|---|---|
| `origin/main` / `dispo-main` | `14de0f2…` = erwartet |
| Registry `oa_tkp`×`tkp` | `planned` |
| OA Preislisten / Items / Plattformen / Targetings (lokal) | 0 / 0 / 0 / 0 |
| Aktive Medium×Inventar-Regeln OA-Kern | **41** |
| Import-CLI | `oa:import-platforms\|targetings\|price-list`, `oa:activate-price-list` |
| OA-Admin-UI | **fehlt** |
| Spot-/Produktionspreis-Admin | vorhanden (Muster) |

---

## 1. Ist-Architektur (belegt)

### 1.1 Stammdaten

| Objekt | Schlüssel | Preisfelder |
|---|---|---|
| `oa_tkp_price_lists` | `year` + `revision_number`, Status draft/active/archived | Meta |
| `oa_tkp_price_list_items` | Liste × **Medium × Inventar** | `list_tkp`, `minimum_tkp` |
| `oa_targeting_definitions` | `code` + `revision_number`, Kategorie `technisch`\|`dmp` | **nur** `tkp_surcharge` (≥ 0) |
| `oa_platforms` | `code` | keine Preise (Mengenverteilung) |
| `oa_adserver_surcharges` | vorhanden | Runtime **OUT** erstes Release |

Migration: `database/migrations/2026_10_20_160000_po_calc_tkp_1a_oa_tkp_stammdaten.php`.

### 1.2 Auflösung und Engine

**`OaTkpPriceResolver`** liefert aus der **einen** aktiven Revision/Jahr das Item für Medium×Inventar: `list_tkp` + `minimum_tkp`. Keine Targeting-Dimension, keine Plattform-Preiswirkung.

**`OaTkpWriterPositionResolver`** pinnt bei gleichbleibendem Jahr/Medium/Inventar die Snapshots (`list_tkp_snapshot`, `minimum_tkp_snapshot`); sonst Rebind über Resolver + Expected-Token. Targetings werden aus Definitionen (oder Pin) mit **einem** `tkp_surcharge(_snapshot)` geladen.

**`OaTkpNormalEngine`** (kanonisch):

```text
final_tkp      = basis_tkp + Σ targeting.tkp_surcharge
below_minimum  = basis_tkp < minimum_tkp          // minimum = Preislisten-Item
media_gross    = impressions/1000 × final_tkp
```

Targeting-Aufschläge erhöhen **nur** den Final-TKP, **nicht** die Mindestschwelle.

### 1.3 Sonderfreigabe (unverändert zu schützen)

- OA-001/002: Prüfung **Basis vs. Mindest**, nicht Final (`SpecialApprovalAssessor::oaTkpBelowMinimumReasonsFromStoredPosition`).
- Kanonisch: Snapshot-Decimals `basis_tkp` / `minimum_tkp_snapshot`; Boolean `below_minimum_tkp` nur Marker.
- Speichern unter Min erlaubt; Dispo-Übergabe braucht Begründung + Special Approval.
- Tests: `PoCalcTkp1c1UnderMinApprovalTest` – Vertrag nicht aufweichen.

### 1.4 Import / Activate (wiederverwendbar)

- Draft-Import nur `status=draft`; Non-Draft fail-closed (`OaTkpPriceListDraftImportGuard`).
- Activate atomar + Jahres-`GET_LOCK` (`OaTkpPriceListActivationService` / `OaTkpPriceListYearLock`).
- Kein Hard-Delete historischer Revisionen; Rollback = neue Draft-Revision + Activate.
- Audit heute: strukturierte `Log::info`/`logImportOutcome`-Events; Spot-Admin zusätzlich `AuditLogger`.

### 1.5 Admin-Konventionen (Spot / Produktion)

- Gate: `can:access-administration`.
- Lifecycle: Draft pflegen → Prüfen/Vorschau → Activate (Vorgänger archivieren) → Archive; kein Hard Delete aktiver Historie (`PriceListAdminWriter` + `AuditLogger`).
- Inertia unter `administration/preislisten` bzw. `produktionspreise`.
- **Kein** OA-Äquivalent in Routes/UI.

---

## 2. Soll-Preisvertrag (fachlich, noch nicht importieren)

### 2.1 Medium-Grundpreise (Spezifikation)

| Medium-Code (Ist) | Listen-TKP | Mindest-TKP |
|---|---:|---:|
| `pre_stream` | 56,00 | 14,00 |
| `in_stream` | 44,00 | 8,00 |
| `pre_stream_influencer` | 56,00 | 14,00 |
| `in_stream_influencer` | 44,00 | 8,00 |
| `midroll_music_environment` | 80,00 | 20,00 |

**Netto-TKP = Mindest-TKP** (nicht Einkauf).

### 2.2 Targeting (Spezifikation)

| Targeting | Listen-Aufschlag | Mindest-Aufschlag |
|---|---:|---:|
| DMP | 25,00 | 5,00 |
| Geo | 15,00 | 3,00 |

### 2.3 Kombinierte Formel (gewünscht)

```text
effektive_Listen-TKP   = Listen-Basis  + Σ Listen-Targeting-Aufschläge
effektive_Mindest-TKP  = Mindest-Basis + Σ Mindest-Targeting-Aufschläge
final_tkp              = basis_tkp     + Σ Listen-Targeting-Aufschläge   // unverändert OA-Formel
under_min              = basis_tkp     <  effektive_Mindest-TKP          // OA-001 bleibt Basis-Vergleich
```

Beispiel Pre-Stream + DMP + Geo: Liste 96 €, Mindest 22 €.

### 2.4 Release-Umfang / Inventare

- 41 bestehende Medium×Inventar-Paare bleiben der **technische** Preislisten-Key.
- Gelieferte Werte sind **Grundpreise je Medium** – **keine** automatische Kopie auf alle Inventare ohne Fachabnahme.
- Midroll: ein Medium `midroll_music_environment` × `inv_more_kombi_online_audio`; Spotify/YouTube/Deezer = fachlicher Kontext / spätere **Plattform**-Stammdaten, **keine** neuen Inventare oder Auto-Plattformzeilen aus dieser Discovery.
- `native_ad` bleibt OUT.

---

## 3. Gap-Analyse: Ist vs. Soll

| Aspekt | Ist | Soll | Gap |
|---|---|---|---|
| Targeting-Aufschlag | 1 Feld `tkp_surcharge` | Listen- **und** Mindest-Aufschlag | **Modell fehlt** |
| Kategorie Geo | nur `technisch`\|`dmp` | „Geo“ als Produktname | **Fachentscheid** (map → `technisch` oder neue Kategorie) |
| Under-Min-Schwelle | nur Item-`minimum_tkp` | Item-Min + Σ Targeting-Mins | **Engine + Snapshot** |
| `minimum_tkp_snapshot` | = Preislisten-Min | = **effektive** Mindest-TKP der Position | Semantik erweitern (Pin/Assessor) |
| Listen-Referenz UI | Item-`list_tkp` | optional effektiv inkl. Targeting-Listen | UI/Metadata |
| Final-TKP | basis + Σ surcharge | basis + Σ **Listen**-Aufschläge | Mapping klarstellen (`tkp_surcharge` = Listen) |
| Admin OA | CLI only | Admin UI + Services | **fehlt** |
| Fachpreise in DB | 0 | nach Abnahme | D4c-3-Blocker bleibt bis Befüllung |

**Fazit:** Engine unterstützt die gewünschte Mindest-Unterscheidung **nicht**. Bestehende Freigabelogik (Basis vs. Mindest-Snapshot) kann erhalten bleiben, wenn der Snapshot die **effektive** Mindest-TKP speichert und Assessor unverändert gegen diesen Decimal prüft.

---

## 4. Notwendige Datenmodelländerungen (Empfehlung)

### 4.1 Targeting-Stammdaten

- Neues Feld z. B. `minimum_tkp_surcharge` `decimal(16,4)` ≥ 0, Constraint `minimum_tkp_surcharge ≤ tkp_surcharge` (Listen-Aufschlag).
- Bestehendes `tkp_surcharge` = **Listen-Aufschlag** (semantisch dokumentieren, kein Rename nötig wenn Migration+Doku klar).
- Importer `OaTargetingDefinitionImporter` + CSV/JSON-Vorlagen erweitern.
- Runtime-Lookup: höchste aktive Revision/Code unverändert.

### 4.2 Positions-Persistenz

- `calculation_position_oa_targeting_selections`: neues `minimum_tkp_surcharge_snapshot` (analog Listen-Snapshot).
- Dispo-JSON-Snapshot der Targetings mitziehen.
- `minimum_tkp_snapshot` auf Position: **effektive** Mindest-TKP (Basis-Min + Σ Min-Aufschläge der **gewählten** Targetings) – damit OA-001/002 und Assessor ohne Vertragsbruch weiterlaufen.
- Optional separat `price_list_minimum_tkp_snapshot` nur wenn Audit/Transparenz Basis-Min braucht (sonst aus Item-Pin rekonstruierbar) – **Fach/PO-Entscheidung**, Default: ein effektiver Mindest-Snapshot reicht.

### 4.3 Preislisten-Items

- Schema `list_tkp`/`minimum_tkp` je Medium×Inventar **bleibt**.
- Keine Schemaänderung für „Grundpreis je Medium“ nötig; Befüllungspolitik ist fachlich (gleicher Wert auf N Inventare **nur nach Abnahme**).

### 4.4 Plattformen

- Unverändert; Admin-CRUD analog Importvertrag; keine Preise.

---

## 5. Engine, Pricing, Sonderfreigabe

### 5.1 Engine-Anpassung (Slice 2)

1. Input-Targetings tragen Listen- und Mindest-Aufschlag (Snapshot-first).
2. `targetingSurchargeTotal` = Σ Listen-Aufschläge → `final_tkp`.
3. `effectiveMinimum = minimum_basis + Σ Mindest-Aufschläge`.
4. `belowMinimum = basis < effectiveMinimum`.
5. Validierung: je Targeting `0 ≤ min_surcharge ≤ list_surcharge`; effektives Min ≤ effektive Liste (bzw. konsistent zu Basis-Regeln).

### 5.2 Sonderfreigabe

- Assessor weiter: `basis_tkp` vs. `minimum_tkp_snapshot`.
- **Kein** Aufweichen: Speichern unter Min ok; Übergabe weiter blockiert.
- Reasons-Payload: effektive Mindest-TKP ausweisen; optional Breakdown in Label/Meta (nicht kanonisch für Gate).
- Bestehende gespeicherte Calcs: Snapshots unverändert; Reassessment nur aus Positions-Snapshots (wie heute gegen Live-Preisliste immun).

### 5.3 Writer / Metadata

- Writer berechnet effektives Min vor Engine-Aufruf oder Engine übernimmt Aggregation – eine Quelle der Wahrheit.
- `OaTkpPricingMetadataService`: UI mit Basis-List/Min + Targeting-Listen/Mins + Effektivwerte.

---

## 6. Admin-UX-Konzept

### 6.1 Navigation / Rechte

- Neuer Bereich z. B. `administration/online-audio` (oder Untermenü Preislisten → OA-TKP).
- Permission: zunächst `access-administration` (wie Spot); später ggf. feinere Capability – **offen**.
- Mutations nur Draft; Activate/Archive mit Vorschau + Confirm; Audit via `AuditLogger` (Spot-Muster) zusätzlich zu Import-Logs.

### 6.2 Screens (Vorschlag)

1. **Übersicht Preisjahre/Revisionen** – Status, Version, Activate/Archive-Aktionen.
2. **Draft-Editor Preisliste** – Matrix Medium×Inventar (41 Zeilen), Listen-/Mindest-TKP, Validierung (`min≤list`, >0, 4 NK).
3. **Targetings** – CRUD Revisionen: code/name/category, Listen- + Mindest-Aufschlag, Aktiv/Gültigkeit.
4. **Plattformen** – code/name/sort/flags; keine Demo-Codes.
5. **Aktivierungsvorschau** – Abdeckung freizugebender Paare, Vorgänger-Archiv, Dry-Run-Report.
6. **Historie** – archived read-only; kein Edit/Delete von active/archived Items.

### 6.3 Backend-Wiederverwendung

- Schreiben bevorzugt über bestehende Domain-Importer **oder** dünne Admin-Writer, die dieselben Validierungsregeln (`OaTkpImportDecimal`, Guards) und bei Activate denselben `OaTkpPriceListActivationService` nutzen.
- Keine zweite Activate-Logik; HTTP-Admin ruft denselben Service wie CLI.
- Year-Lock auch für Admin-Activate.

### 6.4 Revisions- und Aktivierungsschutz

- Eine `active` Revision / Jahr.
- Draft-Reimport/Edit nur Draft; active/archived fail-closed.
- Kein stilles Überschreiben; Pinning in Calcs unangetastet.
- Registry bleibt `planned` bis separates D4d nach Datenabnahme.

---

## 7. Rückwärtskompatibilität

| Bereich | Auswirkung |
|---|---|
| Vorhandene Kalkulationen | Snapshots unverändert; keine Reprice-Migration |
| Leere operative Stammdaten | Schema-Additiv ok; Default `minimum_tkp_surcharge=0` für Altzeilen/Tests |
| Targeting-Snapshots alt | fehlendes Min-Feld → behandeln als `0` beim Lesen historischer Rows |
| Dispo-Aufträge | JSON-Snapshots additiv erweitern; Reader tolerant |
| Sonderfreigabe | Vertrag bleibt; nur Semantik des Mindest-Snapshots „effektiv“ |
| OA-Tests | Engine-/1C1-/Import-Tests anpassen; Spot/SWF unberührt |
| Spot/SWF | keine Schema-/Engine-Kopplung |

---

## 8. Teststrategie

| Slice | Tests |
|---|---|
| Modell/Import | Migration; Importer list+min surcharge; Constraint min≤list; CLI Dry-Run |
| Engine | Einheit: Basis+DMP+Geo → final/list/min eff.; under_min Grenzfälle; ohne Targeting = Ist-Verhalten |
| Approval | 1C1-Regression; effektives Min im Snapshot; Dispo-Übergabe blockiert |
| Admin | Feature: Draft CRUD, Activate+Archive, Audit, Permission 403, Concurrency Year-Lock |
| UI | Inertia/Feature + gezielter Smoke; keine Registry-Freigabe |
| Regression | Spot PriceList Admin, Writer, D4b Planned-422 |

---

## 9. Offene Fachentscheidungen

1. **Inventar-Befüllung:** gleiche Medium-Grundpreise für alle 10 Stream-Inventare (bzw. 1 Midroll) – ja/teilweise/nein?
2. **Preisjahr zuerst:** 2027 (D4c-3-Empfehlung) vs. 2026?
3. **Geo-Kategorie:** unter `technisch` führen oder Enum erweitern?
4. **Codes** für DMP/Geo (`dmp`, `geo`, …) und Anzeigenamen final?
5. **Mindest-Snapshot:** nur effektiv, oder zusätzlich Basis-Min getrennt persistieren?
6. **Admin-Rechte:** nur `access-administration` oder eigene OA-Preis-Capability?
7. **Plattformkatalog** für Midroll-Kontext: wann und welche Codes (ohne Preiswirkung)?
8. **Teilmenge der 41** in Revision 1 vs. vollständige Abdeckung?

---

## 10. Priorisierter Slice-Plan

| # | Slice | Abhängigkeit | Risiko | Abnahme |
|---|---|---|---|---|
| **1** | Datenmodell + Targeting Listen-/Mindest-Aufschlag + Importvertrag + Vorlagen | — | Mittel (Schema) | Migration, Importer, Unit |
| **2** | Engine + Writer + effektives Min + Sonderfreigabe-Snapshots | 1 | **Hoch** (Kernlogik) | Engine-/1C1-Tests, Pin-Regression |
| **3** | Admin-Backend (HTTP) + Rechte + Audit, ruft Activate/Importer | 1–2 | Mittel | Feature/MySQL Lock |
| **4** | Admin-UI (Inertia) Preislisten/Targetings/Plattformen | 3 | UI | Smoke, a11y light |
| **5** | Fachliche Befüllung Draft (Jahr laut Entscheidung) + Abnahmematrix | 1–4, Fachdaten | Fach | Matrix 41, Dry-Run |
| **6** | Activate + Resolver-Stichprobe + **D4d Registry** (separates Go/No-Go) | 5 | Freigabe | Checklist D4c-1/2 |

Abhängigkeitskette: **1 → 2 → 3 → 4 → 5 → 6**. Admin-UI (4) darf nicht vor Vertrags-Engine (2) live Preise schreiben, die Under-Min falsch berechnen.

---

## 11. Empfehlung: erster Implementierungsslice

**Slice 1 – Datenmodell und Mindest-Targeting-Vertrag**

Kleinster sinnvoller Schritt:

1. Migration `minimum_tkp_surcharge` (+ Snapshot-Spalte an Selection).
2. Importer/Vorlagen/Validierung.
3. Doku des Soll-Vertrags (effektives Min) ohne Admin-UI und ohne Fachimport der Medium-Preise.
4. Tests: Import round-trip; Default 0 = bisheriges Verhalten bei fehlendem Min-Aufschlag.

**Nicht** in Slice 1: Engine-Umstellung der Under-Min-Aggregation (das ist Slice 2, bewusst getrennt wegen Kernlogik), Admin-UI, produktive Preise, Registry.

Begründung: ohne duale Targeting-Felder kann Slice 2 den Fachvertrag nicht korrekt spezifizieren; Admin ohne Vertrag riskiert falsche Freigaben.

---

## 12. Slice-Status (nach D4c-4a)

| Slice | Status |
|---|---|
| **1** Datenmodell + Importvertrag Mindest-Aufschlag | **umgesetzt** (Feature `feat/po-calc-tkp-1d4c4a-targeting-minimum-schema`, Dok `PO-CALC-TKP-1D4c4a-auftrag-2026-10-28.md`) – Engine/Under-Min/Snapshots noch OUT |
| 2 Engine + effektives Min + Freigabe | offen |
| 3–6 Admin / Befüllung / Registry | offen |

**Wichtig:** Nach 4a speichert der Import `minimum_tkp_surcharge`, aber Engine und Sonderfreigabe nutzen ihn **noch nicht**.

## 13. Statuszeile

> D4c-4 Discovery: Ist-Engine kennt nur einen Targeting-Aufschlag und prüft Under-Min gegen Preislisten-Min ohne Targeting-Mins. Soll braucht Listen- + Mindest-Aufschlag und effektive Mindest-TKP. Admin-OA fehlt; Spot-Admin + Import/Activate wiederverwendbar. Slice 1 (4a) = Stammdaten/Import; Engine folgt in 4b.
