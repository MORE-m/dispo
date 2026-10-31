# PO-CALC-TKP-1D4c-4j – Werbeformabhängige OA-Plattformauswahl (Discovery)

Stand: 30. Oktober 2026 – **Readiness / Discovery**  
Nachzug 31.10.: **D4c-4j-1** implementiert Schema + Helper (ohne Enforce) –
siehe `PO-CALC-TKP-1D4c4j1-auftrag-2026-10-31.md`.  
Git (Discovery-Basis): `391eaf440a0ae4b0c7ae2fcc088b890d629cd067` (`origin/main`)  
DB: `dispo_mat_core` – Preisliste 2027.1 Draft (41), Targetings dmp/geo, Plattformen **0**, Registry **`planned`**

**FD-D4c-4j (verbindlich):** Plattformen sind werbeformabhängig. Auswahl und Persistenz nur bei aktiver, fachlich gültiger Zuordnung Platform × OA-Kernmedium.

**STOP (Discovery):** keine Plattformanlage, keine Aktivierung, kein Deploy, kein Registry-Flip.

Bezug: D4c-4i Plattform-Readiness, D4c-4c/4d Admin-Vertrag, D4c-4e Prep, Wizard/Engine/Writer Ist.

---

## 1. Git-/DB-Ausgangsbasis

| Prüfpunkt | Ergebnis |
|---|---|
| Main | `391eaf4`, clean |
| Preisliste | ID 1, 2027 Rev1, `draft`, 41 Items |
| Targetings | `dmp`/`geo` Rev1 |
| `oa_platforms` | **0** |
| Registry `oa_tkp`×`tkp` | `planned` |

---

## 2. Tatsächliche Ist-Architektur

### Datenfluss (heute)

```
oa_platforms (Stammdaten, medium-agnostisch)
    ↓ AdminWriter/Importer (Identity=code)
CalculationController catalog.oa_platforms = OaPlatform::active()  // KEIN Medium-Filter
    ↓
Wizard Position-Editor (Dropdown filtert nur bereits gewählte IDs)
    ↓ Payload oa_platform_allocations[]
OaTkpWriterPositionResolver::resolvePlatformAllocations
    → existiert? aktiv? (Pin für bereits gespeicherte Inaktive) Duplikat?
OaTkpNormalEngine::assertPlatformAllocations
    → ≥1, Impressions≥1, Summe=Positions-Impressions, Code-Duplikate
OaTkpCalculationPersister → calculation_position_oa_platform_allocations
    (oa_platform_id, platform_code, platform_name, impressions, sort)
DispoOrderSnapshotMapper → oa_platform_allocations_snapshot (gepinnt)
```

### Zentrale Dateien

| Schicht | Datei |
|---|---|
| Schema | `database/migrations/2026_10_20_160000_po_calc_tkp_1a_oa_tkp_stammdaten.php` |
| Model | `app/Models/OaPlatform.php` |
| Import | `app/Services/OnlineAudio/Import/OaPlatformImporter.php` |
| Admin | `OaPlatformAdminWriter`, `OaPlatformAdminController`, UI `platforms/index.tsx` |
| Catalog | `CalculationController` (~666–676) |
| Wizard | `oa-tkp-position-editor.tsx`, `oa-tkp-wizard.ts` |
| Resolver | `OaTkpWriterPositionResolver::resolvePlatformAllocations` |
| Engine | `OaTkpNormalEngine::assertPlatformAllocations` |
| Persistenz | `CalculationPositionOaPlatformAllocation`, `OaTkpCalculationPersister` |
| Dispo | `DispoOrderSnapshotMapper` (Platform-Pin) |
| Kernmedien | `OaTkpCoreMedia` (5 Codes; `native_ad` OUT) |
| Precedent | `inventory_medium_rules` (inventory×medium, `is_active`, Unique, Soft-Deactivate) |
| Release | `EngineProfileRegistry` + `OaTkpHttpReleaseGuard` / `OaTkpWizardCapability` (plattform-agnostisch) |
| Test-Fake | `OaTkpWizardCapability::fakeHttpMutationAllowedForTests` – nur HTTP-Mutation in Unit-Tests |

