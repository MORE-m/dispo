# PO-CALC-TKP-OA003-PO – Online-Audio-Festpreis Vertrag

Status: **Akzeptiert / verbindlich für nachfolgende Implementierungsslices**  
Stand: 11. November 2026  
Basis: `origin/main` @ `4f6149576884a29fdfb28a06029129a0fb5c02ae`  
(nach PR #228 / Post-Merge-CI `42996888406` success)  
IDs: `PO-CALC-TKP-OA003-PO`, `OA-003`  
Vorgänger: `docs/readiness/PO-CALC-TKP-OA003-fixed-price-readiness-2026-11-11.md`

### Umsetzungsstand (Nachzug OA003-1 / OA003-2)

- **OA003-1 umgesetzt:** Settlement-Kern `OaTkpFixedPriceSettlement` + Reason
  `oa_tkp_fixed_price` + Assessor für gespeicherte Festpreispositionen (PR #230).
- **OA003-2 umgesetzt:** Backend-Wiring Resolver/Preview/Writer/Persistenz/
  Reload/Normal↔Fixed/Dispo-Snapshot; Preview-Approval-Parity.
- **Weiter OUT:** Wizard-UI (`OaTkpPositionEditor`), Browser-E2E, Release-Closure
  (OA003-3/4). Standardangebote/Budget/Native-Ad/Plattformwahl unverändert OUT.
- `supportsFixedPriceSettlement('oa_tkp')` bleibt **false** (OA-eigener Resolver).
- Registry `oa_tkp×tkp`/`v1` unverändert; kein v2; keine Migration; kein +15.

---

## 1. Entscheidung – technisches Modell

OA-Festpreis ist **kein** eigener Calculation Method und **kein** neues Registry-Pair.

```text
engine_profile_key       = oa_tkp
calculation_method_key   = tkp
algorithm_version        = v1
pricing_settlement_mode  = fixed_price
fixed_price_nn           = <vereinbarter N/N>
```

**Nicht** einführen:

```text
oa_tkp × fixed_price
```

Registry bleibt:

```text
oa_tkp × tkp = released / v1
```

Der allgemeine Registry-Key `fixed_price` wird für OA-003 **nicht** aktiviert
(bleibt `planned`, analog Spot BL-P4-02d).

---

## 2. Algorithmusversion

Festpreis ist **Preisabschluss / Commercial Settlement**, keine neue
TKP-Berechnungsmethode.

- `algorithm_version = v1` bleibt unverändert.
- Kein v2 allein wegen OA-003.
- Historische Normal-TKP-v1-Snapshots dürfen nicht verändert oder neu
  interpretiert werden.
- Unterscheidung normal/fixed eindeutig über  
  `pricing_settlement_mode` + `fixed_price_nn`.

---

## 3. `fixed_price_nn`

Vereinbarter endgültiger **N/N-Endbetrag der OA-Medienposition**.

| Regel | Vorgabe |
|---|---|
| Wert | `> 0` |
| Präzision | Geldbetrag, max. 2 Nachkommastellen |
| Nicht | Listenpreis, Basis-TKP |
| Forward-Rabatte | **keine** auf den Festpreis |

Wechsel zu `pricing_settlement_mode=normal` → `fixed_price_nn = null`.

---

## 4. Effektiver TKP

```text
effective_tkp = fixed_price_nn / impressions × 1.000
```

Rundung gemäß bestehender OA-TKP-Preispräzision (`Decimal::roundPrice` / 4 NK).

Kanonische Speicherung:

```text
final_tkp = effective_tkp
```

Beispiel: 100.000 Imp., 5.000 EUR → `final_tkp = 50.0000`.

---

## 5. `basis_tkp` bei Festpreis

Kein vom Vertrieb gewählter Basis-TKP. Snapshot-Kompatibilität:

```text
basis_tkp = final_tkp = effective_tkp
```

Nur technischer Kompatibilitäts-/Snapshotwert. UI: nicht editierbar, nicht als
„gewählter Basis-TKP“ darstellen.

**Verboten:** `final_tkp − targeting_surcharge` als Pseudo-Basis (kann negativ
werden; fachlich falsch).

---

## 6. Mindest-TKP

Prüfung gegen **effective minimum** (D4c-4b):

```text
effective_minimum_tkp =
  price_list_minimum_tkp
  + Σ minimum_targeting_surcharges

below_minimum ⇔ final_tkp < effective_minimum_tkp
```

Gleichheit ist zulässig. Nicht nur gegen das Preislisten-Grundminimum prüfen.

---

## 7. Targetings

### Verkaufspreis

Ausgewählte Targetings werden **nicht** zusätzlich auf den vereinbarten
N/N-Endbetrag aufgeschlagen. `nn_invest = fixed_price_nn`.

### Snapshots

Targetings bleiben vollständig erhalten (Definition, Code, Name, Kategorie,
`tkp_surcharge_snapshot`, `minimum_tkp_surcharge_snapshot`, Revision).

Listenaufschläge = kaufmännische Referenz.  
Mindestaufschläge wirken weiter auf `effective_minimum_tkp`.

### `targeting_surcharge_total`

Bei `pricing_settlement_mode=fixed_price`:

```text
targeting_surcharge_total = 0.0000
```

Einzel-Snapshots behalten ihre originalen Listenaufschläge. Damit bleibt
`basis_tkp = final_tkp` konsistent.

---

## 8. Referenz-Mediabrutto

Analog Spot-Festpreis:

```text
reference_tkp = list_tkp + Σ targeting_list_surcharges
media_gross   = impressions / 1.000 × reference_tkp
```

Beispiel Midroll + DMP: List 80 + DMP 25 → `reference_tkp = 105`, bei
100.000 Imp. → `media_gross = 10.500`. Festpreis kann unabhängig sein
(`fixed_price_nn = 5.000`, `final_tkp = 50`, `nn_invest = 5.000`).

---

## 9. Rabatte und AE

### Forward-Rabatte

Bei OA-Festpreis **keine** Forward-Rabatte. Positions-/Auftragsrabattbeträge der
Festpreisposition = `0`. Globale Auftragsrabatte wirken weiter auf andere
Normalpreispositionen, **nicht** auf die OA-Festpreisposition.

```text
nn_invest = fixed_price_nn
```

### AE rückwärts (wie Spot)

Wenn `ae_enabled=true` und Position `is_ae_eligible=true`:

```text
net_before_ae = fixed_price_nn / (1 − ae_rate)
ae_amount     = net_before_ae − fixed_price_nn
nn_invest     = fixed_price_nn
```

Beispiel 15 %: 5.000 → net_before_ae 5.882,35 / AE 882,35 / N/N 5.000.  
AE aus oder nicht AE-fähig → `ae_amount = 0`, `net_before_ae = fixed_price_nn`.  
AE-Satz ≥ 100 % fail-closed.

### Payfaktor

```text
effective_pay_factor_percent = fixed_price_nn / media_gross × 100
Gesamtabschlag              = 1 − fixed_price_nn / media_gross
```

Optional „Rabatt vor AE“ aus `net_before_ae`. Keine neue DB-Spalte; vorhandene
Felder (`effective_pay_factor_percent`, …) nutzen.

---

## 10. Sonderfreigabe

### Immer

Jede OA-Festpreisposition erfordert Sonderfreigabe – unabhängig vom
effektiven TKP.

Neuer Reason-Code:

```text
oa_tkp_fixed_price
```

Label: `Online-Audio-Festpreis`

### Under-Min + Festpreis

Wenn zusätzlich `final_tkp < effective_minimum_tkp`:

1. `oa_tkp_fixed_price`
2. `oa_tkp_basis_below_minimum` (bestehender Code, **nicht umbenennen**)

Zwei Reasons speichern. Keine kombinierte Sonderregel.

Hinweis: Der historische Code-Name enthält „basis“; der aktuelle
effective-minimum-Vertrag vergleicht bereits über `final_tkp`.

### Workflow

Bestehende Infrastruktur: Save erlaubt → `requires_special_approval=true` →
Submit `approval_kind=special` → bestehende Rollen/Approval-Services →
Übergabe Disposition. Keine zweite Freigabeengine.

---

## 11. Preislistenpflicht

Aktive passende **Jahres**-OA-Preisliste bleibt Pflicht (Listen-TKP,
Grundminimum, Targeting-Referenz, effective minimum, Referenz-Mediabrutto,
Snapshot, Payfaktor, Pin).

Kein Cross-Year-/Draft-Fallback. Kein „Festpreis braucht keine Preisliste“.

---

## 12. Medien- und Plattformvertrag

Gilt für alle fünf released Core-Medien:

```text
pre_stream
in_stream
pre_stream_influencer
in_stream_influencer
midroll_music_environment
```

Weiter OUT: `native_ad`.  
Keine Endnutzer-Plattformauswahl; Midroll = Spotify/YouTube/Deezer-Bundle;  
OA-005/006 Future.

### OA-004

Interne 15 EUR Adserver-/Einkaufskosten haben auch beim Festpreis **keine**
Verkaufspreiswirkung. Kein `fixed price + 15 TKP`. Kein Befüllen von
`oa_adserver_surcharges` als Verkaufsaufschlag.

---

## 13. Freeze / Dispo / Migration

### Freeze

```text
engine_profile_key = oa_tkp
calculation_method_key = tkp
algorithm_version = v1
pricing_settlement_mode = fixed_price
fixed_price_nn = …
```

Normal-TKP-v1: `pricing_settlement_mode = normal`.

### Dispo

`DispoOrderSnapshotMapper` muss `fixed_price_nn` bei OA durchreichen (heute hart
`null` – Korrektur im Writer/Dispo-Slice). Enthalten: Impressions, Festpreis,
final/effektiver TKP, Listen-TKP, effective minimum, Targeting-Snapshots,
Preislistenreferenz, Sonderfreigabegründe. Keine Live-Neuberechnung.

### Migration

**KEINE Migration erforderlich.** Settlement- und OA-Snapshotfelder existieren.

---

## 14. UI-Vertrag

Im `OaTkpPositionEditor`: Umschalter Normal / Festpreis.

Bei Festpreis:

- Eingabe Festpreis N/N
- Basis-TKP nicht editierbar
- effektiver TKP, Listen-TKP, effective minimum, Referenz-Mediabrutto anzeigen
- permanenter Hinweis:  
  `Online-Audio-Festpreis erfordert eine Sonderfreigabe.`
- Targeting-Auswahl möglich; klar: Listenaufschläge sind Referenz, nicht N/N-additiv

### Wechsel

**Normal → Festpreis:** Imp./Pin/Targetings bleiben; `fixed_price_nn` Pflicht;
manueller Basis-TKP verliert Preiswirkung; Preview erst bei gültigem Festpreis.

**Festpreis → Normal:** `fixed_price_nn = null`; Normal-TKP-Vertrag wieder;
Basis-TKP wieder editierbar/validiert; keine Festpreis-Preiswirkung.

---

## 15. Verbindliche Beispielmatrix

### A – oberhalb Minimum

100.000 Imp., Festpreis 5.000, Min 20, kein Targeting  
→ final 50 / effective min 20 / Under-Min nein / Reason `oa_tkp_fixed_price`

### B – unter Minimum

100.000 Imp., Festpreis 1.500, Min 20  
→ final 15 / Under-Min ja / Reasons `oa_tkp_fixed_price` + `oa_tkp_basis_below_minimum`

### C – Targeting Minimum

100.000 Imp., Festpreis 2.300, Grundmin 20, DMP-Min +5  
→ final 23 / effective min 25 / Under-Min ja / beide Reasons

### D – Targeting Verkauf

100.000 Imp., Festpreis 5.000, List 80, DMP List +25  
→ reference 105 / media_gross 10.500 / nn 5.000 / final 50 / kein +25 auf N/N

### E – AE 15 %

Festpreis 5.000 → net_before_ae 5.882,35 / AE 882,35 / N/N 5.000

---

## 16. Implementierungsslices (nach diesem PO)

| Slice | Inhalt |
|---|---|
| **OA003-1** | Settlement + Approval (Rechenkern, effective TKP/Min, Targetings, AE reverse, Reason, Unit/Feature) |
| **OA003-2** | Writer / Preview / Persistenz / Dispo (Resolver, Save/Update, Snapshot, Mapper `fixed_price_nn`, Reload) |
| **OA003-3** | UI (`OaTkpPositionEditor`, Toggle, Anzeigen, Hinweis, Wechsel) |
| **OA003-4** | E2E / Release (Browser, fünf Medien, Special Approval, Freeze/Dispo, Negativfälle) |

OA003-1 startet **erst nach Merge** dieses Docs-Vertrags und ausdrücklichem
Implementierungsauftrag. Kein automatischer Runtime-Start.

---

## 17. Bewusst nicht in diesem Slice

- Runtime / Registry / Migration / DB / Deploy
- Implementierung OA003-1…4
- Umbau Spot-Festpreis oder SWF-Settlement
- Umbenennung von `oa_tkp_basis_below_minimum`
