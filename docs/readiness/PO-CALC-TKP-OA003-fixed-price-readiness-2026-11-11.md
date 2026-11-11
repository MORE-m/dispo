# PO-CALC-TKP-OA003 – Online-Audio-Festpreis Readiness

Stand: 2026-11-11 (Nachzug PO-Entscheidung)  
Basis: `main` nach Merge PR #228 (`4f6149576884a29fdfb28a06029129a0fb5c02ae`)  
Status: **Readiness abgeschlossen; PO-Vertrag verbindlich**  
PO-Dokument: [`docs/entscheidungen/PO-CALC-TKP-OA003-fixed-price.md`](../entscheidungen/PO-CALC-TKP-OA003-fixed-price.md)

Keine Runtime-Implementierung in diesem Docs-Stand.

---

## 0. Kurzfazit (verbindlich nach PO)

| Thema | Entscheidung |
|---|---|
| Registry-Pair | **Kein** neues Pair `oa_tkp × fixed_price`. Settlement auf bestehendem `oa_tkp × tkp`. |
| Algorithmusversion | Settlement außerhalb des versionierten TKP-Kerns; `algorithm_version` bleibt **`v1`**. |
| `fixed_price_nn` | Vereinbarter **N/N-Endbetrag**. |
| Effektiver TKP | `Festpreis / Impressions × 1.000` → kanonisch in **`final_tkp`**; `basis_tkp = final_tkp`. |
| Minimum | Gegen **effective minimum**. |
| Targeting Verkauf | **Nicht** auf N/N addieren; Snapshots bleiben; `targeting_surcharge_total = 0` bei Festpreis. |
| Referenz-Mediabrutto | `list_tkp + Σ Listenaufschläge` × Imp/1000. |
| AE/Rabatt | Keine Forward-Rabatte; **AE rückwärts** analog Spot. |
| Sonderfreigabe | **Immer**; Reason `oa_tkp_fixed_price`; Under-Min zusätzlich separat. |
| Migration | **NEIN**. |
| Runtime jetzt | **NO-GO** bis ausdrücklicher Slice OA003-1. |

---

## 1. Ist-Zustand (nach D4d)

### Freigegeben

- Registry: `oa_tkp × tkp` = **released / v1** (`EngineProfileRegistry::CATALOG`).
- Fünf Core-Medien: `pre_stream`, `in_stream`, `pre_stream_influencer`, `in_stream_influencer`, `midroll_music_environment`.
- Normal-TKP-Engine: `OaTkpNormalEngine::ALGORITHM_VERSION = v1`.
- Effective-Minimum-Vertrag (D4c-4b):  
  `final_tkp = basis + Σ listen_targeting_surcharges`  
  `effective_minimum = price_list_minimum + Σ minimum_targeting_surcharges`  
  Under-Min → `oa_tkp_basis_below_minimum`.

### Festpreis aktuell OUT

- `OaTkpWriterPositionResolver` setzt hart  
  `pricing_settlement_mode = normal`, `fixed_price_nn = null`.
- `assertNoUnsupportedFields`: jeder andere Settlement-Mode →  
  „Festpreis-Abschluss ist für TKP nicht verfügbar.“
- `CalculationWriter::fillOaTkpPosition` / `positionResultFromOa` persistieren bzw. adaptieren
  ebenfalls Settlement `Normal` / `fixed_price_nn = null`.
- `OaTkpNormalEngine`-Docblock: „Kein Festpreis.“
- `EngineProfileRegistry::supportsFixedPriceSettlement()` freigibt Settlement nur für SWF-
  Längenprofile – **nicht** für `oa_tkp` (Spot Classic läuft über eigenen Settlement-Pfad).
- Wizard: `isTkpPosition` → `OaTkpPositionEditor`;  
  `!isTkpPosition` → `PricingSettlementSection` (Spot/SWF, inkl. `hideFixedPrice` /
  `mediumSupportsFixedPriceSettlement`).
- E2E Release-Smoke erwartet, dass `fixed_price`-Radio für OA **nicht** wählbar ist.
- Blocker-Log (10.11.2026): OA-003 ausdrücklich **Weiter OUT**.

### Spot-Präzedenz (BL-P4-02d)

- Live: `pricing_settlement_mode = fixed_price` auf Basis `average|calendar`.
- Registry-Methode `fixed_price` bleibt **planned** (kein eigener Rechenweg).
- `PricingSettlementMode`-Enum: „Unabhängig von spot_method / calculation_method_key“.
- `fixed_price_nn` = vereinbarter N/N-Endbetrag; keine Forward-Rabatte; AE rückwärts.