### Kritische Ist-Lücken für FD-D4c-4j

1. Keine Platform×Medium-Tabelle.  
2. Catalog und UI ohne Medium-Filter.  
3. Resolver/Engine ohne Zuordnungsprüfung.  
4. Mediumwechsel bei bestehender TKP-Position leert OA-Draft **nicht** (`oaTkpDraftPatchAfterResolvedMethod` gibt `{}` wenn schon TKP) → stille Übernahme unzulässiger Plattformen wäre heute möglich, sobald Plattformen existieren.

---

## 3. Empfohlenes Datenmodell

### Tabelle `oa_platform_medium_rules` (Arbeitstitel)

Analog `inventory_medium_rules`, **keine** zweite Plattform-Stammdatenstruktur.

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | PK | |
| `oa_platform_id` | FK → `oa_platforms` | vorhandene Stammdaten-ID |
| `advertising_medium_id` | FK → `advertising_media` | nur OA-TKP-Kernmedien |
| `is_active` | bool, default true | Soft-Deactivate |
| `timestamps` | | |
| Unique | `(oa_platform_id, advertising_medium_id)` | keine Duplikate |

### Regeln

- Zulässig nur Medien in `OaTkpCoreMedia` (`pre_stream`, `in_stream`, `pre_stream_influencer`, `in_stream_influencer`, `midroll_music_environment`).  
- **`native_ad` verboten** (Validierung serverseitig).  
- Kein Hard-Delete der Rule-Zeile empfohlen: `is_active=false` (wie Plattformen/IMR). Optional physisches Löschen nur wenn nie referenziert – lieber Soft-Deactivate.  
- **Keine eigene Revisionsnummer** für Zuordnungen nötig: Historie der Kalkulationen hängt an Allocation-Snapshots (`platform_code`/`name`); Audit auf Rule create/update/deactivate reicht.  
- Plattform selbst bleibt Identity=`code`; Zuordnung ist Zusatzrelation.

### Lookup-Hilfen (später)

- `OaPlatformMediumRules::activePlatformIdsForMedium(int $mediumId): list<int>`  
- Fail-closed: keine aktive Rule für Medium → leerer Katalog / Save 422.

---

## 4. Zentraler serverseitiger Validierungspunkt

**Primär:** `OaTkpWriterPositionResolver::resolvePlatformAllocations`  
– unmittelbar nach Plattform-Existenz/Aktiv-Check, mit dem **bereits resolvten** `advertising_medium_id` der Position.

Zusätzlich (Defense in Depth, optional derselbe Helper):

- Preview-Pfad über denselben Resolver (bereits der Fall bei Writer.preview).  
- Engine kann optional asserten, ist aber nicht die autoritative Stammdatenquelle.

### Prüfreihenfolge (Soll)

1. Allocation-Array nicht leer  
2. Platform existiert (`id`/`code`)  
3. Platform aktiv **oder** Pin (bereits auf Position gespeichert) – bestehendes Verhalten  
4. **NEU:** aktive `oa_platform_medium_rule` für `(platform_id, medium_id)` **oder** Pin-Ausnahme (siehe Historie)  
5. Keine doppelte Platform-ID  
6. Engine: Impressions ≥1, Summe = Positions-Impressions  

Client-Filter **nie** autoritativ.

### Pin-/Historienvertrag (empfohlen, PO-Bestätigung)

| Fall | Verhalten |
|---|---|
| Neue Allocation | Platform aktiv **und** Rule aktiv für Medium |
| Re-Save unveränderte Platform auf Position | Pin erlaubt, auch wenn Platform oder Rule inzwischen inaktiv (analog heutiger Inaktiv-Pin) |
| Medium-Rebind derselben Position | **kein** stilles Beibehalten; unzulässige Allocations müssen entfernt/ersetzt werden, sonst 422 |
| Dispo-/Calc-Snapshots | unverändert; keine Rückrechnung |

### Test-Fake

`fakeHttpMutationAllowedForTests` bleibt Release-Gate-only. Neue Zuordnungstests brauchen Fixtures (Platform + Rules) in RefreshDatabase – Fake ändert die Rule-Logik nicht.

