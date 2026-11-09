# PO-CALC-TKP-1D4d – Go/No-Go Readiness OA-TKP Registry-Release

Stand: 9. November 2026  
Basis: `main` `8366b824071999ec51f5d1740346b0e45ccb36bf`  
(PR **#217** `PO-CALC-TKP-1D4c-4j-KORR-4`, Post-Merge-CI
[`42394888095`](https://github.com/MORE-m/dispo/actions/runs/42394888095):
`ci` / `mysql` / `e2e-spt008` success)

Analyse + Docs. Keine Registry-Mutation, keine DB-Mutation, keine
Preislisten-Aktivierung, kein Deploy, keine Runtime-Änderung.

**Ergebnis: NO-GO.** `oa_tkp` × `tkp` bleibt `planned`.
OA-TKP ist nicht produktiv freigegeben.

Nachtrag PRE-1, 9. November 2026: 2026 wird benötigt und übernimmt die
2027er Preise; `2027.1` und die Targetings `dmp`/`geo` sind fachlich
freigegeben. OA-004 bleibt offen, Midroll-Listen-TKP bleibt 80.0000.
D4d bleibt NO-GO. Dok:
`docs/readiness/PO-CALC-TKP-1D4d-PRE-1-po-entscheidung-2026-11-09.md`.

Fachdaten gelesen aus der lokalen DB `dispo_mat_core` (nur SELECT,
9. November 2026). Testfixtures sind keine Fachdaten.

---

## A. Statusnachzug KORR-1…4

| Slice | Inhalt | Evidenz | Status |
|---|---|---|---|
| KORR-1 | V1-Vertrag ohne Endnutzer-Plattformauswahl | PR **#212**, Merge `b4c8231` | erledigt |
| KORR-2 | Wizard-/Resolver-Readiness ohne Plattformpflicht | kein eigener Merge auf `main`; die Folge ist in KORR-3 umgesetzt | erledigt (ohne eigenen Commit) |
| KORR-3 | Runtime ohne Plattformpflicht | PR **#216**, Merge `b87213f`, Commit `6da1eac` | erledigt |
| KORR-4 | E2E-/Abnahme fünf Werbemittel ohne Plattformauswahl | PR **#217**, Merge `8366b82`, CI `42394888095` success | erledigt |

### V1-Vertrag (unverändert bindend)

- fünf OA-Werbemittel: `pre_stream`, `in_stream`, `pre_stream_influencer`,
  `in_stream_influencer`, `midroll_music_environment`
- keine Endnutzer-Plattformauswahl
- Midroll = Spotify/YouTube/Deezer gemeinsam
- OA-005/006 = Future
- Platform×Medium-Rules nicht buchungsrelevant
- 4j-5 alter fail-closed Ansatz verworfen

### Weiterhin offen

- D4d Registry- und Produktivfreigabe (**dieser Bericht: NO-GO**)
- OA-004
- OA-003 / OA-Festpreis (empfohlene spätere Teilfreigabe, siehe F)
- reale Fachdaten- und Preislistenfreigabe

---

## B. Registry Ist

Code-Katalog `EngineProfileRegistry::CATALOG`, nicht administrierbar:

| Feld | Wert |
|---|---|
| Paar | `oa_tkp` × `tkp` |
| `pair_status` | `planned` |
| `current_released_version` | `null` |
| Version `v1` | `implemented` |

Release wäre eine Code-Änderung dieses Katalogs
(`pair_status=released`, `current_released_version=v1`,
`versions.v1=released`). Die Registry-Invariante verlangt das gemeinsam.
In diesem Slice nicht gesetzt.

Zwei Ebenen:

| Ebene | Schalter | Wirkung heute |
|---|---|---|
| Soft-Open | `dispo.oa_tkp_wizard_capability` ← `OA_TKP_WIZARD_CAPABILITY`, Default `false` (`.env.example` auskommentiert, `.env` von `dispo-main` ohne Setzung) | Preview, Pricing-Metadata, Wizard-Methodenoption nur solange das Paar **nicht** released ist |
| Registry-Release | `OaTkpWizardCapability::allowsHttpMutation()` = `pairIsReleased()` | HTTP Store/Update und Prop `oa_tkp_http_mutation_allowed` |

`allowsHttpMutation()` hat keinen produktiven Override. Der Test-Fake
wirft außerhalb von Unit-Tests.

---

## I. Release-Wirkungsmatrix

Was ein Flip `planned` → `released` **im heutigen Code** öffnet.
Nicht ausgeführt.

| Pfad | Nach Flip | Grund |
|---|---|---|
| HTTP Store | offen | `CalculationController::store` → `assertMutationAllowed` → `allowsHttpMutation()` |
| HTTP Update | offen | derselbe Guard in `update` |
| UI-Save-Flag | `oa_tkp_http_mutation_allowed=true` | dieselbe Methode |
| Preview / Metadata / OA-Katalog | offen | `allowsWizardPreviewSurface()` wird bei released wahr, auch wenn die Capability aus ist |
| Wizard-Methodenoption `tkp` | **zu** | `includeWizardPlannedTkp` verlangt Preview **und** nicht released. `optionForMedium()` liefert bei released absichtlich `null` und verweist auf LiveBookability |
| LiveBookability / `is_bookable_for_new_positions` | **bleibt falsch** | Die fünf Medien haben `kind=NULL`. LiveBookability blockt leeres Kind, bevor der Pair-Status geprüft wird. `CalculationKind::OaTkp` ist im Match nicht auf `oa_tkp` gemappt |
| Wizard-Auswahl neuer OA-Positionen | **zu** | Frontend: buchbar oder (Capability und Methodenoption `tkp`). Nach dem Flip fehlt die Option, Buchbarkeit bleibt falsch. Capability `true` repariert das nicht |
| Reload gespeicherter OA-Positionen | technisch ja, fachlich leer | Reload läuft für gespeicherte `oa_tkp`-Positionen. In `dispo_mat_core`: 0 solche Positionen |
| Dispo-Create | unverändert | Create prüft die Registry nicht. Voraussetzung bleibt eine gespeicherte Position |
| Standardangebote | bleiben zu | Options ohne Soft-Open (`buildWizardProps` setzt `forCalculationWizard=false`). Materializer wirft bei `is_oa_tkp` unabhängig vom Pair-Status |
| Budget | bleibt zu | `assertBudgetNotTkp()` unabhängig von Release und Capability |
| falsche Methode / nicht-OA | unverändert fail-closed | Zuordnungsprüfung und Method-Switch sitzen im Writer, nicht am Pair-Status |
| Expected-Token / 409 | unverändert | `expected_oa_tkp_price_list_id` im Writer |
| Preisjahr-Auflösung | fail-closed ohne active Liste | `OaTkpPriceResolver::resolveActive` verlangt genau eine `active` Revision des gewählten Jahres |

**Blocker am Release-Mechanismus:** Ein reiner Katalog-Flip öffnet HTTP-Mutation
und schließt gleichzeitig die einzige Wizard-Methodenoption. Neue OA-Positionen
wären in der UI nicht wählbar, per HTTP aber speicherbar. Das ist vor jedem
Flip zu korrigieren (Slice **D4d-WIRE**). Dabei müssen Standardangebote und
Budget ausgeschlossen bleiben: sobald LiveBookability OA als buchbar meldet,
nimmt der Options-Resolver `tkp` für jeden Consumer auf, auch für
`buildWizardProps(forCalculationWizard: false)`.

---

## C. Reale Fachdaten `dispo_mat_core`

Read-only, 9. November 2026. Keine Zeile geschrieben.

### Registry

Unverändert `planned` / `current_released_version=null` / `v1=implemented`
(Code, nicht DB).

### OA-Preislisten

| id | Jahr | Version | Revision | Status | Items | published_at |
|---|---|---|---|---|---|---|
| 1 | 2027 | `2027.1` „OA-TKP 2027 (Fachbefüllung D4c-4e)“ | 1 | **draft** | 41 | NULL |

- active: **0**
- archived: **0**
- Jahr 2026: **0 Listen**
- Jahr 2027: genau diese eine Draft-Liste

Die 41 Items decken die aktiven Inventory-Medium-Rules der fünf Kernmedien
ohne Lücke und ohne Zusatzzeile. Das ist Abdeckung eines Drafts, keine
freigegebene Preisliste.

### Targetings

| code | name | category | aktiv | Listen-Aufschlag | Mindest-Aufschlag | Revision |
|---|---|---|---|---|---|---|
| `dmp` | DMP | `dmp` | ja | 25.0000 | 5.0000 | 1 |
| `geo` | Geo | `technisch` | ja | 15.0000 | 3.0000 | 1 |

Weitere Zeilen: keine. Kein PO-Dokument, das diese zwei Zeilen als
freigegebenen Zielgruppenkatalog ausweist.

### Fünf Medien

Alle aktiv, Kategorie `online_audio` aktiv, Mode `override`,
Default-Methode `tkp` (id 4, aktiv), Assignment `oa_tkp` × `tkp` aktiv.
`kind` ist bei allen fünf **NULL**. `native_ad` bleibt inherit und ohne
TKP-Assignment.

Aktive Inventar-Rules (alle Inventare aktiv):

| Medium | aktive Rules |
|---|---|
| `pre_stream` | 10 |
| `in_stream` | 10 |
| `pre_stream_influencer` | 10 |
| `in_stream_influencer` | 10 |
| `midroll_music_environment` | 1 (`inv_more_kombi_online_audio`) |

### Plattformbestand (dokumentiert, kein V1-Release-Gate)

| code | name | sort | aktiv | `is_adserver_relevant` |
|---|---|---|---|---|
| `spotify` | Spotify | 10 | ja | false |
| `youtube` | YouTube | 20 | ja | false |
| `deezer` | Deezer | 30 | ja | false |

Aktive Platform×Medium-Rules: genau diese drei auf
`midroll_music_environment`. Stream und Influencer: 0 Rules.
Buchungsrelevant für V1: nein (`OaPlatformMediumRules` nur im Admin-Writer).

### Sonstige OA-Daten

| Bestand | Anzahl |
|---|---|
| `oa_adserver_surcharges` | 0 |
| Kalkulationspositionen `engine_profile_key=oa_tkp` | 0 |
| Dispo-Positionen `oa_tkp` | 0 |
| OA-Plattform-Allocations | 0 |
| OA-Targeting-Selections | 0 |

---

## D. Fünf Medien

| Medium | Assignment | kalkulierbare Inventare | Draft-2027.1-Abdeckung | Save/Reload/Dispo/Show/Snapshot/Sonderfreigabe |
|---|---|---|---|---|
| `pre_stream` | aktiv `oa_tkp`×`tkp` | 10 aktive Rules | 10/10 | Codepfad in KORR-4 unter Test-Fake; produktiv 0 Vorgänge, Save durch Registry zu |
| `in_stream` | aktiv | 10 | 10/10 | gleich |
| `pre_stream_influencer` | aktiv | 10 | 10/10 | gleich |
| `in_stream_influencer` | aktiv | 10 | 10/10 | gleich |
| `midroll_music_environment` | aktiv | 1 | 1/1 | gleich; Preis siehe G/H-Abschnitt Midroll |

Produktiv kalkulierbar ist keines der fünf: der Resolver nimmt nur
`status=active`. Der Wizard-Default für das Preisjahr ist
`PriceListCalendar::currentYear()` (Europe/Berlin). Am 9. November 2026
ist das **2026**. Für 2026 existiert keine Liste. Für 2027 existiert nur
ein Draft, den `resolveActive` nicht verwendet.

---

## E. OA-001 / OA-002

KORR-3 und KORR-4 ändern die Preisformel und den Assessor nicht.
Leere Plattform-Allocations sind seit KORR-3 zulässig; nicht-leere
Allocations bleiben mengenvalidiert (KORR-4).

### OA-001

Bindend ist der korrigierte Vertrag aus D4c-4b, nicht der ältere
Katalogsatz „nur Basis gegen Mindest-TKP“:

```text
final_tkp = basis_tkp + Σ Listen-Targeting-Aufschläge
effective_minimum = Preislisten-Mindest-TKP + Σ Mindest-Targeting-Aufschläge
unter Minimum, wenn final_tkp < effective_minimum
Gleichheit ist zulässig
```

`OaTkpNormalEngine` setzt das so um. Der Assessor vergleicht bei gesetztem
`price_list_minimum_tkp_snapshot` den Final-TKP mit `minimum_tkp_snapshot`
(effektive Schwelle). Ohne dieses Feld bleibt der ältere Vergleich
Basis gegen `minimum_tkp_snapshot`.

### OA-002

| Schritt | Ist im Code | durch KORR-3/4 verändert? |
|---|---|---|
| Speichern unter effektivem Minimum | ja, Persister setzt danach `requires_special_approval` | nein |
| strukturierte Begründung | `oa_tkp_basis_below_minimum` | nein |
| Dispo-Create | wird durch Under-Min nicht abgewiesen; Header übernimmt Flag und Reasons | nein |
| Freigabeart | Submit setzt `approval_kind=special`, wenn `requiresSpecialApproval()` | nein |
| wer freigeben darf | `approveSpecial`: Admin/Management, Vertrieb nur mit `can_special_approve`; Ersteller nie. Reguläre Freigabe genügt nicht | nein |
| danach bis Disposition | Approve setzt Status `at_disposition` | nein |
| freie Nutzerbegründung beim Special-Submit | weiterhin das offene 1C1-Follow-up | nein |

Der Katalogsatz „Übergabe blockiert, bis Begründung und Sonderfreigabe
vorliegen“ ist operativ das Sonderfreigabe-Gate vor `at_disposition`,
nicht ein Reject von `createFromCalculation`. Die Sonderfreigabe-Kette
selbst ist vorhanden. Sie ist kein eigener D4d-Blocker. Die fehlende
Freitext-Begründung bleibt ein bekanntes Follow-up und ist nicht Teil
dieses NO-GO.

---

## F. OA-003 – Online-Audio-Festpreis

Fachvertrag: effektiver TKP aus Festpreis / Impressions × 1.000, Prüfung
gegen Mindest-TKP, OA-Festpreis immer mit Sonderfreigabe.

| Frage | Befund |
|---|---|
| 1. Implementiert? | **nein.** `OaTkpWriterPositionResolver` setzt `pricing_settlement_mode=normal` und `fixed_price_nn=null`. Jeder andere Settlement-Wert wirft „Festpreis-Abschluss ist für TKP nicht verfügbar.“ Engine-Kommentar: kein Festpreis |
| 2. Im V1-Wizard sichtbar? | nein |
| 3. Speicherbar? | nein |
| 4. Geforderte Sonderfreigabe? | es gibt keinen OA-Festpreis-Pfad, also auch keine Festpreis-Sonderfreigabe. Die Under-Min-Sonderfreigabe (OA-002) ist ein anderer Vertrag |
| 5. Teil des D4d-Scopes? | **nein, späterer Slice**, wenn die Teilfreigabe ausdrücklich so dokumentiert wird |

### Entscheidungsvorlage

**A – OA-003 blockiert den vollständigen BL-P6-01-Release.**  
Dann bleibt D4d NO-GO bis ein eigener Festpreis-Slice existiert.
BL-P6-01 nennt OA-001 bis OA-009. Ein vollständiger Paket-Release wäre
damit gesperrt.

**B – D4d gibt zunächst nur Normal-TKP frei.**  
Das muss als **Teilfreigabe** dokumentiert werden. OA-003 bleibt offen.
Der bisherige 1B/1D-Vertrag ist eine Normal-TKP-Engine. KORR ändert daran
nichts. Eine stille Streichung von OA-003 aus BL-P6-01 ist unzulässig.

**Empfehlung: B als Scope der späteren Freigabe, nicht als Freigabe heute.**
OA-003 hält das Gesamtpaket BL-P6-01 offen und ist kein zusätzlicher
Grund, Normal-TKP ewig zu sperren. Heute ist das Urteil trotzdem NO-GO,
weil Preislisten und OA-004 und der Wizard-Release-Pfad fehlen.
B wird erst dann eine vertretbare Teilfreigabe, wenn diese drei Gates zu sind.

---

## G. OA-004 – 15 EUR Adserver-TKP

Fachvertrag: zusätzlicher Adserver-TKP von 15 EUR bei Spotify/Deezer/YouTube
als Verkaufs-TKP/Preisbestandteil. Nach KORR ist
`midroll_music_environment` das gemeinsame Werbemittel dieser drei Namen.
Keine Plattformauswahl. Der Aufschlag müsste am Midroll-Medium greifen,
nicht an einer vom Nutzer gewählten Plattform.

| Frage | Befund |
|---|---|
| 1. Wo müsste er greifen? | auf `midroll_music_environment`, additiv zum Verkaufs-TKP |
| 2. Über die Preisliste abgebildet? | **nicht belegt.** Draft-Zeile siehe Midroll-Beispiel. Die Differenz zu Pre-Stream auf demselben Inventar ist 24.0000, zu In-Stream 36.0000. Das ist kein +15-Muster. Kein Dokument sagt, dass 80.0000 den Adserver bereits enthält |
| 3. Eigenes Feld / Surcharge-Modell? | Tabelle `oa_adserver_surcharges` und Model `OaAdserverSurcharge` existieren seit 1A. **0 Zeilen.** Kein Calculation-Service liest das Model |
| 4. `is_adserver_relevant` wirkungslos? | **ja für den Preis.** Drei Plattformen stehen auf `false`. Die Engine liest das Feld nicht. Admin kann es pflegen |
| 5. Final-TKP ohne OA-004 um 15 EUR zu niedrig? | die Engine addiert keinen Adserver-TKP. Ob der Draft-Listenpreis das kaufmännisch schon enthält, ist nicht belegt. Solange das offen ist, wäre ein produktiver Midroll-Final-TKP um 15 EUR zu niedrig, wenn der Listenpreis den Bestandteil nicht enthält |
| 6. Bestehende Preislogik, die OA-004 erfüllt? | **keine.** Engine-Kommentar: „Kein Adserver (FD-OA-ADSERVER).“ Formel ist Basis plus Targeting-Aufschläge |

OA-004 ist nicht umgesetzt. Midroll darf produktiv nicht freigegeben werden,
solange nicht fachlich belegt ist, dass der Listenpreis die 15 EUR bereits
enthält. Das ist ein D4d-NO-GO.

### Midroll-Preisbeispiel (Draft, nicht produktiv)

Quelle: `oa_tkp_price_list_items` der Draft-Liste `2027.1`, Medium
`midroll_music_environment`, Inventar `inv_more_kombi_online_audio`.
Kein gespeicherter Vorgang. Kein erfundener Basis-TKP.

```text
Listen-TKP (Draft 2027.1):     80.0000
Preislisten-Mindest-TKP:       20.0000
Basis-TKP:                     kein Vorgang
+ Targeting:                   keine Selection
+ OA-004 Adserver-TKP:         nicht angewendet (Tabelle leer, Engine addiert nichts)
= Final-TKP:                   nicht als produktiver Preis ableitbar
```

Dieselbe Inventarzeile im selben Draft, nur als Vergleich der Listenanker:

| Medium | list_tkp | minimum_tkp |
|---|---|---|
| `pre_stream` | 56.0000 | 14.0000 |
| `in_stream` | 44.0000 | 8.0000 |
| `midroll_music_environment` | 80.0000 | 20.0000 |

Alle zehn Pre-Stream-Zeilen des Drafts sind 56.0000/14.0000, alle zehn
In-Stream-Zeilen 44.0000/8.0000, Influencer gleich dem jeweiligen
Stream-Medium. Midroll hat nur diese eine Zeile.

---

## H. Targeting

Mechanik im Code (unverändert durch KORR-3/4):

- aktive Definitionen gehen in den Wizard-Katalog, wenn die Preview-Surface an ist
- Aufschläge additiv auf den Final-TKP
- `minimum_tkp_surcharge` additiv auf das effektive Minimum
- Selection speichert Snapshot inklusive Mindest-Aufschlag
- Dispo-Snapshot übernimmt die Targeting-Zeilen

Datenstand: zwei aktive Zeilen `dmp` und `geo` (Werte in Abschnitt C).
Das sind keine belegten, freigegebenen Fachzielgruppen. Einordnung:
**Release-Blocker für die Behauptung, OA-008/009 seien produktiv mit
Fachdaten befüllt.** Die Mechanik selbst ist kein Code-Blocker.

UND-/ODER-Verknüpfung aus OA-008 ist in diesem Datenbestand nicht
vorhanden (zwei unabhängige Zeilen, keine Verknüpfung gepflegt).

---

## Wizard-Sollkonfiguration (noch nicht setzen)

Heute in Produktion / lokalem `dispo-main`:

- Registry `planned`
- `OA_TKP_WIZARD_CAPABILITY` ungesetzt → Default false → Wizard-Surface zu, HTTP zu

Das ist der sichere Ist-Zustand. Nicht ändern.

Soll **vor** einem späteren Flip, sonst entsteht „HTTP offen, Wizard zu“:

1. Slice D4d-WIRE: bei `pair_status=released` bleiben die fünf Medien im
   Kalkulationswizard wählbar. `kind=NULL` darf den Pfad nicht schließen.
   Standardangebote bleiben ohne `tkp`. Budget bleibt ohne TKP.
2. Erst danach Katalog: `pair_status=released`,
   `current_released_version=v1`, `versions.v1=released`.
3. `OA_TKP_WIZARD_CAPABILITY` kann false bleiben, sobald der Released-Pfad
   die Methodenoption selbst liefert. Die Capability ist nur der
   Soft-Open vor dem Release.
4. Mindestens eine **active** OA-TKP-Preisliste für jedes Jahr, das der
   Wizard anbietet. Am 9. November 2026 ist das laufende Jahr 2026
   (keine Liste) und das nächste Jahr 2027 (nur Draft).
5. OA-004-Entscheidung und Targeting-Freigabe liegen vor.
6. Kein Registry-Flip in diesem Slice.

---

## HTTP, Dispo, Standardangebote, Budget

### HTTP Create/Update

`PoCalcTkp1d4b2HttpCreateUpdateTest` deckt unter Test-Fake ab: Create,
Update, Expected-Token 409, Method-Switch Spot↔OA verboten, Mixed,
Budget und Standardangebot fail-closed, Planned bleibt 422.
Dieselben Guards lesen produktiv den Pair-Status. Ein echter Release
braucht dafür keinen Test-Fake. Der Wizard-Gap aus der Wirkungsmatrix
bleibt davon unberührt: HTTP wäre offen, die UI-Auswahl nicht.

### Dispo

KORR-4 (`PoCalcTkp1d4c4jKorr4V1NoPlatformMatrixTest`) unter Test-Fake:
fünf Medien ohne Plattformallocation, historische Allocations bleiben
lesbar, nicht-leere Allocations bleiben validiert, Registry im Test
weiter `planned`. Ältere Slices (D3a–D3d) decken Snapshot, Adoption,
Show, Operational Flight, Completion und Mixed Spot+OA ab.
OA-002-Sonderfreigabe bleibt wirksam (Abschnitt E).
In `dispo_mat_core` gibt es keinen realen OA-Vorgang. Das ist Evidenz
des Codepfads, kein produktiver Smoke.

### Standardangebote und Budget

Der heutige Flip öffnet sie nicht (Wirkungsmatrix). Kein Seiteneffekt-Blocker
des aktuellen Codes. Auflage für D4d-WIRE: der künftige buchbare OA-Pfad
darf SO-Options und Budget nicht mitöffnen. Der Materializer-Reject und
`assertBudgetNotTkp()` müssen bleiben.

---

## J. Gate-Matrix

| Gate | Status | Evidenz | Blocker? |
|---|---|---|---|
| KORR-1 | erledigt | PR #212 `b4c8231` | nein |
| KORR-2 | erledigt ohne eigenen Merge | Folge in KORR-3 | nein |
| KORR-3 | erledigt | PR #216 `b87213f` | nein |
| KORR-4 | erledigt | PR #217 `8366b82`, CI `42394888095` | nein |
| Registry-Codepfad | Flip öffnet HTTP und schließt die Wizard-Option | `allowsHttpMutation`, `includeWizardPlannedTkp`, LiveBookability bei `kind=NULL` | **ja** |
| fünf Medien | Stammdaten und Rules vorhanden, `kind` NULL | `dispo_mat_core` | nein als Stammdatum; ja als Teil des Wizard-Release-Pfads |
| Preislisten | nur Draft `2027.1`, 41 Items, 0 active | SELECT 09.11.2026 | **ja** |
| Preisjahr | Default 2026 ohne Liste; 2027 nur Draft | `PriceListCalendar`, `resolveActive` | **ja** |
| OA-001 | Effective-Minimum umgesetzt | `OaTkpNormalEngine`, Assessor | nein |
| OA-002 | Speichern ja; Sonderfreigabe vor Disposition; Freitext offen seit 1C1 | Assessor, ApprovalService, Policy | nein für D4d; Freitext bleibt Follow-up |
| OA-003 | nicht implementiert | Writer erzwingt `normal` / `fixed_price_nn=null` | nein für eine **ausdrückliche** Normal-TKP-Teilfreigabe (Empfehlung B); ja für einen vollständigen BL-P6-01-Release (Variante A) |
| OA-004 | nicht umgesetzt, nicht im Listenpreis belegt | Engine, 0 Adserver-Zeilen, Midroll-Draft 80.0000 | **ja** |
| OA-008/009 Targeting | Mechanik da; nur `dmp`/`geo` ohne Freigabebeleg | 2 Zeilen | **ja** (Fachdaten) |
| Wizard | Soft-Open nur vor Release; nach Release derzeit ohne Option | Capability + Options-Resolver | **ja** |
| HTTP Store/Update | testseitig mit Fake; produktiv zu | D4b-2, Guard | nein als Code, solange der Wizard-Pfad vor dem Flip korrigiert wird |
| Dispo | Codepfad unter Fake belegt; 0 Realvorgänge | KORR-4, D3* | nein als Code |
| Snapshot | OA-Felder im Mapper | `DispoOrderSnapshotMapper` | nein als Code |
| E2E | KORR-4 und CI `e2e-spt008` success; kein Release-Smoke | Run `42394888095` | nein für KORR-4; echter Release-Smoke erst nach REL |
| Composer audit | im Job `ci` enthalten, Job success | `.github/workflows/tests.yml`, Run `42394888095` | nein; lokal in diesem Slice nicht erneut ausgeführt |
| Standardangebote OUT | bleiben bei heutigem Flip zu | Materializer, `buildWizardProps(false)` | nein heute; Auflage für D4d-WIRE |
| Budget OUT | bleibt zu | `assertBudgetNotTkp` | nein |

---

## K. Urteil

**NO-GO.**

GO wäre nur zulässig, wenn Preisdaten, OA-004, Targeting-Freigabe und ein
Wizard-Pfad, der nach dem Flip sichtbar bleibt, vorhanden wären.
Das ist nicht der Fall.

CONDITIONAL GO (Normal-TKP ja, Festpreis OUT) ist fachlich die richtige
spätere Form (Variante B), sobald die NO-GO-Gates geschlossen sind.
Heute ist sie nicht vertretbar: es gibt keine active Preisliste, das
Wizard-Jahr 2026 hat keine Liste, und der Midroll-Preis wäre ohne
belegten OA-004-Bestandteil nicht freigabefähig. Der Registry-Flip hätte
zusätzlich die Wizard-Auswahl geschlossen.

Registry bleibt `planned`. Nichts aktiviert.

---

## L. Nächste Slices

Nur das, was dieser Befund erzwingt.

1. **D4d-PRE-1** – PO-Fachentscheidung zu den vorhandenen Daten.
   Keine Aktivierung, keine neuen Preise durch den Agenten.
   Vier Entscheidungen: Preisjahr 2026 ja/nein; Draft `2027.1`
   kaufmännisch freigeben ja/nein; enthält Midroll `80.0000` die
   15 EUR Adserver bereits ja/nein; sind `dmp`/`geo` der freigegebene
   Zielgruppenkatalog ja/nein.
2. **OA-004** – nur wenn die PO-Antwort zu Midroll „nein, 15 EUR sind
   nicht im Listenpreis“ ist. Sonst dokumentierte Teilfeststellung
   „im Listenpreis enthalten“, ohne neue Preislogik.
3. **D4d-WIRE** – Released-Pfad zeigt die fünf Medien im Kalkulationswizard
   und lässt Standardangebote sowie Budget zu. Registry bleibt in diesem
   Slice `planned`. `kind=NULL` wird dabei berücksichtigt.
4. **D4d-REL** – erst nach active Liste für jedes freigegebene Preisjahr,
   nach OA-004-Klärung, nach Targeting-Freigabe und nach D4d-WIRE.
   Dann erst Katalog-Flip.
5. **D4d-E2E** – Smoke ohne Test-Fake gegen die echte Registry.

OA-003 nicht in dieser Reihe. Erst nach der Teilfreigabe Normal-TKP,
als eigener Slice, falls BL-P6-01 vollständig geschlossen werden soll.

Nicht empfohlen: Registry-Flip, Preislisten-Aktivierung oder
OA-004-Implementierung als nächster Schritt vor der PO-Entscheidung.

---

## Prompt für den unmittelbar nächsten Slice (nicht ausführen)

```text
# PO-CALC-TKP-1D4d-PRE-1 – PO-Fachentscheidung Preisjahr, Draft 2027.1, OA-004, Targeting

Docs-only. Keine Registry-Mutation. Keine DB-Mutation.
Keine Preislisten-Aktivierung. Keine OA-003-Implementierung.
Keine OA-004-Implementierung. Kein Deploy. Keine Runtime-Änderung.

Basis: docs/readiness/PO-CALC-TKP-1D4d-go-no-go-2026-11-09.md
main 8366b824071999ec51f5d1740346b0e45ccb36bf
Urteil dort: NO-GO. oa_tkp × tkp bleibt planned.

Ziel: die vier offenen Fachentscheidungen schriftlich einholen und
festhalten. Keine eigenen Preise. Keine Aktivierung.

Ist, das nicht neu erfunden werden darf:

- OA-Preislisten in dispo_mat_core: nur id 1, Jahr 2027, Version 2027.1,
  Revision 1, status draft, 41 Items, published_at NULL.
  active = 0. Jahr 2026 = 0 Listen.
- Wizard-Defaultjahr: PriceListCalendar::currentYear(), Europe/Berlin.
  Am 09.11.2026 ist das 2026. resolveActive akzeptiert nur status=active.
- Midroll-Draft auf inv_more_kombi_online_audio: list_tkp 80.0000,
  minimum_tkp 20.0000. Dieselbe Inventarzeile: pre_stream 56.0000/14.0000,
  in_stream 44.0000/8.0000. Engine addiert keinen Adserver-TKP.
  oa_adserver_surcharges = 0. is_adserver_relevant der drei Plattformen = false
  und ohne Preiswirkung.
- Targetings: nur dmp (25.0000 / minimum 5.0000, category dmp) und
  geo (15.0000 / minimum 3.0000, category technisch), beide aktiv.
  Kein Freigabedokument.

Vier Entscheidungen, jede mit Ja/Nein und Datum:

1. Braucht der operative Zeitraum eine active Liste 2026?
2. Ist Draft 2027.1 die kaufmännisch freizugebende Liste (ohne sie hier zu aktivieren)?
3. Enthält Midroll-Listen-TKP 80.0000 die 15 EUR Adserver bereits?
   Wenn nein: nächster Technik-Slice ist OA-004, nicht D4d-REL.
   Wenn ja: das als Teilfeststellung dokumentieren, keine neue Preislogik.
4. Sind dmp und geo der freigegebene Zielgruppenkatalog?
   Wenn nein: Katalog bleibt Release-Blocker, bis die Fachdaten benannt sind.

Output: docs/readiness/PO-CALC-TKP-1D4d-PRE-1-po-entscheidung-YYYY-MM-DD.md
plus knapper Statusnachzug in fortschritt.md, backlog-v1.md,
blocker-und-entscheidungslog.md, docs/README.md.

Wenn eine Antwort fehlt: STOP. Nichts aktivieren, nichts schätzen.

Bewusst nicht dieser Slice: D4d-WIRE, D4d-REL, D4d-E2E, OA-003, OA-004-Code.
```