---

## 2. Fachvertrag OA-003 (Ausgang)

> Bei Festpreis wird der effektive TKP automatisch berechnet und ebenfalls gegen den Mindest-TKP geprüft. Online-Audio-Festpreis erfordert immer eine Sonderfreigabe.

Formel:

```text
effektiver TKP = Festpreis / Impressions × 1.000
```

Beispiel:

```text
Impressions = 100.000
Festpreis N/N = 5.000 EUR
effektiver TKP = 50 EUR
```

OA-002 (Under-Min bei Normal-TKP) bleibt ein **anderer** Vertrag.

---

## 3. Architektur: Settlement vs. neues Registry-Pair

### Empfehlung

**Settlement-Modus auf bestehendem Pair `oa_tkp × tkp`.**

```text
engine_profile_key       = oa_tkp
calculation_method_key   = tkp
algorithm_version        = v1          (Freeze des TKP-Kerns)
pricing_settlement_mode  = fixed_price
fixed_price_nn           = <N/N>
```

### Begründung

1. Spot/SWF behandeln Festpreis bewusst **nicht** als eigene Berechnungsmethode.
2. `PricingSettlementMode` ist im Code als orthogonal dokumentiert.
3. Ein Pair `oa_tkp × fixed_price` würde Capability-/Wizard-/Freeze-Pfade verdoppeln und dem Spot-Präzedenzfall widersprechen.
4. Registry-Kommentar zu OA: „Normal-TKP v1 freigegeben (OA-003 Festpreis bleibt OUT)“ – OUT bezieht sich auf Funktionalität, nicht auf die Notwendigkeit eines zweiten Pairs.

### Wann ein eigenes Pair doch nötig wäre

Nur wenn Festpreis eine **andere** algorithmische Kernformel mit eigener Versionierung/Release-Steuerung bräuchte (eigene `current_released_version`, eigener Soft-Open). Das ist hier nicht der Fall: Preislisten-/Targeting-Auflösung bleibt identisch; geändert wird der **Abschluss** auf einen vereinbarten N/N.

---

## 4. Algorithmusversionierung

### Variante A – v1 „erweitern“ (Formel ändern)

**Nicht empfohlen**, wenn damit die Normal-TKP-Formel von v1 still verändert würde. v1 ist produktiv eingefroren.

### Variante B – v2

**Nicht empfohlen als Default.**  
Ein Flip von `current_released_version` auf v2 würde neue **Normal-TKP**-Positionen auf v2 einfrieren. Historische v1-Snapshots blieben lesbar, aber der Release-Schnitt wäre unnötig groß, sofern Settlement orthogonal ist.

### Variante C – Settlement außerhalb des versionierten Kerns (**Empfehlung**)

- `OaTkpNormalEngine` v1 bleibt der Normal-Pfad unverändert.
- Festpreis = Settlement-Schicht **oberhalb** Preislisten-/Targeting-Resolution.
- Freeze-Tripel `oa_tkp / tkp / v1` bleibt gültig (Preisauflösung, Targeting-Regeln, Effective-Minimum-Semantik).
- Unterscheidung normal/fixed innerhalb derselben Pair-Version über `pricing_settlement_mode` (+ `fixed_price_nn`).

Das entspricht dem Spot-Modell und verhindert Regression des released Normal-TKP.

---

## 5. Bedeutung von `fixed_price_nn`

### Empfehlung

```text
fixed_price_nn = vereinbarter N/N-Endbetrag
```

Identisch zur Spot-Semantik (BL-P4-02d / Abnahmekatalog).

### Schema-Nachweis

Migration `2026_09_17_200000_add_pricing_settlement_bl_p4_02d.php` legt auf

- `calculation_positions`
- `dispo_order_positions`

bereits an:

- `pricing_settlement_mode` (default `normal`)
- `fixed_price_nn` (nullable, 14,2)

Weitere OA-Felder (`final_tkp`, Targeting-Snapshots, `price_list_minimum_tkp_snapshot`, …) existieren ebenfalls.

**Migration nötig: NEIN** (für den Kernvertrag).  
Hinweis Implementierung: `DispoOrderSnapshotMapper` setzt für OA derzeit hart `fixed_price_nn => null` – das ist **Code**, keine fehlende Spalte.

