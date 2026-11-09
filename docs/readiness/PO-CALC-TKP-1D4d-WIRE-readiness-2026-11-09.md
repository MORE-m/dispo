# PO-CALC-TKP-1D4d-WIRE – Release-Pfad Readiness

Stand: 9. November 2026
Analysebasis: PRE-2 Feature-HEAD `6a4e13407ed15a722d0dd3218985d4c6fbb36e2f`
Base: `c511834b898b1e4accf06dc8cfdbe3642b8d01b4`
Vorgänger: `docs/readiness/PO-CALC-TKP-1D4d-go-no-go-2026-11-09.md`,
`docs/readiness/PO-CALC-TKP-1D4d-PRE-3-activation-readiness-2026-11-09.md`
Branch (lokal, kein PR): `docs/po-calc-tkp-1d4d-wire-readiness`

**Nur Analyse.** Keine Runtime-Änderung. Kein Registry-Flip. Kein Activate-Write.
Keine `kind`-Mutation. Keine DB-Mutation. Kein OA-003/OA-004. Kein Merge.
**D4d bleibt NO-GO** (WIRE ist Voraussetzung für einen späteren REL, ersetzt ihn nicht).

---

## 1. Ist-Codepfad (aktuell nachvollzogen)

### Registry

`EngineProfileRegistry` CATALOG `oa_tkp` × `tkp`:

| Feld | Ist |
|---|---|
| `pair_status` | `planned` |
| `current_released_version` | `null` |
| `v1` | `implemented` |

### Zwei Freigabe-Ebenen

| Ebene | Schalter | Wirkung |
|---|---|---|
| Soft-Open | `dispo.oa_tkp_wizard_capability` (`OaTkpWizardCapability::isEnabled`) | Preview/Metadata/Wizard-`tkp`-Option **nur solange Pair nicht released** |
| Domain-Release | `pairIsReleased()` | HTTP Store/Update (`allowsHttpMutation`); Preview-Surface auch ohne Capability |

Lokal bestätigt (09.11.2026): Capability `false`, Preview `false`, Mutation `false`, Released `false`.

### Wizard-Methodenoption heute (`planned`)

1. `CalculationController::wizardProps(forCalculationWizard: true)`
2. `includeWizardPlannedTkp = previewSurface && !released`
3. `AdvertisingMediumCalculationMethodOptionsResolver::resolve(..., includeWizardPlannedTkp: true)`
4. LiveBookability liefert für die fünf Medien **keine** Methode (`kind=NULL` → block)
5. Zusatz: `OaTkpWizardMethodAvailability::optionForMedium`:
   - Capability/Preview an
   - Pair **nicht** released
   - Core-Medium (nicht `native_ad`)
   - aktive Zuordnung `oa_tkp` × `tkp`
   - → injiziert Option `tkp`

Frontend (`isSelectableForCalculationWizardPosition`):

- regulär `is_bookable_for_new_positions`, **oder**
- Capability + aktive Medium + Options enthalten `tkp`

### Nach hypothetischem Registry-Flip (`released` / `v1`)

| Pfad | Wirkung |
|---|---|
| HTTP Store/Update | **offen** (`allowsHttpMutation` = released) |
| Preview-Surface | **offen** (released ⇒ `allowsWizardPreviewSurface`) |
| Soft-Open `optionForMedium` | **zu** (`pairIsReleased()` ⇒ bewusst `null`) |
| `includeWizardPlannedTkp` | **false** (`!released` fällt weg) |
| LiveBookability | bleibt **unbookable** (`kind=NULL` bricht vor Pair-Check ab) |
| Wizard-Auswahl neue OA-Position | **zu** (keine Option, Buchbarkeit false) |
| Standardangebote / Budget | bleiben zu (heutiger Code; siehe §5–6) – **aber** ein naiver LiveBookability-Fix öffnet SO-UI, siehe §4 |

**Exakte Ursache:** Soft-Open ist nur für `planned` gebaut. Released verweist auf LiveBookability. LiveBookability verweigert `kind=NULL`, bevor `oa_tkp`/`tkp` geprüft wird. `CalculationKind::OaTkp` existiert, ist im LiveBookability-`match` aber **nicht** gemappt.

Lokal bestätigt für alle fünf Codes + `native_ad`:

```text
lb_bookable=false
lb_reason="Keine freigegebene Berechnungsmethode vorhanden."
opts_wizard=[]  (Capability aus)
opts_nonwizard=[]
```

---

## 2. Fünf V1-Medien

