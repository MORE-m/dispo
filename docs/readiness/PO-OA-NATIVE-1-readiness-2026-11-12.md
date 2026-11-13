# PO-OA-NATIVE-1 – Native-Ad Readiness

| Feld | Wert |
|---|---|
| Status | **NATIVE-1 Foundation + NATIVE-2 Writer/HTTP/Persistenz implementiert** – Native-Ad weiterhin **nicht** operativ buchbar; Pair **nicht** released (CATALOG `planned`) |
| Stand | 13. November 2026 (NATIVE-2 Writer/HTTP/Persistenz; Base NATIVE-1 `4a2ba72`) |
| Slice | `PO-OA-NATIVE-1` / `NATIVE-1` |
| Branch | `feat/free-position-native-1-foundation` |
| Base | `8005d2f670b909084d526f1e81471e936bd277bd` (`main` nach #235 PO-STD-V2) |
| Worktree | `dispo-wt-free-position-native-1` |
| Explizit nicht | Bookability, Wizard UI, Podcast-Rule, Registry-Release, Migration, Deploy, `dispo_mat_core`-Write |
| NATIVE-2 Branch | `feat/free-position-native-2-writer` (Worktree `dispo-wt-free-position-native-2`) |
| NATIVE-3 Branch | `feat/free-position-native-3-ui` (Worktree `dispo-wt-free-position-native-3`, Base `5a1358f`) |
| NATIVE-4 Branch | `feat/free-position-native-4-dispo` (Worktree `dispo-wt-free-position-native-4`, Base `6fe81e3`) |
| NATIVE-4 Status | **Dispo-Classifier/Snapshot (Freeze) implementiert**; Free AND-only klassifiziert, explizites Mapper-`match`, Anzeige „Freie Preisposition“; Pair weiter **planned/unreleased**; Browser-Smoke Port 8080; **kein** Release/Podcast-Rule/Deploy/`dispo_mat_core`-Write/Migration |
| NATIVE-3 Status | **Wizard-UI implementiert** (Preis, Rabatte, AE-Header-Toggle); Pair weiter **planned/unreleased**; isolierter Browser-Smoke (Port 8079) mit Registry-Test-Fake nur im E2E-Server; **kein** Release/Podcast-Rule/Deploy/`dispo_mat_core`-Write/StandardOffer/Budget |
| NATIVE-2 Umfang | `FreePositionWriterPositionResolver`, `FreePositionHttpReleaseGuard`, `CalculationWriter`-Dispatch/Totals/Persistenz/Reload, Request-Validierung `free_price`, Registry-Test-Fake, Budget-Guard; **kein** CATALOG-Flip, **kein** StandardOffer-Öffnen, **kein** Budget-Free, **keine** Migration/UI/Podcast-Rule |

---

## 0. Kurzfazit

Native-Ad ist fachlich einfach:

> Werbemittel mit **freier Preiseingabe**. Der User trägt den Preis selbst ein.
> Auf diesen Preis können anschließend die **normalen Rabatte** (und ggf. normale AE)
> angewendet werden.

Vergleichsmodell: **Event / Off-Air / freie Preisposition** (`free_position`).

```text
Produktart     = free_position
Userpreis      = Ausgangspreis der Position (nicht N/N-Endpreis, kein TKP, keine Liste)
Rabatt         = normal zulässig, gesteuert über is_discountable
AE             = allgemeine Forward-Logik gemäß is_ae_eligible (kein OA003-Rückwärts)
Länge          = keine
TKP / OA-TKP   = nein (Guards bleiben CLOSED)
OA-Targeting   = OUT
Sonderfreigabe = nur allgemeine bestehende Regeln (z. B. Rabattgrenze)
Buchbarkeit    = InventoryMediumRule; V1 = 4 bestehende + Podcast (= 5)
Einplanung     = redaktion (initial); admin-änderbar
MORE-Kombi OA  = native_ad nicht buchbar (Konfiguration)
```

**Runtime weiterhin NO-GO**, bis `free_position` als generisches Registry-/Engine-Pair
umgesetzt und verdrahtet ist. Keine Native-Ad-Sonderengine.

---

## 1. Verbindlicher Fachvertrag (entschieden)

| Thema | Vertrag |
|---|---|
| Produktart | `free_position` (Freie Preisposition) |
| Fachliches Vorbild | Event / Off-Air / freie Preisposition |
| Preisquelle | User-Eingabe |
| Preissemantik | Userpreis = **Ausgangspreis** der Position → normale Rabatte → ggf. AE → N/N |
| Nicht | OA-TKP · listenbasierter Festpreis · N/N-Festpreis-Settlement (`fixed_price_nn`) |
| Rabatt | **zulässig**; Positions-/Auftragsrabatt; persönliche Limits; bestehende Approval bei Überschreitung; Steuerung `is_discountable` |
| AE | allgemeine Logik + `is_ae_eligible`; **keine** OA003-Rückwärtslogik |
| Länge | nicht erforderlich; kein Default-30-Vertrag; keine Sekundenpreislogik |
| Menge preisbildend | **nein** (kein Menge×Preis, kein Impressions×TKP als Native-Formel) |
| TKP / Minimum / +15 | nein |
| OA-Targetings | OUT |
| Sonderfreigabe wegen freier Eingabe | **nein** (kein Reason `native_ad_free_price`) |
| Matrix V1 | 5 Kombinationen (4 + Podcast); MORE-Kombi Online Audio OUT |
| Einplanung initial | `planning_responsibility_key = redaktion` |
| Buchbarkeit | nur `InventoryMediumRule` – keine Hardcode-Inventarliste |

---

## 2. Repository-Ist: `free_position`

| Aspekt | Ist |
|---|---|
| Calculation Method | geseedet: Key `free_position`, Name „Freie Preisposition“ (Migration `2026_09_09_160000_…`) |
| Engine Profile Pair | **fehlt** – `EngineProfileRegistry::pairsForMethodKey('free_position')` = `[]` |
| `CalculationKind` | **kein** `free_position`-Kind |
| Bookability | `kind=null` + non-OA-Core → fail-closed |
| Writer/Engine | kein Free-Position-Pfad |
| Docs | BL-P2-02: geseedet, kein Engine-Paar, nicht rechenfähig |

---

## 3. Empfohlene generische Architektur

Ziel: **generischer Free-Position-Vertrag**, Scope der ersten Freischaltung = Native-Ad.
Architektur so, dass Event/`off_air` später dasselbe Pair nutzen können – **ohne**
gleichzeitige Freischaltung.

### A – Engine Profile Key

```text
engine_profile_key = free_position
```

Nicht `native_ad`, nicht `oa_tkp`.

### B – Registry Pair + Version

```text
free_position × free_position
pair_status = released   (erst im Freigabe-Slice)
current_released_version = v1
versions.v1 = released
algorithm_version (Freeze) = v1
```

Zusätzlich nötig (Code, nicht Schema):

- `CalculationKind::FreePosition = 'free_position'`
- Mapping in `CalculationMethodFreezeDescriptor`, `AdvertisingMediumLiveBookability`
- Kind↔Kategorie-Kompatibilität mindestens `online_audio` (Native); optional vorbereiten `events_promotion` (Event/Off-Air) **ohne** Freischaltung
- Medium-Override-Assignment `free_position`/`free_position` für Native (nicht Kategorie-global erzwingen)

### C – Preis-Persistenz

```text
Userpreis → media_gross
pricing_settlement_mode = normal
fixed_price_nn = null
```

Danach vorhandene Forward-Pipeline (wie Spot Classic Normal):

```text
media_gross
→ Positionsrabatte
→ Auftragsrabatt
→ AE (falls eligible)
→ nn_invest
```

**Nicht** `pricing_settlement_mode=fixed_price` / `fixed_price_nn`.

Beispiel (fachlich):

```text
Userpreis 5.000
Positionsrabatt 10 % → 4.500
weitere Auftrags-/AE-Logik → allgemeiner Kalkulationsvertrag
```

### D – Rabatt-Pipeline (Wiederverwendung)

| Baustein | Klasse / Ort |
|---|---|
| Validierung | `DiscountValidator` |
| Persistenz Pos-/Auftragsrabatte | `CalculationWriter` (`syncPositionDiscounts` / `syncOrderDiscounts`) |
| Rechnung | `CalculationEngine::applyDiscountSequence` (bzw. shared Sequenz im Free-Engine) |
| Flags | Snapshot `is_discountable` aus Medium/Rule (wie bestehende Medien) |

Keine Native-Sonderformel. Writer darf Rabattfähigkeit **nicht** hardcoden.

### E – AE-Pipeline (Wiederverwendung)

Forward-AE wie Spot Normal (`finalizePosition`-Logik):  
AE vom Betrag nach Rabatten, wenn `is_ae_eligible` und AE aktiv.

**Nicht** `deriveFixedPriceAe` / OA003-Rückwärts.

Flags aus Medium/Rule (`is_ae_eligible`), konfigurierbar.

### F – Approval-Pipeline (Wiederverwendung)

`SpecialApprovalAssessor` + bestehende Reason Codes:

- `position_discount_exceeds_personal_limit`
- `order_discount_exceeds_personal_limit`
- `effective_discount_exceeds_personal_limit`

Limit: `personal_discount_limit_percent` / User-Rabattgrenze.

**Kein** neuer Reason nur wegen manueller Preiseingabe.

### G – Migration

```text
Schema-Migration: NEIN (Favorit)
```

Vorhanden und ausreichend für Kernvertrag:

- `media_gross`, Rabatt-/AE-Beträge und -Prozente
- `is_discountable`, `is_ae_eligible`
- Discount-Kind-Tabellen
- Freeze-Vierfach (`engine_profile_key`, `calculation_method_key`, `calculation_method_name`, `algorithm_version`)
- `pricing_settlement_mode` / `fixed_price_nn` (für Native: normal / null)

`length_seconds`: Spalte NOT NULL ohne Default → technisch Sentinel **`0`**
(wie OA-TKP), **kein** UI-Pflichtfeld, keine Längenvalidierung.

Feature-Slices brauchen Code (Registry, Kind, Writer, Bookability, Dispo-Classifier) –
das ist **keine** Preislisten-/Daten-Migration.

### H – UI-Minimalvertrag

Preisrelevant:

1. Werbemittel `native_ad`
2. Inventar (über aktive Rule buchbar)
3. manuell eingegebener Preis → `media_gross`

Zusätzlich allgemeine Calc-UI (soweit schon vorhanden): Positions-/Auftragsrabatte, AE.

**Nicht** als Native-Pflicht erfinden: Länge, TKP, Mindest-TKP, Plattformallokation,
OA-Targeting-Aufschläge.

Operative Dispo-Felder (URL, Reporting, …) über bestehendes Dynamic-Field-/Dispo-System,
separat vom Preiskern.

### I – Dispo-Freeze

Geld-/Rabattfelder in `DispoOrderSnapshotMapper` sind bereits aus gespeicherten
Positionswerten (keine Live-Neuberechnung) – Muster wiederverwenden.

Anpassungsbedarf (Code):

- `DispoPositionMethodClassifier` muss `free_position×free_position` kennen
- Freeze-Legacy/`SpotCalculationMethod`-Pfad nicht für Free-Position missbrauchen
- Snapshot: `media_gross`, Rabatte, AE, `nn_invest`, `pricing_settlement_mode=normal`,
  `length_seconds=0`, Freeze-Triple, Medium/Inventar

### J – Podcast-Rule (Daten, später)

```text
Inventar: inv_more_kombi_podcast (MORE-Kombi Podcast)
Medium:   native_ad
is_active: true
planning_responsibility_key: redaktion
```

4 bestehende Rules unverändert.  
MORE-Kombi Online Audio: bewusst **keine** Rule / nicht buchbar.  
Nur über `InventoryMediumRule` – kein Engine-Hardcode.

**In diesem Slice: Rule nicht anlegen.**

---

## 4. OA-TKP bleibt unverändert

| Thema | Vertrag |
|---|---|
| `OaTkpCoreMedia` | Native **nicht** aufnehmen |
| Registry | `oa_tkp × tkp = released/v1`, fünf Core-Medien |
| Preislisten / Min / Targeting-Surcharges / +15 | keine Native-Wirkung |
| bestehende Native-Rejects in Resolver/Import/Activation/… | **korrekt beibehalten** |

---

## 5. Matrix / Einplanung (entschieden)

| Inventar | V1 |
|---|---|
| Radio Hamburg, ROCK ANTENNE, 80er 90er OLDIE, CARAVAN.fm | bestehende 4 Rules **behalten** |
| MORE-Kombi Podcast | **fehlende Rule → später anlegen** (`redaktion`, active) |
| MORE-Kombi Online Audio | **bewusst nicht buchbar** |

Excel historisch 6 Zellen = Beleg, nicht V1-Soll (Soll = 5).

---

## 6. Event / Off-Air (Architekturhinweis)

`off_air` = Kategorie `events_promotion`, `kind=null`.  
Anforderung EVT-001: fixe/freie Preispositionen.

**Empfehlung:** dasselbe Pair `free_position×free_position` später wiederverwenden.  
**Aktueller Implementierungsscope:** nur Native-Ad freischalten – Event/Off-Air nicht
ungefragt öffnen.

---

## 7. Implementierungsslices (nach dieser Readiness)

| Slice | Inhalt |
|---|---|
| **NATIVE-PO** (Docs, dieser Stand) | Fachvertrag geschlossen; technische Readiness |
| **NATIVE-1** | Registry Pair `free_position×free_position` v1 + `CalculationKind` + Bookability/Freeze-Mapping + Medium-Assignment Native |
| **NATIVE-2** | Writer/Preview/Engine: Userpreis → `media_gross` + Forward-Rabatt/AE + Approval-Reuse; `length_seconds=0` — **umgesetzt 13.11.2026** (Pair weiter planned; Tests via Registry-Fake) |
| **NATIVE-3** | Wizard-UI Minimal (Preis + Rabatte/AE) — **umgesetzt 13.11.2026** (Pair weiter planned; Browser-Smoke via E2E-Fake) |
| **NATIVE-4** | Dispo-Classifier/Snapshot + Reload/Copy-Regression — **Dispo-Freeze umgesetzt 13.11.2026** (Pair weiter planned; Podcast OUT; Revisions-/Copy-Pfad nutzt denselben Writer, nicht gesondert getestet) |
| **NATIVE-5** | Stammdaten: Podcast-Rule; E2E; OA-TKP-Regression (5 Core unverändert) |

OA-Budget und Standardangebote bleiben OUT/V2.

---

## 8. GO / NO-GO

| Frage | Ergebnis |
|---|---|
| Fachvertrag Produktart/Rabatt/Länge/TKP/Matrix? | **entschieden** |
| Runtime Native-Ad freischalten? | **NO-GO** |
| Blocker | fehlendes Registry-/Engine-/Writer-Pair `free_position`; Kind/Bookability; UI; Dispo-Classifier; Podcast-Rule-Daten |

---

## 9. Verbleibende technische (nicht fachliche) Punkte vor Coding

1. KindCategoryCompatibility: welche Kategorien dürfen `free_position` tragen (Native jetzt; Events vorbereiten)?  
2. Exact Payload-Feldname für Userpreis im Writer (→ `media_gross`).  
3. Ob Category-Assignment jemals für `events_promotion` gesetzt wird (später) vs. nur Medium-Override.  

Keine erneute Produktart-/N/N-vs-Brutto-Diskussion: **Ausgangspreis + Forward** ist verbindlich.

---

## 10. Referenzen

- Migration `database/migrations/2026_09_09_160000_create_calculation_method_foundation_tables.php`
- `app/Support/Calculation/EngineProfileRegistry.php`
- `app/Services/Calculation/CalculationEngine.php` (`applyDiscountSequence`, `finalizePosition`)
- `app/Services/Calculation/SpecialApprovalAssessor.php`
- `app/Services/DispoOrder/DispoOrderSnapshotMapper.php`
- `app/Support/Advertising/AdvertisingMediumLiveBookability.php`
- `docs/readiness/BL-P2-02-mat-kombinationstabelle-operativ-2026-09-29.md` (§ free_position)
- `docs/anforderungskatalog.md` EVT-001
- `dispo_mat_core` read-only (Native Rules / Matrix)