---

## 5. Admin-UX-Konzept

### Rechte

Unverändert: Gate `manage-oa-tkp-master-data` (Admin-only), wie Plattform-/Preislisten-Admin.

### UI-Vorschlag

Erweiterung der Plattformverwaltung (nicht neue Top-Level-App):

1. Bestehende Plattformliste (code/name/aktiv/adserver).  
2. **Zuordnungsmatrix** (Plattform-Zeilen × 5 Kernmedien-Spalten, Checkbox = aktive Rule):

| Plattform | Pre-Stream | In-Stream | Pre-Str. Infl. | In-Str. Infl. | Midroll |
|---|---|---|---|---|---|
| Spotify | offen | offen | offen | offen | vorgesehen* |
| YouTube | offen | offen | offen | offen | vorgesehen* |
| Deezer | offen | offen | offen | offen | vorgesehen* |

\*Konzept / FD Midroll – **keine Seed-Freigabe** in diesem Discovery.

3. Speichern: Batch-Upsert Rules (aktivieren/deaktivieren), YearLock nicht nötig (kein Preisjahr); Optimistic Lock optional über `lock_version` an Plattform oder Matrix-ETag.  
4. Audit: `oa_platform_medium_rule.created|updated|deactivated` (oder gebündeltes Matrix-Audit).  
5. Validierung: nur Kernmedien; Ablehnung `native_ad`; Plattform muss existieren.

**Keine** Plattformstammdaten in diesem Slice anlegen – Matrix operiert auf vorhandenen `oa_platforms` (derzeit 0 → leere Matrix bis Fachbefüllung).

---

## 6. Wizard-Verhalten (Soll)

| Situation | Soll |
|---|---|
| Position öffnen / Medium wählen | Catalog nur Platforms mit aktiver Rule für dieses Medium |
| Keine Rule für Medium | Hinweis „Keine Plattformen für diese Werbeform hinterlegt“; Preview/Save nicht ready |
| Mediumwechsel (TKP→TKP) | Unzulässige Allocations **entfernen** oder Draft-Platforms zurücksetzen – **kein** stilles Mitnehmen (UI-Patch nötig; heute `{}`) |
| Allocation-Summe | unverändert = Positions-Impressions |
| Inaktive Platform neu wählen | weiterhin unzulässig |
| Gespeicherte Position mit inzwischen ungültiger Rule | Anzeige historischer Pin-Zeile; Update ohne Änderung ok; neue Platforms nur aus gültigem Katalog |
| Preview | dieselben Filter + Server-Validierung |

Catalog-Payload-Optionen (Implementierungswahl Slice 5):

- A) flache Liste + `allowed_medium_ids[]` je Platform  
- B) `oa_platforms_by_medium_id: Record<number, Platform[]>`  

Empfehlung: **A** (eine Liste, Client filtert; Server validiert).

---

## 7. Snapshot-/Historienvertrag

- Calc-Allocations und Dispo-Snapshots behalten `platform_code`/`platform_name`/`oa_platform_id`.  
- Rule-Änderungen ändern **keine** bestehenden Snapshots.  
- Preislisten/Targetings/Registry unberührt.  
- Spot/SWF ohne OA-Plattformpfad – keine Auswirkung.  
- `FD-OA-PLAT` (Plattform im Preis-Key) bleibt **separat offen** – diese Discovery betrifft nur Zulässigkeit der Mengenverteilung.

---

## 8. Konkrete PO-Entscheidungen (Bedarf)

### A. Fachliche Plattformliste (weiterhin offen, außer Midroll-Namen)

| Thema | Status |
|---|---|
| Midroll: Spotify, YouTube, Deezer | fachlich bestätigt als Namen |
| Exakte `code` / Anzeigenamen / `sort` | **offen** |
| `is_adserver_relevant` (OA-004) | **offen** (Runtime-Aufschlag weiter OUT bis eigener Slice) |
| Pre-/In-Stream Plattformen | **offen** (D4c-4i Optionen B1–B3) |
| Influencer = dieselben wie Stream? | **offen** |
| Überschneidungen (z. B. Spotify auch Pre-Stream?) | **offen** – Matrix macht das steuerbar |