| Medium | kind | Mode | Default | Assignment |
|---|---|---|---|---|
| `pre_stream` | `NULL` | override | `tkp` | aktiv `oa_tkp`×`tkp` |
| `in_stream` | `NULL` | override | `tkp` | aktiv |
| `pre_stream_influencer` | `NULL` | override | `tkp` | aktiv |
| `in_stream_influencer` | `NULL` | override | `tkp` | aktiv |
| `midroll_music_environment` | `NULL` | override | `tkp` | aktiv |

`native_ad`: inherit, kein TKP-Assignment, bleibt OUT.

Warum sie heute (mit Capability) im Wizard erscheinen:

- nicht über LiveBookability
- über Soft-Open (`OaTkpWizardMethodAvailability` + Frontend-Capability-Zweig)

Was bei `released` greifen würde / fehlt:

- Soft-Open schaltet ab
- LiveBookability müsste greifen, tut es wegen `kind=NULL` nicht
- Medium-Methoden-Assignment allein reicht **nicht**; LiveBookability verlangt zuerst ein Kind und mappt nur Spot/SWF-Profile

---

## 3. `kind=NULL` Bewertung

### Urteil: **Variante A**

`kind=NULL` ist für OA-Medien **fachlich/historisch korrekt**, kein Versehen.

Evidenz:

- `CalculationKind::OaTkp` Docblock: „Medium-kind im Katalog bleibt null“
- ADV-001c3a: neue Medien bewusst `kind=null`; Katalog ≠ Buchbarkeit
- Soft-Open-Kommentar: „historisch oft kind=null; kein Medium-Code-Branching“

### Variante B (kind=`oa_tkp` setzen) – **nicht** empfohlen als WIRE

- wäre Stammdaten-/Migrations-Slice
- Compatibility `OaTkp` ↔ `online_audio` existiert zwar
- LiveBookability-Match müsste trotzdem `OaTkp` → `PROFILE_OA_TKP` lernen
- widerspricht dem dokumentierten Katalogvertrag „bleibt null“
- pauschales Setzen nur zum Gate-Umgehen ist untersagt

**WIRE muss das Gate OA-TKP explizit berücksichtigen**, ohne `kind` zu mutieren.

---

## 4. Minimaler Runtime-Fix (D4d-WIRE)

Ziel: nach späterem **echtem** Registry-Release sind die fünf Medien im
Kalkulationswizard wählbar; Preview/Store/Update über Registry; SO/Budget/OA-003
weiter geschlossen. Keine zweite Releasequelle.

### Kernfix 1 – LiveBookability OA-Pfad

Datei: `app/Support/Advertising/AdvertisingMediumLiveBookability.php`

Bei `kind` leer:

1. nicht sofort blocken, wenn Medium in `OaTkpCoreMedia` und ≠ `native_ad`
2. aktive Zuordnung `oa_tkp` × `tkp` auflösen
3. wenn Pair **nicht** released → unverändert unbookable (planned bleibt Soft-Open)
4. wenn Pair released + Version gesetzt → bookable mit Profil `oa_tkp`, Methode `tkp`
5. alle anderen `kind=NULL`-Medien weiter blocken (ADV-001c3a unverändert)

Kein Medium-Code-Branching für Preise; Erkennung über Core-Media-Scope + Assignment
(wie Soft-Open / Writer).

### Kernfix 2 – Standardangebot-UI darf nicht mitöffnen

Sobald LiveBookability OA als bookable meldet, gilt:

- `is_bookable_for_new_positions=true` im gemeinsamen Media-Payload
- SO-Filter in `wizard.tsx` nutzt bei `isStandardOffer` nur
  `isSelectableForNewWizardPositions` → OA würde **sichtbar**

Deshalb Pflicht-Begleitfix in demselben Slice:

| Ort | Maßnahme |
|---|---|
| `CalculationController::wizardProps` bei `forCalculationWizard=false` | OA-Medien: `is_bookable_for_new_positions=false` erzwingen und `tkp` aus Method-Options streichen (oder äquivalent SO-sicher klemmen) |
| optional Defense-in-Depth Frontend | SO-Zweig weiterhin ohne OA-Soft-Open; zusätzlich keine Selection rein über OA-Bookability |

Serverseitige SO-Gates bleiben zusätzlich:

- `StandardOfferMaterializer`: Reject bei `is_oa_tkp`
- `StandardOfferSpotClassicBoundary`: nur `kind=SpotClassic`

### Capability nach Release

