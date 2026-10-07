# PO-BLP501A-1 – SWF Trailer × Durchschnitt (inventarübergreifend)

Status: **Entschieden** (**A1 ACCEPTED** + **B1 ACCEPTED**) · Implementierung `BL-P5-01a` im Draft-PR; **kein** Merge/Deploy
Stand: 7. Oktober 2026
Basis: `origin/main` @ `0ff11aaeb8df4ccddd0688cdeb86a551e24b9614`
(Merge PR #128; Post-Merge-CI [37611870573](https://github.com/MORE-m/dispo/actions/runs/37611870573) SUCCESS; **kein** Deploy)
IDs: `SWF-001`–`SWF-005`, `SWF-008` (nur Abgrenzung), `SPT-016` (Abgrenzung),
`PRI-002`/`PRI-004`/`PRI-005`/`PO-PRI-YEAR-1`/`PO-PRI-HOURS-1`, `COM-001`–`COM-008`,
`MAT-001`–`MAT-003`, `ADV-001` (nur Methoden-Zuordnung Kategorie), `ADV-003` (Abgrenzung),
`CAL-001`/`CAL-005`, `AT-05`, `ADM-003`
Vorgeschlagene Slice-Kennung: **`BL-P5-01a`** (Teilscope von `BL-P5-01`, nicht vollständig)
Readiness: [`docs/readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md`](../readiness/BL-P5-01a-swf-trailer-average-2026-10-07.md)
Arbeitsmodus: Implementierung im Draft-PR (Branch `feat/bl-p5-01a-swf-trailer-average`); **kein** Merge/Deploy; Daten-Vorbedingungen je Inventar bleiben bestehen

> **A1** und **B1** sind fachlich bestätigt (7. Oktober 2026). UX-GATE-C-**Teilfreigabe A1**
> gilt nur für Trailer × Durchschnitt. Keine Deployment-Freigabe. Historische
> Scope-Entscheidungen (Spot Classic, Settlement-`fixed_price`, Matrix-Importvertrag, Katalog)
> bleiben unverändert.

## Kurzfazit

| Thema | Klassifikation | Handlungsbedarf |
|---|---|---|
| A Scope Trailer × Average, inventarübergreifend | **entschieden (A1)** | UX-GATE-C Teilfreigabe Trailer × Durchschnitt |
| B Preisbasis Sekundenpreise | **entschieden (B1)** | Spot-Grundpreise + inventarspezifischer Trailer-Aufschlag |
| C Längen/Aufschläge außerhalb RHH | **Datenlieferung** | fail-closed bis Admin-Daten je Inventar (kein Fallback) |
| Rabatt/AE/Freigabe/Pin/Jahr | **entschieden** (bestehende Verträge) | unverändert übernehmen |
| Calendar / Festpreis / CityLife / weitere SWF | **V1-Rest** | außerhalb dieses Teilslices |
| Produktion (BL-P5-02) | **abhängig** | **keine** Freigabe durch diesen Slice |
| Standardangebote / Budget | **pausiert** | bewusst nicht |

## Verbindlicher Ist-Rahmen (nicht zur Wahl)

1. **Main:** PR #128 gemergt (`0ff11aa…`); Post-Merge-CI `37611870573` SUCCESS (`ci`/`mysql`/`e2e-spt008`). **Kein** Deploy.
2. Spot Classic Average/Calendar inkl. Komponenten, Settlement-Festpreis, Tandem/Tridem auf `main`.
3. Katalog: Medium `trailer_station_voice` / Name `Trailer/Vorpr. Element Station Voice`, Kategorie `special_advertising_formats`, `kind=null` → derzeit **nicht** live buchbar.
4. Matrix (PO-MAT-CORE-MATRIX-1 / Workbook): Trailer **zulässig nur** auf
   Radio Hamburg, ROCK ANTENNE Hamburg, 80er 90er OLDIE ANTENNE Hamburg, CARAVAN.fm.
   Übrige Inventare: leere Buchungszelle bzw. `darf nicht geplant werden` → **keine** Regel.
5. RHH-Referenzen Länge 20 s / Aufschlag +30 % gelten **nur** als dokumentierter
   RHH-Geltungsbereich (`SWF-004`/`005`, `initialdaten.md`) – **keine** automatische
   Übertragung auf ROCK/OLDIE/CARAVAN.
6. Bestehende Felder: `inventory_medium_rules.default_length_seconds`,
   `surcharge_percent`, `is_discountable`, `is_ae_eligible`; Positionen frieren Länge/Aufschlag.
7. Method-Key `fixed_price` bleibt Planned; Spot-Settlement `fixed_price` ist **nicht** dieser Slice.
8. Medium-`allonge` (SWF) ≠ Spot-Allonge-Komponente (`SPT-014`).
9. Dev-DB `dispo_mat_core` in diesem Auftrag **nicht** gelesen; lokale Datenabdeckung **ungeprüft**.

## A. Scope / UX-GATE-C Teilfreigabe – **akzeptiert A1**

| Option | Inhalt |
|---|---|
| **A1 (akzeptiert)** | UX-GATE-C **Teilfreigabe** nur: Werbemittel Trailer × Methode **Durchschnitt** × **alle matrix-zulässigen Inventare** (Architektur); Preview→Save→Reload→Dispo-Snapshot; bestehende Rabatt-/AE-/Freigabe-/Pin-Verträge. Operative Erstfreischaltung nur Inventare mit gelieferten Admin-Daten (s. C). Preisbasis laut **B1**. |
| A2 | Zusätzlich engere operative Sperre: nur Radio Hamburg live, andere matrix-zulässige Inventare technisch vorbereitet aber fail-closed bis Datenlieferung |
| A3 | Keine GATE-C-Teilfreigabe jetzt |

**Entscheidung A1:** Teilfreigabe auf den Matrix-Inventaren Radio Hamburg, ROCK ANTENNE Hamburg,
80er 90er OLDIE ANTENNE Hamburg und CARAVAN.fm (Prüfung über die Regel-Matrix, **keine**
hartcodierten Inventar-IDs). Buchbar ist ein Inventar erst, wenn Trailer-Länge, Trailer-Aufschlag
und Spot-Sekundenpreisliste des Preisjahres vorliegen (siehe Abschnitt „Umsetzung“).

**Nicht** in A1: Calendar/Planer, Festpreis (Method-Key oder Settlement), CityLife,
weitere SWF-Medien, gruppierte Zeitschienen-Sonderprofile (SWF-008 Rest), Produktion,
CRM, OA, Standardangebote/Budget.

## B. Preisbasis – **akzeptiert B1**

**Entscheidung:** Trailer verwenden die **bestehenden Spot-Sekunden-Grundpreise** des
jeweiligen Inventars und Jahres (einschließlich der für Average relevanten Stundenpreise).
Zusätzlich gilt der **individuell konfigurierte Trailer-Aufschlag je Inventar**.
**Keine** eigenen SWF-Preislisten.

Pin/Jahr/`PO-PRI-HOURS-1` fail-closed unverändert. Kein stiller Fallback, keine
erfundenen Preise.

### Verbindliche Zeitraumformel (B1)

```text
Ø-Sekunden-Grundpreis(Zeitraum) = Σ Spot-Sekundenpreis(Stunde, Tagesgruppe) ÷ Anzahl(Stunden)

Zeitraumssumme = Anzahl(Zeitraum)
               × Ø-Sekunden-Grundpreis(Zeitraum)
               × Trailer-Länge
               × (1 + Aufschlag / 100)

Positions-Mediabrutto = Σ Zeitraumssummen
```

- **Kein** Spotlängenindex.
- Länge und Aufschlag **nur** aus der Konfiguration/Snapshot der jeweiligen
  Inventar-Position; **keine** Übernahme von Länge oder Aufschlag eines anderen Inventars.
- Ein **ausdrücklich konfigurierter** Aufschlag von **0 %** ist gültig.
- **Fehlende** Aufschlag-/Längen-Konfiguration bleibt **fail-closed** (nicht still 0 annehmen).

B2 (eigene SWF-Listen) ist **nicht** gewählt.

## C. Längen / Aufschläge (Daten, unverändert)

- Radio Hamburg: dokumentierte Referenz 20 s / +30 % darf als **Admin-Seed nur für dieses Inventar**
  (Rule-Felder) vorgeschlagen werden – nicht als Medium-Hardcode für alle Inventare.
- ROCK / OLDIE / CARAVAN: Werte **fehlen** als verbindliche Lieferung → fail-closed bis Admin-Daten.
- Vertrieb darf Länge je Position ändern (`SWF-004`); Aufschlag aus Rule/Snapshot, Admin-pflegbar (`SWF-005`, `ADM-003`).
- Explizit konfigurierte **0 %** erlaubt; fehlende Konfiguration ≠ 0 %.

## Umsetzung `BL-P5-01a` (Draft-PR)

| Baustein | Umsetzung |
|---|---|
| Berechnungsart / Profil | `CalculationKind::SwfTrailer = 'swf_trailer'`, `EngineProfileRegistry::PROFILE_SWF_TRAILER`; Kategorie `special_advertising_formats` × `average` Released (v1), **kein** Calendar/`fixed_price` |
| Medium | **nur** `trailer_station_voice` erhält `kind=swf_trailer`; alle übrigen SWF-Medien bleiben `kind=null` (nicht buchbar) |
| Engine | kein Spotlängenindex für `swf_trailer`; `length_index` fix 100; Komponenten (Hauptspot/Allonge/Tandem/Tridem) und Settlement `fixed_price` werden abgelehnt |
| Aufschlag | `inventory_medium_rules.surcharge_percent` jetzt **nullable ohne Default**: `NULL` = nicht konfiguriert (fail-closed), `0` = ausdrücklich 0 % |
| Länge | Regel-`default_length_seconds` muss für Trailer gesetzt sein; Vertrieb darf die Länge je Position ändern, der Aufschlag kommt stets aus Regel/Snapshot (kein Vertriebs-Override); **kein** Medium-Default-Fallback (30 s) |
| Datenmigration | Trailer-Regeln: Aufschlag und Länge → `NULL`; Spot-Regeln unverändert (0 bleibt 0) |
| Importer | `CombinationMatrixImporter` erzwingt für Trailer kein 0 %/30 s; Länge/Aufschlag bleiben `NULL` bis ausdrücklich gepflegt |
| Seed | Radio-Hamburg-Referenz (20 s / +30 %) **nur** in Test-Fixtures bzw. als bewusste Admin-Pflege; **nicht** erfunden für ROCK/OLDIE/CARAVAN |
| Fail-closed je Inventar | unvollständige Inventare blockieren andere Inventare nicht; deutsche Fehlermeldung am Positionsfeld |

Bewusste Härtung: Weil der Wizard immer `length_seconds` sendet (Vorbelegung aus Medium-Default 30 s),
verlangt der Server für Trailer zusätzlich eine **nicht leere Regel-Länge** – sonst könnte der
Medium-Default unbemerkt als „konfiguriert“ durchgehen.

## Nicht-Ziele

- Vollständiges `BL-P5-01` (alle SWF, Planer, Festpreis, CityLife, SWF-008-Sonderzeitschienen)
- `BL-P5-02` Produktion / `ADV-003`-Zusatzzeilen
- Freischaltung als `kind=spot_classic` (würde Spotlängenindex riskieren)
- ADV-002 / pauschale ADV-001-Rest-Defaults
- Standardangebote, Budget-Vorlagen, CRM, OA
- Merge / Deploy (nur Draft-PR; Freigabe separat)

## Kennungen

| Rolle | Kennung | Status |
|---|---|---|
| Slice | `BL-P5-01a` | Implementierung im Draft-PR; Merge/Deploy offen |
| PO/Gate | `PO-BLP501A-1` | **A1 ACCEPTED + B1 ACCEPTED** |
| Elternpaket | `BL-P5-01` | bleibt `offen` / teilweise nach Umsetzung von 01a |