Keine Ableitung aus E2E-Seeder (`spotify`/`youtube`/`deezer` nur Test).

### B. Technische Vertragsentscheidungen (Empfehlung fett)

| # | Frage | Empfehlung |
|---|---|---|
| 1 | Tabellenname | **`oa_platform_medium_rules`** |
| 2 | Soft-Deactivate vs. Delete | **nur Soft (`is_active`)** |
| 3 | Revisionslogik Rules | **nein** (Audit + Snapshots) |
| 4 | Pin bei deaktivierter Rule | **ja**, analog Inaktiv-Platform-Pin |
| 5 | Mediumwechsel TKP→TKP | **Allocations bereinigen / 422 wenn unzulässig** |
| 6 | Leere Rules für Medium | **fail-closed** (keine speicherbare Position) |
| 7 | Reihenfolge Enforce vs. Admin | **Admin-Matrix vor oder parallel zu Enforce**; Enforce nicht vor leerer Prod ohne Kommunikationsplan |
| 8 | Preiswirkung Platform | **weiter nein** (FD-OA-PLAT separat) |

---

## 9. Priorisierter Implementierungsplan (Slices)

| Slice | Inhalt | Abhängigkeit | Migration | Risiko |
|---|---|---|---|---|
| **4j-1** | Migration `oa_platform_medium_rules` + Model + Query-Helper + Unit-Tests; noch **kein** Enforce | – | ja | niedrig |
| **4j-2** | Serverseitige Validierung im Resolver (+ Tests, Fake-HTTP unverändert) | 4j-1 | nein | mittel – fail-closed ohne Rules |
| **4j-3** | Admin-Backend JSON (Rules CRUD/Matrix-PUT, Audit, Gate) | 4j-1 | nein | niedrig |
| **4j-4** | Admin-Zuordnungsmatrix UI | 4j-3 | nein | niedrig |
| **4j-5** | Wizard-Catalog-Filter + Mediumwechsel-Cleanup + UX-Hinweis | 4j-1, ideal nach 4j-2 | nein | mittel (UX) |
| **4j-6** | E2E/Regression + **fachliche** Plattform- + Rule-Befüllung (eigene PO-Freigabe) | 4j-2…5 | Daten | hoch (Fachdaten) |

Abhängigkeitshinweis: **4j-2 vor Produktiv-Plattformen ohne Rules** würde Saves blockieren → entweder Rules zuerst pflegen (4j-3/4 + 4j-6 Daten) oder 4j-2 Feature-Flag / „enforce only if any rule exists for OA“ – **PO-Entscheidung #7**.

Empfohlene Reihenfolge: **4j-1 → 4j-3 → 4j-4 → (Fachdaten minimal) → 4j-2 → 4j-5 → 4j-6**.

Rückwärtskompatibilität:

| Bereich | Wirkung |
|---|---|
| Platform-Importer | unverändert (nur Stammdaten) |
| OA-Admin-API Preis/Targeting | unverändert |
| Preislisten/Targetings 2027 | unverändert |
| Wizard bis 4j-5 | unverändert sichtbar; danach gefiltert |
| Engine Mengenregeln | ergänzt, nicht ersetzt |
| Gespeicherte Calcs/Dispo | Snapshots stabil |
| Registry | bleibt `planned` bis eigener Release |
| Spot/SWF | keine |

---

## 10. Empfehlung für den ersten Slice

**Start mit 4j-1 (Datenmodell + Helper + Tests), ohne Enforce und ohne UI.**

Begründung: kleinster abnehmbarer Vertrag; keine Verhaltensänderung bei 0 Plattformen; schafft Basis für Admin und Validierung; null Risiko für Draft-Preisliste/Targetings.

Parallel PO-Klärung: Stream-/Influencer-Plattformen + Codes Midroll + Entscheidung Enforce-Zeitpunkt (#7).

### Bewusst nicht in D4c-4j Discovery

- Implementierung, Migration auf Live, Plattform-Seeds, Preislisten-Activate, Registry, FD-OA-PLAT Preis-Key