---

## 6. Berechnungsvertrag / Feldbelegung

### Ableitung

```text
final_tkp (effektiver TKP) = round_price( fixed_price_nn / impressions × 1000 )
nn_invest                  = fixed_price_nn   (Medien-N/N; OA ohne Spotproduktion)
```

### Empfohlene Speicherung

| Feld | Festpreis-Semantik |
|---|---|
| `pricing_settlement_mode` | `fixed_price` |
| `fixed_price_nn` | vereinbarter N/N |
| `impressions` | Eingabe ≥ 1 |
| `final_tkp` | **effektiver TKP** (kanonisch) |
| `basis_tkp` | = `final_tkp` (kein frei gewählter Basis-TKP im Festpreis) |
| `list_tkp_snapshot` | Listen-TKP aus aktiver Jahresliste (Referenz) |
| `price_list_minimum_tkp_snapshot` | Preislisten-Grundminimum |
| `minimum_tkp_snapshot` | **effective minimum** |
| `targeting_surcharge_total` | bei Festpreis **`0.0000`** (Einzel-Snapshots behalten Listenaufschläge) |
| `targeting_minimum_surcharge_total` / Selections | für Effective Minimum |
| `below_minimum_tkp` | `final_tkp < effective_minimum` |
| `media_gross` | Listen-äquivalentes Mediabrutto als Referenz (analog Spot: Basisbrutto vor Settlement) |
| `nn_invest` | = `fixed_price_nn` (+ 0 Produktion) |
| Rabattbeträge Forward | 0 |
| `ae_amount` | rückwärts aus Fest-N/N, wenn AE aktiv/eligible |

Keine doppelte „zweite Ergebnisstruktur“: bestehende `method_result.tkp`-Felder (`final_tkp`, `minimum_tkp`, `below_minimum_tkp`, …) erweitern um Settlement-Felder (`pricing_settlement_mode`, `fixed_price_nn`, `effective_tkp` Alias = `final_tkp`).

---

## 7. Mindest-TKP – PO-Entscheidung

### Frage

Prüfen gegen Preislisten-Grundminimum **oder** effective minimum?

### Empfehlung (explizit zur PO-Bestätigung)

**Gegen effective minimum** – konsistent zu D4c-4b / Normal-TKP / `SpecialApprovalAssessor`.

Beispiel C:

```text
Grundminimum 20 + DMP-Min 5 = effective minimum 25
Festpreis 2.300 / 100.000 × 1.000 = 23
→ under effective minimum → below_minimum_tkp = true
```

---

## 8. Targetings bei Festpreis

### Empfehlung

| Aspekt | Verhalten |
|---|---|
| Verkaufsaufschlag auf Endbetrag | **Nein** – `fixed_price_nn` bleibt finaler N/N |
| Speichern / Versionieren / Dispo | **Ja** |
| Effective Minimum | **Ja** – Mindestaufschläge zählen |
| Listenaufschläge | Snapshot/Transparenz; nicht additiv auf N/N |

Begründung: Fachvertrag definiert Festpreis und leitet daraus den effektiven TKP ab. Eine nachträgliche Aufschlagsaddition würde den vereinbarten N/N brechen.

---

## 9. Sonderfreigabe – immer

### Vertrag

OA-Festpreis erfordert **immer** Sonderfreigabe – auch wenn `final_tkp >= effective_minimum`.

OA-002 (`oa_tkp_basis_below_minimum`) darf **nicht** allein den Festpreis abdecken.

### Empfohlener Reason-Code

```text
oa_tkp_fixed_price
```

Label sinngemäß: „Online-Audio-Festpreis erfordert Sonderfreigabe“.

### Matrix

| Fall | Gründe |
|---|---|
| Normal-TKP ≥ effective min | keine OA-Sonderfreigabe (Rabattgrenzen unberührt) |
| Normal-TKP < effective min | `oa_tkp_basis_below_minimum` (OA-002) |
| Festpreis ≥ effective min | `oa_tkp_fixed_price` (OA-003) |
| Festpreis < effective min | **`oa_tkp_fixed_price` + `oa_tkp_basis_below_minimum`** |

### Empfehlung zwei Codes

Zwei Reason Codes statt eines kombinierten Codes: Audit/UI/Filter bleiben klar; bestehende Under-Min-Logik bleibt wiederverwendbar.

### Workflow