- Mutation: weiter nur Registry (`allowsHttpMutation` = released) – **keine** Capability-Mutation
- Soft-Open wird nach Release für Options redundant (liefert `null`)
- Capability darf `false` bleiben; sie ist kein zweiter Release-Schalter
- Kein paralleler „WIRE-Flag“ neben der Registry

### Ausdrücklich nicht Teil von WIRE

- Registry-Flip (eigener REL-Slice)
- Preislisten-Activate (PRE-3)
- `kind`-Backfill
- OA-004 / Adserver
- OA-003 Festpreis
- Budget freigeben
- Platform Selection / OA-005/006
- neue Preisformel / Cross-Year-Fallback

---

## 5. Standardangebot-Isolation

| Gate | Heute | Nach WIRE+Release |
|---|---|---|
| Soft-Open Options | nur `forCalculationWizard=true` | bleibt so |
| Materializer | Reject `is_oa_tkp` | unverändert behalten |
| SpotClassicBoundary | nur SpotClassic-kind | unverändert (OA `kind=NULL` ≠ SpotClassic) |
| UI-Mediumliste SO | nur `is_bookable` | **muss** gegen OA-Bookability geklemmt werden (Fix 2) |

Erwartung: OA-TKP in Standardangeboten weiter fail-closed (UI + Server).

---

## 6. Budget-Isolation

`OaTkpHttpReleaseGuard::assertBudgetNotTkp`:

- unabhängig von Release und Capability
- Payload- oder Bestands-TKP → 422 „Budgetvorschläge sind für TKP-Positionen nicht verfügbar.“

WIRE ändert diesen Vertrag nicht. Frontend Soft-Open bereits
`planningMode === 'manual' && !isBudgetSetup`.

---

## 7. Preislistenabhängigkeit

WIRE aktiviert keine Listen und ändert keinen Resolver.

Nach späterem PRE-3 Write:

- Jahr 2026 → `2026.1` active
- Jahr 2027 → `2027.1` active
- `PriceListCalendar::currentYear()` am 09.11.2026 = 2026
- `OaTkpPriceResolver::resolveActive` jahrstrikt, fail-closed ohne Active / bei Mehrdeutigkeit
- kein Cross-Year-Fallback einführen

WIRE ohne active Listen: Wizard kann Medien zeigen, Speichern scheitert weiter
am Resolver – das ist akzeptiert und gehört zu PRE-3/REL, nicht zu WIRE.

---

## 8. OA-003 / OA-004 Isolation

### OA-003

- Writer erzwingt `pricing_settlement_mode=normal`, `fixed_price_nn=null`
- anderer Settlement → „Festpreis-Abschluss ist für TKP nicht verfügbar.“
- Registry: `oa_tkp` hat nur Methode `tkp`, kein released `fixed_price`
- WIRE darf keinen Festpreis-Pfad öffnen

### OA-004

- `oa_adserver_surcharges` = 0; Engine addiert keinen Adserver
- Midroll bleibt `80.0000` / `20.0000`
- WIRE darf keine +15-Logik, keine Surcharge-Befüllung, keine preiswirksamen Platform-Flags

---

## 9. Registry-Semantik bei Flip (nicht ausführen)

Technisch nötig für REL (nach WIRE + PRE-3 + fachlichem GO):

```text
pair_status = released
current_released_version = v1
versions.v1 = released
```

Wirkung nach WIRE-Fix:

| Fläche | Erwartung |
|---|---|
| Kalkulationswizard | fünf Medien + `tkp` via LiveBookability |
| Preview | offen |
| Store/Update | offen |
| Soft-Open | unnötig / null |
| Standardangebote | geschlossen (Fix 2 + Materializer) |
| Budget | geschlossen |
| Dispo | unverändert (braucht gespeicherte Position) |
| Platform Selection | weiterhin nicht vorhanden (KORR-Vertrag) |
| OA-003 | OUT |

---

## 10. Testmatrix (späterer WIRE-Slice)

### Positive (simuliertes Domain-Release, kein Soft-Open nötig)

- alle fünf Medien: LiveBookability bookable für `tkp`
- Wizard-Props Calc: Method-Options enthalten `tkp`; `is_bookable=true`
- Preview erlaubt
- Store/Update erlaubt (echtes Release oder kontrollierter Test-Double der Registry; bestehender `fakeHttpMutationAllowedForTests` deckt nur Mutation-Prop/HTTP, **nicht** Wizard-Bookability)

### Negative

- `native_ad` OUT
- Standardangebote: keine OA-Auswahl / Materializer Reject
- Budget: `assertBudgetNotTkp`
- OA-Festpreis OUT
- keine Plattformauswahl-Pflicht
- falsche Methode OUT

