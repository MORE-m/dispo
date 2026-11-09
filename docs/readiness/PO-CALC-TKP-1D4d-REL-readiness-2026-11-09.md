# PO-CALC-TKP-1D4d-REL – Registry-Release Readiness

Stand: 9. November 2026  
Analysebasis: `origin/main` `c511834b898b1e4accf06dc8cfdbe3642b8d01b4`  
Parallel (nicht mergen in diesem Auftrag):

| PR | Slice | HEAD (Analyse) |
|---|---|---|
| **#220** Draft | PRE-2 Preislisten 2026/2027 | `6a4e134…` |
| **#221** Draft | D4d-WIRE Bookability | `436a865…` |
| **#222** Draft | OA004-DEC interne Kosten | `69cd25e…` |

Lokale Vorgänger-Readiness (nicht auf `main`): PRE-3 Activation,
WIRE-Readiness. Branch dieses Dokuments (lokal, kein Push):
`docs/po-calc-tkp-1d4d-rel-readiness`.

**Dieser Slice ist Analyse / Docs only.** Kein Registry-Flip. Keine
DB-Mutation. Keine Preislistenaktivierung. Kein Runtime-Commit.
Kein Push / PR / Merge.

---

## Urteil (unter Annahme PRE-3 + WIRE abgeschlossen)

**GO** für den späteren D4d-REL-Implementierungs-Slice.

Voraussetzungen (noch nicht erfüllt auf `main` / `dispo_mat_core`):

1. PRE-2 gemergt und 2026.1 als Fachdaten dokumentiert  
2. PRE-3 Activate-Write: 2026.1 + 2027.1 jeweils active, 41 Items, genau 1 active/Jahr  
3. D4d-WIRE auf `main`  
4. OA004-DEC dokumentiert (15 EUR = interne Kosten, kein Verkaufsaufschlag)

Unter diesen Annahmen ist der REL-Scope **minimal** (Registry-CATALOG +
Tests + Docs). Kein weiterer echter Verkaufs-Blocker aus OA-004, Targeting
(PRE-1), Soft-Open (WIRE) oder Plattformmodell.

**Heute ohne diese Annahmen: weiter NO-GO** – Registry bleibt `planned`.

---

## 0. Fachlicher Zielstand des Teil-Releases

Erster OA-TKP-V1-Release = **nur Normal-TKP**.

Core-Medien (`OaTkpCoreMedia::CODES`):

```text
pre_stream
in_stream
pre_stream_influencer
in_stream_influencer
midroll_music_environment
```

OUT:

| Thema | Gate |
|---|---|
| `native_ad` | nicht in CoreMedia; PriceResolver / Soft-Open / Import reject |
| OA-003 Festpreis | Writer erzwingt `pricing_settlement_mode=normal`, `fixed_price_nn=null`; Registry hat kein `oa_tkp`×`fixed_price` |
| Standardangebote | WIRE: `forCalculationWizard=false` klemmt Bookability; Materializer reject `is_oa_tkp`; SpotClassicBoundary |
| Budget | `OaTkpHttpReleaseGuard::assertBudgetNotTkp` (release-unabhängig) |
| OA-005/006 / Endnutzer-Plattformauswahl | V1-Vertrag KORR; keine Wizard-Plattformpflicht |

---

## 1. Release-Voraussetzungen

| Gate | Ist (Analyse 09.11.2026) | Für REL nötig |
|---|---|---|
| PRE-1 Preisjahr / Targeting | dokumentiert: 2026 nötig, `dmp`/`geo` freigegeben | erfüllt (Docs) |
| PRE-2 2026.1 Draft + Parität | PR **#220** Draft, lokal 41/41 zu 2027.1 | Merge + dokumentierte Fachdaten |
| PRE-3 Activate | Readiness lokal; **kein** Write | 2026.1 + 2027.1 active, je 41 Items, genau 1 active/Jahr |
| D4d-WIRE | PR **#221** Draft (LiveBookability OA-Pfad + SO-Klemme + Test-Fake) | auf `main` |
| OA-004 | PR **#222** Draft: interne Kosten, Midroll 80/20, kein +15 | dokumentiert; **kein** Runtime-Slice |
| OA-003 | bewusst OUT | bleibt OUT; Teilfreigabe Normal-TKP |
| Registry | `planned` / `null` / `v1=implemented` | Flip erst im REL-Slice |

Keine historischen Blocker ungeprüft übernehmen: OA-004 ist nach OA004-DEC
**kein** Verkaufs-Release-Blocker mehr. Targeting-Freigabe (PRE-1) ist erledigt.