Bestehende Infrastruktur nutzen (`requires_special_approval`, Submit `approval_kind=special`, Assessor, Dispo-Snapshot, Reassessment). Keine parallele Freigabelogik.

Anker: `SpecialApprovalAssessor`, `SpecialApprovalReasonCode`, Persistenz in CalculationWriter / OaTkpCalculationPersister / DispoOrderSnapshotMapper.

---

## 10. Rabatt / AE

### Empfehlung (mit Beispiel)

Analog Spot-Festpreis:

1. **Forward-Rabatte** auf Fest-N/N: **aus** (keine Reduktion von 5.000).
2. **AE rückwärts**, wenn `ae_enabled ∧ is_ae_eligible`:

```text
fixed_price_nn = 5.000,00
ae_percent     = 15
net_before_ae  = 5.000 / (1 − 0,15) = 5.882,35
ae_amount      = 882,35
nn_invest      = 5.000,00   (unverändert)
```

3. Payfaktor / effektiver Abschlag rückwärts aus Listen-`media_gross` → Fest-N/N (Transparenz), ohne N/N zu ändern.

OA besitzt bereits `is_discountable` / `is_ae_eligible` Flags am Medium – Festpreis muss diese für Forward-Rabatte ignorieren, für AE-Rückrechnung respektieren.

---

## 11. Preislistenpflicht

### Empfehlung

Aktive passende **Jahres**-OA-Preisliste bleibt **Pflicht** für:

- Listen-TKP-Referenz
- Grundminimum / effective minimum
- Pin (`oa_tkp_price_list_id/version/year/revision`)
- Targeting-Revisionen

Kein Cross-Year-/Draft-Fallback (bestehender Pin-/Resolver-Vertrag).

Beweisbedarf in Tests: Store/Preview ohne aktive Liste → fail-closed (wie Normal-TKP).

---

## 12. Wizard / UI – Mindestscope

Aktuell:

```text
isTkpPosition → OaTkpPositionEditor
!isTkpPosition → PricingSettlementSection
```

OA-Festpreis **nicht** durch bloßes Einblenden der Spot-`PricingSettlementSection` aktivieren.

### Minimaler UX-Scope (nicht implementieren)

Im `OaTkpPositionEditor` (oder eng gekoppelter Teilkomponente):

1. Settlement-Umschalter Normal ↔ Festpreis
2. Eingabe `fixed_price_nn` (wenn Festpreis)
3. Anzeige berechneter effektiver TKP (`final_tkp`)
4. Anzeige Listen-TKP + effective minimum (+ optional Grundminimum)
5. Permanenter Hinweis „Sonderfreigabe erforderlich“
6. Under-Min-Hinweis zusätzlich, wenn `below_minimum_tkp`
7. Basis-TKP-Eingabe im Festpreis-Modus ausblenden/deaktivieren

Optional: gemeinsame primitive Controls aus Spot wiederverwenden; **nicht** die komplette Spot-Section.

---

## 13. Preview

Empfohlene Preview-Erweiterung der bestehenden `method_result.tkp`-Struktur:

```text
pricing_settlement_mode = fixed_price
fixed_price_nn = 5000.00
impressions = 100000
final_tkp / effective_tkp = 50.0000
minimum_tkp (effective) = 25.0000
price_list_minimum_tkp = 20.0000
below_minimum_tkp = false|true
requires_special_approval = true
special_approval_reasons = [oa_tkp_fixed_price, …]
```

Keine zweite parallele Ergebniswurzel.

---

## 14. Writer / Engine – Implementierungsschnitt (Vorschlag)

### Empfohlene Möglichkeit 2 (+ klare Grenze zu 1)

**`OaTkpFixedPriceSettlement`-Service** oberhalb der bestehenden Preis-/Targeting-Resolution:

1. Writer-Resolver lässt `fixed_price` zu (Guard öffnen), validiert `fixed_price_nn > 0`, Impressions, Pin.
2. Preislisten + Targetings weiter über bestehenden Resolver/`OaTkpPriceResolver`.
3. Settlement-Service berechnet `final_tkp`, Commercial (kein Forward-Rabatt, AE reverse), setzt Flags.
4. `OaTkpNormalEngine` bleibt für `pricing_settlement_mode=normal` unverändert (Regressionschutz v1).

Möglichkeit 1 (Engine erweitern) nur, wenn Settlement-Zweig strikt isoliert und Normal-Pfad bitgleich bleibt – höheres Regressionsrisiko.