### Regression

- `planned` + Capability on: Soft-Open weiter wie D2a
- `planned` + Capability off: Wizard-Surface zu
- `released` ohne Soft-Open: Calc-Wizard offen über LiveBookability
- `kind=NULL` Nicht-OA-Medien weiter unbookable

### Bestehende Anker

| Test | Nutzen |
|---|---|
| `AdvertisingMediumLiveBookabilityTest` | null-kind bleibt für Nicht-OA; neue OA-released-Fälle |
| `AdvertisingMediumCalculationMethodOptionsResolverTest` | Soft-Open vs LiveBookability |
| `PoCalcTkp1d2aDataContractTest` | Capability Props |
| `PoCalcTkp1d4b1SaveReleaseGateTest` | Preview vs Mutation; Budget |
| `PoCalcTkp1d4b2HttpCreateUpdateTest` | Store/Update unter Fake; SO/Budget fail-closed |
| `resources/js/lib/oa-tkp-wizard.test.ts` | Selection-Helfer |
| `resources/js/lib/wizard-medium-selection.test.ts` | bookable-Grundvertrag |
| ggf. neues Feature: Wizard-Props nach Registry-Release ohne Capability | Kern von WIRE |

---

## 11. Erwarteter Dateiscope

### Voraussichtlich ändern

| Datei | Rolle |
|---|---|
| `app/Support/Advertising/AdvertisingMediumLiveBookability.php` | OA-Released-Pfad bei `kind=NULL` |
| `app/Http/Controllers/CalculationController.php` | SO-Props OA-Bookability/Options klemmen |
| Unit-/Feature-Tests LiveBookability + Options + Wizard-Props | Absicherung |
| ggf. `resources/js/pages/calculations/wizard.tsx` oder Selection-Helfer | Defense-in-Depth SO |

### Ausdrücklich nicht ändern

- `EngineProfileRegistry` CATALOG (Flip = REL, nicht WIRE)
- Activate-/Import-Services, Preislisten-JSON
- `advertising_media.kind` / Migrationen / Seeder-Kind
- `OaTkpNormalEngine` Preisformel
- `oa_adserver_surcharges` / Platform-Flags
- `assertBudgetNotTkp` Vertrag (nur Regression)
- `StandardOfferMaterializer` Reject entfernen
- OA-003 Settlement freischalten
- Soft-Open als dauerhafte zweite Releasequelle ausbauen

### Capability

`OaTkpWizardCapability` / Soft-Open: **behalten** für `planned`-Phase; nach Release
nicht mehr für Selection nötig. Keine neue parallele Freigabelogik.

---

## 12. Risiken

| Risiko | Mitigation |
|---|---|
| LiveBookability-OA öffnet SO-UI | Fix 2 Pflicht im selben PR |
| Soft-Open nach Release „vergessen“ abzuschalten | bereits `pairIsReleased→null`; Tests released ohne Capability |
| Registry-Test ohne CATALOG-Mutation schwer | dedizierter Test-Hook oder isolierter Unit mit Refactor-minimalem Seam – **kein** produktiver Zweit-Schalter |
| PRE-3 noch nicht active | WIRE trotzdem mergebar; produktiver REL erst nach PRE-3 + OA-004-Entscheidung |
| `kind=oa_tkp` Setzen als Abkürzung | **NEIN** (Variante B verworfen) |

---

## 13. GO / NO-GO

### D4d-WIRE Readiness (dieser Slice)

**GO** – klarer minimaler Runtime-Slice ohne Stammdaten-, Preis-, SO-, Budget-,
OA-003- oder OA-004-Freigabe ist möglich.

Voraussetzungen für die spätere Umsetzung:

1. LiveBookability OA-Pfad bei released + `kind=NULL` Core-Medien
2. Standardangebot-Props/UI bleiben OA-geschlossen
3. Registry bleibt die einzige Domain-Freigabe; Capability nur Soft-Open vor REL
4. Kein Registry-Flip in WIRE selbst

### Gesamt-D4d / Produktiv-Release

weiter **NO-GO** (PRE-2 CI/#220, PRE-3 Write, OA-004, Targeting-Fachfreigabe,
REL-Flip, Release-E2E ausstehend).

---

## STOP

Keine Runtime geändert. Kein Flip. Kein Activate. Kein PR. Kein Merge.
Nächster umsetzbarer Code-Slice nach GO: **D4d-WIRE** (isolierter Runtime-PR),
erst danach REL – und nur nach PRE-3 + fachlichen Restgates.