---

## 2. Exakter Registry-Diff

Datei: `app/Support/Calculation/EngineProfileRegistry.php`  
Paar: `oa_tkp` × `tkp` in `CATALOG`.

### Ist

```text
pair_status              = Planned
current_released_version = null
versions.v1              = Implemented
```

### Soll (D4d-REL)

```text
pair_status              = Released
current_released_version = 'v1'
versions.v1              = Released
```

### `validateDefinitions()` – warum alle drei wechseln müssen

Aus `EngineProfileRegistry::validateDefinitions()`:

1. `pair_status=released` **erfordert** gesetztes `current_released_version`
2. gesetztes `current_released_version` muss in `versions` existieren
3. die referenzierte Version muss Status **`Released`** haben
4. wenn `current_released_version` gesetzt ist, muss `pair_status` **`Released`** sein

Damit ist ein partieller Flip unmöglich. Spiegelbild zu bereits freigegebenen
Paaren (z. B. `spot_classic` × `average`).

Kein weiterer CATALOG-Eintrag nötig (kein zweites Methodenpaar, keine v2).

---

## 3. Wizard- / HTTP-Auswirkung nach Flip

Unter Annahme WIRE auf `main`:

| Fläche | Nach `released/v1` |
|---|---|
| Wizard fünf Core-Medien | bookable über LiveBookability (OA-Pfad bei `kind=NULL`) |
| Methode `tkp` | auswählbar über LiveBookability; Soft-Open liefert bewusst `null` (`pairIsReleased`) |
| Soft-Open / Capability | **nicht** zweite Releasequelle; `allowsWizardPreviewSurface()` true wegen `pairIsReleased()`; Capability-Flag egal |
| Preview | freigegeben (`allowsWizardPreviewSurface`) |
| Store / Update | freigegeben (`allowsHttpMutation` → `pairIsReleased`) |
| Prop `oa_tkp_http_mutation_allowed` | true |
| Resolver / Freeze neu | `currentReleasedVersion` = `v1`; LiveBookability verlangt Released |
| Snapshot / Freeze Persistenz | `engine_profile_key=oa_tkp`, `calculation_method_key=tkp`, `algorithm_version=v1` (`OaTkpNormalEngine::ALGORITHM_VERSION`) |
| Bestehende Drafts/Vorgänge | in `dispo_mat_core` aktuell **0** OA-Positionen; kein Migrationsbedarf. Reload eingefrorener `v1` bleibt über FreezeResolver lesbar/ausführbar (`Implemented`\|`Released`) |

### Soft-Open nach Release – Capability-Cleanup

**Variante A (bevorzugt): keine Capability-Änderung im REL-Slice.**

Begründung:

- Preview/Options: released ⇒ Surface offen ohne Config
- Soft-Open-Options: `OaTkpWizardMethodAvailability::optionForMedium` returns
  `null` wenn `pairIsReleased()`
- `includeWizardPlannedTkp` ist `… && !pairIsReleased()` → false
- Persistenz war nie Capability-gesteuert

Kein Cleanup von `dispo.oa_tkp_wizard_capability` / Env nötig für Funktion.

---

## 4. Preislisten-Gate

`OaTkpPriceResolver::resolveActive($year, …)`:

- filtert strikt `year` + `status=active`
- 0 Actives → ValidationException (fail-closed)
- >1 Actives → fail-closed
- **kein** Cross-Year-Fallback, **kein** Draft-Fallback, **kein** „latest“

Erwartung nach PRE-3:

| Kalender-/Positionsjahr | Active-Liste |
|---|---|
| 2026 | `2026.1` |
| 2027 | `2027.1` |

Registry-Release **ohne** passende active Jahresliste bleibt fail-closed – erwünscht.
REL ändert den Resolver nicht.

Midroll-Anker (fachlich): `list_tkp=80.0000`, `minimum_tkp=20.0000`. Kein +15.

---

## 5. OA-001 / OA-002

Unverändert im REL-Slice (keine Engine-/Assessor-Änderung):

| Anforderung | Ist-Verhalten |
|---|---|
| OA-001 | `OaTkpNormalEngine`: `effective_minimum_tkp = list_min + Σ min-Targeting`; `below_minimum` bei `final_tkp < effective` |
| OA-002 | Speichern unter Mindest möglich; Sonderfreigabe vor Disposition über bestehendes Approval-/Assessor-Modell (`below_minimum_tkp` / Reasons) |

---

## 6. OA-003 Isolation

Konkrete Gates, die Festpreis weiter blocken:

1. Registry: nur Methode `tkp` unter `oa_tkp` – kein released `fixed_price`-Paar  
2. `OaTkpWriterPositionResolver` / Persister: `pricing_settlement_mode=normal`, `fixed_price_nn=null`  
3. Payload: Settlement `fixed_price` für TKP → Validation („nicht verfügbar“)  
4. Keine OA-Festpreis-Sonderfreigabelogik im Scope

Release von `oa_tkp`×`tkp` öffnet **nicht** OA-Festpreis.

---

## 7. OA-004 Klarstellung

PO 09.11.2026 (OA004-DEC / PR #222):

- 15 EUR = interne Einkaufskosten / harte Kosten  
- kein Verkaufsaufschlag, kein Bestandteil von `final_tkp` / `minimum_tkp`  
- Midroll bleibt 80/20; kein `80+15`  
- `oa_adserver_surcharges` nicht als Verkaufsaufschlag befüllen  
- **kein** Runtime-Slice vor/in D4d-REL  

REL darf und soll OA-004-Code **nicht** nachziehen.

---

## 8. Standardangebote / Budget Isolation

### Standardangebote

| Schicht | Wirkung nach Release |
|---|---|
| Soft-Open | nur `forCalculationWizard=true` |
| WIRE Defense-in-depth | bei `forCalculationWizard=false` + CoreMedia: `is_bookable_for_new_positions=false`, TKP aus Options streichen |
| `StandardOfferMaterializer` | Reject bei `is_oa_tkp` |
| `StandardOfferSpotClassicBoundary` | nur SpotClassic-kind; OA `kind=NULL` ≠ SpotClassic |

### Budget

`OaTkpHttpReleaseGuard::assertBudgetNotTkp` – unabhängig von Registry und Capability.
REL öffnet Budget nicht.

---

## 9. Plattformmodell

V1-Vertrag (4j-KORR) unverändert durch Registry-Flip:

- keine Endnutzer-Plattformauswahl  
- Midroll = Bundle Spotify/YouTube/Deezer  
- technische `oa_platforms` / Midroll-Rules dürfen bestehen, sind nicht buchungsrelevant  
- Plattformmatrix ist **kein** REL-Gate  

KORR-3/4 Runtime ohne Plattformpflicht bleibt Voraussetzung (bereits auf `main`).

---

## 10. Test-Fake Bewertung (WIRE PR #221)

WIRE führt ein:

```text
EngineProfileRegistry::fakeOaTkpPairReleasedForTests()
EngineProfileRegistry::clearOaTkpPairFakeForTests()
```

Override nur für `oa_tkp`×`tkp`; wirft außerhalb Unit-/Feature-Tests.
Produktiver `CATALOG` bleibt unberührt.

### tearDown

Geprüft in PR #221:

| Test | clear in `tearDown` |
|---|---|
| `PoCalcTkp1d4dWireReleasedBookabilityTest` | ja |
| `AdvertisingMediumLiveBookabilityTest` | ja |

**Keine WIRE-Korrektur** wegen fehlendem Clear nötig.

### Entscheidung für REL

**Variante A – Fake behalten.**

Begründung:

- Nach echtem CATALOG-Release ist `fakeReleased` idempotent/redundant für Happy-Path, aber harmlos  
- Entfernen (Variante B) vermischt Cleanup mit Release und riskiert Test-Churn ohne Nutzen  
- REL-Tests müssen den **echten** CATALOG prüfen (`pair_status=released` ohne Fake)  
- Soft-Open-/planned-Regressionen, die heute „ohne Fake = planned“ assertieren, müssen im REL-Slice auf released umgestellt werden  
- Optional Future (nicht REL): `fakeOaTkpPairPlannedForTests()` falls historische Soft-Open-Regression gegen released Catalog nötig wird  

REL soll den Fake **nicht** als alleinige Freigabe-Evidenz nutzen.

---

## 11. Testmatrix (späterer REL-Slice)

### Registry

- [ ] `pair_status === released`
- [ ] `current_released_version === 'v1'`
- [ ] `statusForVersion(..., 'v1') === released`
- [ ] `validateDefinitions(CATALOG)` besteht (Boot / Unit)

### Five Core (je Medium)

- [ ] Wizard bookable (`is_bookable_for_new_positions`)
- [ ] Preview HTTP erlaubt
- [ ] Store persistiert OA-Position
- [ ] Update persistiert

### Preis

- [ ] Jahr 2026 → active `2026.1`
- [ ] Jahr 2027 → active `2027.1`
- [ ] Midroll 80.0000 / 20.0000
- [ ] Targeting additiv (`dmp`/`geo`)
- [ ] kein +15 Adserver im Final-TKP

### Negativ

- [ ] `native_ad` OUT
- [ ] OA-Festpreis OUT
- [ ] Budget OUT (`assertBudgetNotTkp`)
- [ ] Standardangebot: Core-Medien nicht bookable / Materializer reject
- [ ] Plattformauswahl OUT (keine Pflichtfelder)
- [ ] falsche Methode OUT
- [ ] Jahr ohne active Liste fail-closed

### Freeze

- [ ] `engine_profile_key = oa_tkp`
- [ ] `calculation_method_key = tkp`
- [ ] `algorithm_version = v1`

### Real Catalog vs Fake

- Happy-Path-REL-Tests **ohne** `fakeOaTkpPairReleasedForTests`  
- Fake höchstens in gezielten Isolationstests; TearDown clear beibehalten  

---

## 12. Rollback

Registry ist **codebasiert** (kein DB-Statusfeld für Pair-Release).

| Szenario | Vorgehen |
|---|---|
| Release-Regression | Code-Revert / Hotfix: CATALOG zurück auf `planned` / `null` / `v1=implemented` (drei Werte gemeinsam, sonst `validateDefinitions` fail) |
| DB-Rollback Registry | nicht nötig / nicht möglich |
| Preislisten | unabhängig; Activate-Rollback = eigener Datenvertrag (PRE-3) |

### Historische Reads nach Rollback auf `planned`

- Gespeicherte Snapshots mit `algorithm_version=v1` bleiben **lesbar**  
- FreezeResolver Execution erlaubt `Implemented` **oder** `Released` → `v1` nach Rollback auf `Implemented` bleibt ausführbar  
- OA-Writer Freeze-Pfad prüft gegen `OaTkpNormalEngine::ALGORITHM_VERSION` (`v1`), nicht gegen Pair-Released  
- **Neue** Positionen / LiveBookability / HTTP-Mutation sind nach Rollback wieder geschlossen  

Voraussetzung: `v1` bleibt im `versions`-Array (Altversionen nicht entfernen – Registry-Kommentar).

---

## 13. D4d-E2E Übergabe (separater Slice, nicht implementieren)

Mindest-Smoke gegen **echten** released Catalog + active Listen:

1. Kalkulation öffnen  
2. OA-Core-Medium wählen  
3. Methode TKP  
4. aktive Jahrespreisliste (2026 bzw. 2027)  
5. Menge / Impressions  
6. Targeting optional  
7. Preview  
8. Speichern  
9. Wieder öffnen  
10. Snapshot/Freeze (`oa_tkp` / `tkp` / `v1`)  
11. Mindest-TKP- / Sonderfreigabe-Fall (OA-001/002)  

Zusätzlich negativ-smoke: Budget, Standardangebot, `native_ad`, fehlende Jahresliste.

---

## 14. Minimaler REL-Dateiscope

Erwartung:

```text
app/Support/Calculation/EngineProfileRegistry.php   # nur oa_tkp×tkp drei Werte
tests/**                                            # Real-Catalog released; planned-Asserts anpassen
docs/**                                             # Fortschritt, Blocker, Readiness-Nachzug
```

**Nicht** im REL-Slice:

- Migrationen / Seeder  
- Preislisten-Activate  
- Plattform-/Targetingdaten  
- Engineformel / OA-004-Runtime  
- Capability-Config / Env  
- Controllerlogik (sofern WIRE korrekt auf `main`)  
- Fake entfernen  

Weitere Runtime-Dateien nur, wenn nach Merge von WIRE ein Regressionstest zeigt,
dass planned-Asserts außerhalb der Registry hart brechen – dann Test-Anpassung,
kein neuer Feature-Code.

---

## 15. GO / NO-GO

| Frage | Antwort |
|---|---|
| REL-Slice **heute** auf `main` umsetzen? | **NO-GO** (PRE-2/3/WIRE/OA004-Docs noch parallel) |
| REL-Slice **nach** PRE-3 + WIRE (+ OA004-Docs)? | **GO** – minimaler Registry-Flip + Tests + Docs |
| OA-004 Runtime vor REL? | **nein** |
| OA-003 in diesem Release? | **nein** |
| Soft-Open-Cleanup nötig? | **nein** (Variante A) |
| Test-Fake | **behalten** (Variante A); REL prüft echten Catalog |

---

## STOP

Kein Registry-Flip. Keine Aktivierung. Keine Runtime-Änderung.
Kein Push / PR / Merge dieses Readiness-Branches.