### Verhindern

- Duplizierte Preislistenauflösung
- Doppelte Targeting-Snapshot-Pflege
- Normal-TKP-Regression

---

## 15. Freeze / Historie

Historisch rekonstruierbar über:

```text
engine_profile_key, calculation_method_key, algorithm_version
pricing_settlement_mode, fixed_price_nn
impressions, basis_tkp, list_tkp_snapshot
price_list_minimum_tkp_snapshot, minimum_tkp_snapshot, final_tkp
targeting selections/snapshots, oa_tkp_price_list_* Pin
below_minimum_tkp, requires_special_approval / reasons
```

Nach Preislistenänderung: Pin hält; **keine** Live-Neuberechnung historischer Festpreise.

---

## 16. Dispo Snapshot

Bereits vorhanden u. a.: Impressions, TKP-Snapshots, Targetings, OA-Preislisten-Pin, `pricing_settlement_mode`, Freeze-Felder, Sonderfreigabe am Auftrag.

**Lücke (Code):** OA-Zweig setzt `fixed_price_nn` derzeit auf `null`.  
**Empfehlung:** aus Position durchreichen – ohne Schema-Migration.

Zusätzlich prüfen: Anzeige Sonderfreigabegründe inkl. neuem Code; optional Grundminimum nur wenn Dispo es braucht (Calc hat `price_list_minimum_tkp_snapshot`; Dispo mappt heute die effektive Schwelle).

---

## 17. Standardangebote / Budget

Bestehende Guards greifen über `is_oa_tkp` / TKP-Erkennung:

- Budgetvorschlag: fail-closed bei TKP-Positionen  
  (`OaTkpHttpReleaseGuard::assertBudgetNotTkp`, u. a. aus `BudgetSpotProposalService` /
  `CalculationWriter`) – unabhängig vom Settlement-Mode.
- Standardangebote: fail-closed (`StandardOfferMaterializer` bei `is_oa_tkp`).

Da Festpreis weiterhin `is_oa_tkp = true` / Pair `oa_tkp×tkp` bleibt: **keine neue Sonderlogik** nötig, sofern Marker nicht entfällt.

---

## 18. Unveränderte OUT-Verträge

- `native_ad` OUT  
- keine Endnutzer-Plattformauswahl  
- OA-005/006 Future  
- Midroll = Spotify/YouTube/Deezer Bundle  
- interne 15 EUR ohne Verkaufspreiswirkung  
- OA-004 unverändert  

OA-003 darf diese Verträge nicht öffnen.

---

## 19. Medienumfang

### Empfehlung

Festpreis für **alle fünf** released Core-Medien, sofern Fachvertrag keinen Ausschluss nennt.

Ausdrücklich: kein Medium-Sonderpfad; Berechtigung weiter über Assignment `oa_tkp×tkp`.

---

## 20. Validierung (Soll)

```text
pricing_settlement_mode ∈ {normal, fixed_price}
bei fixed_price:
  fixed_price_nn > 0, ≤ 2 NK (wie Spot-Parser)
  impressions ≥ 1 (Ganzzahl)
  aktive passende Jahres-Preisliste / Pin-Vertrag
  final_tkp = round_price(fixed_price_nn / impressions × 1000)
  Sonderfreigabe immer
bei normal:
  fixed_price_nn = null (Wechsel fixed→normal leert Feld)
```

Rundung: bestehende `Decimal::roundPrice` / OA-Import-Decimal-Konvention (4 NK TKP).  
Update historischer Positionen: Freeze-Version muss ausführbar bleiben (`v1`); Settlement-Mode wechselbar nach bestehendem Spot-Muster, ohne stilles Rebind (Pin-Vertrag).

---

## 21. Beispielmatrix

### Fall A

100.000 Imp., Festpreis 5.000, kein Targeting, Min 20  
→ effective TKP **50** ≥ 20 → trotzdem **OA-003** Sonderfreigabe.

### Fall B

100.000 Imp., Festpreis 1.500, Min 20  
→ effective TKP **15** < 20 → `oa_tkp_fixed_price` + `oa_tkp_basis_below_minimum`.

### Fall C

100.000 Imp., Festpreis 2.300, Min 20, DMP-Min +5  
→ effective TKP **23**, effective min **25** → under min + Festpreis-Sonderfreigabe.

### Fall D

100.000 Imp., Festpreis 5.000, DMP Verkauf +25  
→ N/N bleibt **5.000**; Verkaufsaufschlag **nicht** addiert; Aufschlag im Snapshot; Min-Aufschlag zählt für effective min.

### Fall E (AE 15 %)

Festpreis 5.000, AE eligible  
→ `nn_invest=5000`, `ae_amount=882.35`, `net_before_ae=5882.35` (Spot-Analog).

---

## 22. Teststrategie (später)

### Unit

- Festpreis → effective TKP / Rundung  
- effective minimum / Targeting  
- AE reverse / keine Forward-Rabatte  
- Assessor: immer fixed_price-Reason; Kombi mit Under-Min  

### Feature

- Preview / Store / Update / Reload  
- Sonderfreigabe-Flag  
- Dispo-Snapshot inkl. `fixed_price_nn`  
- Freeze / Year-Pin  
- Budget & Standardangebot weiterhin blocked  

### Negative

- null/0 Festpreis, 0 Impressions  
- fehlende aktive Jahresliste  
- Native-Ad, Plattformauswahl, Budget, Standardangebot  

### Browser

- Normal ↔ Festpreis  
- Eingabe Festpreis, sichtbarer effektiver TKP  
- Save/Reload  
- Sonderfreigabehinweis  

---

## 23. Migrationsbedarf

```text
Migration nötig: NEIN
```

Begründung: `pricing_settlement_mode` / `fixed_price_nn` und OA-TKP-Snapshot-Spalten existieren auf Calc- und Dispo-Positionen. Reason-Code ist PHP-Enum/JSON, kein Schema. Einzige „Lücke“ ist Mapper-/Resolver-Code.

---

## 24. Implementierungsslices

1. **OA003-PO** – **erledigt** (`docs/entscheidungen/PO-CALC-TKP-OA003-fixed-price.md`).  
2. **OA003-1** – Settlement + Approval.  
3. **OA003-2** – Writer / Preview / Persistenz / Dispo.  
4. **OA003-3** – UI.  
5. **OA003-4** – E2E / Release.

Details und Reihenfolge: PO-Entscheidungsdokument §16.

---

## 25. PO-Entscheidungen

**Keine offenen OA-003-Grundsatzentscheidungen mehr.**  
Alle Punkte aus der Readiness-Analyse sind in  
`docs/entscheidungen/PO-CALC-TKP-OA003-fixed-price.md` verbindlich festgeschrieben.

---

## 26. GO / NO-GO

| Ebene | Status |
|---|---|
| Readiness | **abgeschlossen** |
| PO-Vertrag | **akzeptiert / verbindlich** |
| Runtime-Implementierung | **NO-GO** bis ausdrücklicher Auftrag OA003-1 |
| Registry-Änderung jetzt | **NEIN** |
| DB-Mutation / Deploy | **NEIN** |

---

## Referenzen (Code/Doku)

- `app/Support/Calculation/EngineProfileRegistry.php` – `oa_tkp×tkp` released/v1;  
  `supportsFixedPriceSettlement` nur SWF  
- `app/Enums/PricingSettlementMode.php` – Settlement orthogonal  
- `app/Services/Calculation/OaTkp/OaTkpNormalEngine.php` – v1 Normalformel  
- `app/Services/Calculation/OaTkp/OaTkpWriterPositionResolver.php` – Festpreis derzeit gesperrt  
- `app/Services/Calculation/CalculationWriter.php` – `fillOaTkpPosition` / `positionResultFromOa`  
- `app/Support/Calculation/OaTkpHttpReleaseGuard.php` – `assertBudgetNotTkp`  
- `app/Services/Calculation/SpecialApprovalAssessor.php` / `SpecialApprovalReasonCode`  
- `app/Services/Calculation/CalculationEngine.php` – Spot `deriveFixedPriceAe`  
- `app/Services/DispoOrder/DispoOrderSnapshotMapper.php` – OA `fixed_price_nn => null`  
- `resources/js/pages/calculations/wizard.tsx` – `isTkpPosition` Gate  
- `docs/test-und-abnahmekatalog.md` – BL-P4-02d Spot-Festpreis  
- `docs/blocker-und-entscheidungslog.md` – D4d Abschluss; OA003-PO  
- `docs/anforderungskatalog.md` / `docs/berechnungslogik.md` – OA-003 Fachtext  
- **Verbindlich:** `docs/entscheidungen/PO-CALC-TKP-OA003-fixed-price.md`  
