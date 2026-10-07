# Readiness: BL-P5-01a – SWF Trailer × Durchschnitt (inventarübergreifend)

Status: **READY MIT DATEN-VORBEDINGUNGEN JE INVENTAR** (A1 + B1 akzeptiert; Implementierung im Draft-PR)
Stand: 7. Oktober 2026
Auditbasis: `origin/main` @ `0ff11aaeb8df4ccddd0688cdeb86a551e24b9614`
(Merge PR #128; Post-Merge-CI [37611870573](https://github.com/MORE-m/dispo/actions/runs/37611870573) SUCCESS; **kein** Deploy)
IDs: `SWF-001`–`SWF-005`, `SWF-008` (Abgrenzung), `SPT-016` (Abgrenzung),
`PRI-002`/`PRI-004`/`PRI-005`/`PO-PRI-YEAR-1`/`PO-PRI-HOURS-1`, `COM-001`–`COM-008`,
`MAT-001`–`MAT-003`, `ADV-001` (Methoden-Zuordnung), `CAL-001`/`CAL-005`, `AT-05`, `ADM-003`
Entscheidung: [`docs/entscheidungen/PO-BLP501A-1-swf-trailer-average.md`](../entscheidungen/PO-BLP501A-1-swf-trailer-average.md)
(**A1 ACCEPTED + B1 ACCEPTED**)
Implementierung: Worktree `dispo-wt-feat-bl-p5-01a` / Branch `feat/bl-p5-01a-swf-trailer-average` (Draft-PR; **kein** Merge/Deploy)

> Ursprünglich Docs-only-Readiness (Stand `0ff11aa`); nach A1/B1-Akzeptanz um den
> **Umsetzungsstand** (Abschnitt 10) ergänzt. Dev-DB `dispo_mat_core` **nicht** geöffnet;
> lokale Ist-Daten **ungeprüft**. Kein Merge/Deploy.

## 0. Urteil

| Voraussetzung | Status |
|---|---|
| Formelvertrag Trailer Average (ohne Spotlängenindex) | **verbindlich (B1)** – Spot-Grundpreise × Länge × (1 + Aufschlag/100) |
| Matrix-zulässige Inventare | **4** (Workbook/Parser-Vertrag); nicht 14 |
| Eigenes Engine-Profil / kein `spot_classic`-Missbrauch | **umgesetzt** (`swf_trailer`, Draft-PR) |
| Kategorie-Methoden `average` für SWF | **umgesetzt** (Migration/Bootstrapper; nur `average`) |
| UX-GATE-C Teilfreigabe | **A1 akzeptiert** (Trailer × Durchschnitt; BLK-005 Teilfreigabe) |
| Preisbasis | **entschieden B1** – bestehende Spot-Sekunden-Grundpreise je Inventar/Jahr; keine eigenen SWF-Listen |
| Länge/Aufschlag je Inventar | inventarspezifisch; RHH-Referenz dokumentiert; übrige **Lieferung offen**; 0 % ok, fehlend fail-closed |
| Admin-Pflege Länge/Aufschlag (Rule-Felder) | Aufschlag nullable (`NULL` = nicht konfiguriert, `0` = ausdrücklich); Admin-Kombitabelle pflegt beides |
| Calendar/Festpreis/CityLife/Produktion | bewusst **außerhalb**; V1-Rest |

**Readiness-Urteil: READY MIT DATEN-VORBEDINGUNGEN JE INVENTAR** – A1 und B1 sind akzeptiert,
der vertikale Slice ist implementiert. Operativ buchbar ist ein Inventar erst, wenn Trailer-Länge,
Trailer-Aufschlag (0 % nur ausdrücklich) und Spot-Sekundenpreisliste des Jahres vorliegen; bis dahin
fail-closed **nur für dieses Inventar**. `BL-P5-01` bleibt nur **teilweise**.

---

## 1. Empfohlener Scope

### In Scope (`BL-P5-01a`)

- Werbemittel **Trailer** (`trailer_station_voice`)
- Methode **Durchschnitt** (`average`)
- Architektur und Validierung für **alle matrix-zulässigen Inventare** (derzeit 4)
- Preview → Save → DB-Reload → Dispoauftrag/Snapshot
- Bestehende Rabatt-/AE-/Freigabe-/Pin-/Jahresverträge unverändert
- Fail-closed bei fehlender Matrix-Regel, fehlendem Preis, fehlender Länge/Aufschlag-Konfiguration

### Außerhalb (weiterhin V1-Rest / andere Slices)

- Calendar/Planer, Festpreis (Method-Key und Settlement), CityLife, weitere SWF
- Gruppierte Sonder-Zeitschienen (`SWF-008` Rest) – Erst-Slice nutzt Spot-gleiche
  Durchschnitts-Zeitraumslogik (`SWF`/`berechnungslogik`: „dieselben Prinzipien … soweit nicht SWF-008“)
- Produktion `BL-P5-02` / `ADV-003`
- CRM, OA, Standardangebote, Budget-Vorlagen (pausiert)
- ADV-002 und pauschale ADV-001-Rest-Defaults (Feldsets/Rabatt jenseits Methoden)

### Vertikale Vollständigkeit

Ja, als Teilscope: neues `kind`/Engine-Profil → Kategorie-Methoden → LiveBookability →
Writer/Engine ohne Spotlängenindex → Wizard → Persistenz/Snapshot → Dispo – **pro Position
inventarisoliert**. Voraussetzungen unten §5.

---

## 2. Inventare und Datenabdeckung

Quelle Matrix: `database/data/Dispositionsauftrag_Spots_und_SWF_Alle_Sender_2026.xlsx`
(PO-MAT-CORE-MATRIX-1). Parser-/Importtests erwarten 201 Desired-State-Regeln
(`CombinationMatrixMatCoreImportTest`). Dev-DB in diesem Auftrag **nicht** gelesen.

### 2.1 Zulässigkeit Trailer

| Inventar | Buchung | Einplanung | Matrix-Regel | Bemerkung |
|---|---|---|---|---|
| Radio Hamburg | `SWF (K)` | Disposition | **ja** | |
| ROCK ANTENNE Hamburg | `SWF (K)` | Disposition | **ja** | |
| 80er 90er OLDIE ANTENNE Hamburg | `SWF (K)` | Disposition | **ja** | |
| CARAVAN.fm | `UC` | Disposition | **ja** | |
| MORE Hamburg-Kombi / Kombi+ | — | darf nicht geplant | **nein** | |
| ffn / Bollerwagen / Online Audio / Podcast / Events-* | — | darf nicht / leer | **nein** | |

Keine automatische Freischaltung aller 14 Katalog-Inventare.

### 2.2 Je zulässigem Inventar (Konfiguration vs. Rechnung vs. Nutzbarkeit)

| Inventar | Kombi aktiv (Desired) | Booking / Planung | Preisquelle 2026 (B1) | Stunden/Day-Group | Std-Länge | Aufschlag | Disc/AE (Katalog-Default) | Methoden | Beleg | Fehlend für operative Nutzung |
|---|---|---|---|---|---|---|---|---|---|---|
| Radio Hamburg | ja | SWF (K) / Disposition | Spot-Sekunden-Grundpreise PRI-OPS-1 (B1) | Spot-Import-Muster 72/72 (Audit #114; Dev-DB hier ungeprüft) | RHH-Ref. **20 s** | RHH-Ref. **+30 %** (0 % ok wenn explizit) | rabatt-/AE-fähig (Medium-Katalog) | keine SWF-Zuordnung live | Workbook + Doku + Spot-Audit | Gate-C (**A**); kind/Engine; Methoden; Rule-Seed Länge/Aufschlag |
| ROCK ANTENNE Hamburg | ja | SWF (K) / Disposition | Spot-Grundpreise (B1) | Spot-Muster 72/72 (ungeprüft lokal) | **keine** verbindliche Lieferung | **keine** (fehlend ≠ 0 %) | wie Katalog | wie oben | Matrix | Länge/Aufschlag-Konfiguration; sonst fail-closed |
| 80er 90er OLDIE … | ja | SWF (K) / Disposition | Spot-Grundpreise (B1) | Spot-Muster 72/72 | **keine** | **keine** | wie Katalog | wie oben | Matrix | Länge/Aufschlag-Konfiguration |
| CARAVAN.fm | ja | UC / Disposition | Spot-Grundpreise (B1) | Spot-Muster 72/72 | **keine** | **keine** | wie Katalog | wie oben | Matrix | Länge/Aufschlag-Konfiguration |

**Unterscheidung Belege:**

| Belegart | Zeigt | Zeigt nicht |
|---|---|---|
| Workbook + Parser-Test | Matrix-Zulässigkeit Trailer×4 | Preise, Längen, Aufschläge |
| `initialdaten.md` / SWF-004/005 | RHH-Referenz Länge/Aufschlag | andere Inventare |
| Audit 8-Inventar / PRI-OPS-1 | Spot-Sekundenpreise 2026 (historisch Dev-DB + Fixture) | aktuelle Dev-DB (hier ungeprüft); operative Freischaltung |
| PO-BLP501A-1 **B1** | Trailer nutzt Spot-Grundpreise + inventarspezifischen Aufschlag | Gate-C/Scope **A** |
| Synthetische Fixtures (§6) | Engine-/Abnahmevertrag | operative Freischaltung |

### 2.3 Empfehlung Erstfreischaltung mit realen Daten

1. **Architektur** immer für alle 4 matrix-zulässigen Inventare; Preisbasis **B1**.
2. **Operativ zuerst:** Radio Hamburg – dokumentierte Länge/Aufschlag-Referenz + vorhandene
   Spotlisten (historisch belegt; lokale Dev-DB ungeprüft).
3. ROCK / OLDIE / CARAVAN: wählbar sobald Rule-Länge **und** Aufschlag **ausdrücklich**
   konfiguriert sind (0 % gültig); bis dahin fail-closed. Keine Übernahme von RHH-Länge/Aufschlag.

---

## 3. Fachlicher Berechnungsvertrag (verbindlich für Abnahme)

### 3.1 Formel (ohne Spotlängenindex) – **PO-B1 verbindlich**

PO-BLP501A-1 **B1** (fachlich bestätigt), im Einklang mit Katalog §10 / AT-05:

```text
Ø-Sekunden-Grundpreis(Zeitraum) = Σ Spot-Sekundenpreis(Stunde, Tagesgruppe) ÷ Anzahl(Stunden)

Zeitraumssumme = Anzahl(Zeitraum)
               × Ø-Sekunden-Grundpreis(Zeitraum)
               × Trailer-Länge
               × (1 + Aufschlag / 100)

Positions-Mediabrutto = Σ Zeitraumssummen
```

- Preisbasis = bestehende Spot-Sekunden-Grundpreise des Inventars/Jahres (**keine** eigenen SWF-Listen).
- **Kein** Spotlängenindex / Längenfaktor.
- Mengen = Anzahlen je Preiszeitraum (Zeitraumssumme, danach addieren; kein ungewichteter
  Globaldurchschnitt über alle Zeiträume × Gesamtmenge).
- Ende exklusiv; Überlappungsregeln wie Spot-Average wiederverwenden.
- Fehlende Preiszelle `(Stunde, Tagesgruppe)` → fail-closed (`PO-PRI-HOURS-1`).
- Länge/Aufschlag nur inventarspezifisch; **keine** Übernahme von einem anderen Inventar.
- Aufschlag **0 %** nur wenn **ausdrücklich** konfiguriert; fehlende Konfiguration fail-closed.
- Rabatt: konsekutiv Positions- dann Auftragsrabatte (`COM-001`–`COM-004`).
- AE nach Rabatten auf AE-fähigen Betrag (`COM-005`–`COM-008`); Checkbox-Vertrag unverändert.
- Rundung: intern ≥4 Dezimalen; kaufmännisch 2 Stellen erst an Positions-/Auftragssummen
  (`berechnungslogik.md`).

### 3.2 Preise, Länge, Aufschlag als Admin-Daten

| Datum | Speicherort (Ist-Schema) | Geltung |
|---|---|---|
| Sekunden-Grundpreise (B1) | bestehende Spot-`price_lists` / `price_list_items` je Inventar+Jahr | Pin auf Position; Jahrvertrag `PO-PRI-YEAR-1` |
| Standardlänge | `inventory_medium_rules.default_length_seconds` (Override), Medium-Default nachrangig | Vorbelegung; Position `length_seconds` editierbar |
| Trailer-Aufschlag | `inventory_medium_rules.surcharge_percent` (inventarspezifisch) | Snapshot auf Position; 0 % explizit ok |
| Disc/AE | Rule-Flags | Snapshot; Admin änderbar nur für neue Snapshots |

Keine neue Vererbungshierarchie nötig – Rule-Felder existieren bereits.
Keine Übertragung Länge/Aufschlag zwischen Inventaren.

### 3.3 Mehrinventar in einer Kalkulation

`CAL-001`/`CAL-005`: mehrere Trailer-Positionen unterschiedlicher Inventare in einer
Kalkulation; jede Position eigene Preisliste, Länge, Aufschlag, Zeiträume, Rabatte;
Gesamtsumme = Summe der Positionsnetto/-bruttos. Kein gegenseitiges Überschreiben
bei Inventarwechsel (Rebind nur betroffene Position).

### 3.4 Abgrenzung Allonge

| Begriff | Bedeutung |
|---|---|
| SWF-Medium `allonge` | eigenes Werbemittel, **nicht** in diesem Slice |
| Spot-Allonge-Komponente | Teil von Spot Classic (`SPT-014` / 02c) – unverändert |

---

## 4. Architektur / Freeze-Pfade

### 4.1 Nicht als `spot_classic` freischalten

`AdvertisingMediumLiveBookability` lässt nur `kind=spot_classic` zu.
`EngineProfileRegistry` kennt nur `spot_classic` × average/calendar (fixed_price Planned).
`CalculationKind` hat nur `SpotClassic`. Freischaltung als Spot Classic würde
Spotlängenindex (`SPT-009`) und Spot-Komponentenpfade riskieren → **unzulässig**.

### 4.2 Erforderliche Erweiterungen (Implementierung später)

| Schicht | Wiederverwenden | Erweitern |
|---|---|---|
| `CalculationKind` + Kompatibilität Kategorie `special_advertising_formats` | Muster Spot | neuer Kind-Wert z. B. `swf_trailer` (Name PO/Tech) |
| `EngineProfileRegistry` | Statusmodell Released/Planned | Profil × `average` Released v1; **kein** Index |
| Kategorie-Methoden ADV-001 | Assignment-Admin | `special_advertising_formats` → `average` Default |
| Medium `trailer_station_voice` | Katalog | `kind` setzen; Mode inherit |
| `CatalogResolver` / LiveBookability | Matrix-Whitelist, Spot-Jahrespreis (B1) | Kind/Profil-Zweig; Spotlisten als Trailer-Grundpreis |
| `CalculationEngine` / Writer | Zeitraumvalidierung, Pin, Rabatt/AE, Snapshot | Dispatch Trailer-Average ohne Index; Formel B1 |
| Wizard / Validierung / Rechenerklärung | Average-UI-Muster | Trailer-Felder; kein Index; Aufschlag sichtbar |
| Dispo-Create / Snapshot | Freeze booking/planning | `kind`/Länge/Aufschlag/Summen ohne Index |
| Historische Spot-Calcs/Dispos | unverändert | Regression Pflicht |

### 4.3 ADV-Grundlagen (nur schmal)

- **In Scope:** Kategorie-Methodenzuordnung + Default `average` für SWF-Kategorie;
  Medium inherit; Released-Profil.
- **Nicht:** ADV-002 SystemFieldSetting; pauschale Feldset-/Rabatt-Defaults.

### 4.4 Pflegeoberflächen / Gates

| Oberfläche | Bedarf | Gate |
|---|---|---|
| Wizard Trailer Average | neu | **UX-GATE-C** Teilfreigabe (Frage **A**, offen) |
| Kombinationstabelle Rule: Länge/Aufschlag/Disc/AE | Schema da; Admin ggf. ergänzen | UX-GATE-D Kombi-Admin bereits teilerlaubt (BL-P2-02a); Felder explizit in Scope nennen |
| Preislisten | **B1:** bestehende Spotlisten-Admin (keine SWF-Listen) | UX-GATE-D Preislisten bereits teilerlaubt |
| Katalog Medium kind | Admin oder kontrollierter Seed | bestehende Katalog-Teilfreigabe; Seed-Vertrag fail-closed |

---

## 5. Notwendige Grundlagen vor Implementierung

1. PO akzeptiert Scope/UX-GATE-C **A1** (oder A2). **B1 ist erledigt.**
2. UX-GATE-C Teilfreigabe dokumentiert.
3. Daten: für jedes operativ freizuschaltende Inventar – aktive Spot-Preisliste (B1),
   Rule-Länge gesetzt, Aufschlag **ausdrücklich** konfiguriert (inkl. erlaubter 0 %;
   RHH-Seed nur Radio Hamburg).
4. Keine Produktion-/CRM-/OA-Abhängigkeit für diesen Teilscope.
5. `BL-P5-02` bleibt abhängig von hinreichendem SWF-Fortschritt; **dieser** Slice
   allein begründet **keine** Produktions-Implementierungsfreigabe.

---

## 6. Unabhängig hergeleitete Mehrinventar-Fixtures (synthetisch)

Nur Abnahme; **keine** operativen Preise.

**Inventar A** (RHH-ähnlich): Ø-Sekundenpreis 2,00 €; Länge 20 s; Aufschlag 30 %; 10 Einheiten in einem Zeitraum (eine Stunde).

```text
10 × 2,00 × 20 × 1,30 = 520,00
```

**Inventar B** (abweichend): Ø-Sekundenpreis 1,50 €; Länge 15 s; Aufschlag 50 %; 5 Einheiten.

```text
5 × 1,50 × 15 × 1,50 = 168,75
```

**Kalkulation A+B:** Mediabrutto-Summe **688,75** (vor Rabatt/AE).

**Negativ Spotindex:** Dieselbe Eingabe A mit Index 105 (16–24 s) **darf nicht**
angewendet werden; Ergebnis bleibt 520,00 (nicht 546,00).

**Zwei Stunden gleich gewichtet:** Preise 2,00 und 4,00 → Ø 3,00;
`10 × 3,00 × 20 × (1 + 30/100) = 780,00`.

**Aufschlag 0 % (explizit):** `10 × 2,00 × 20 × (1 + 0/100) = 400,00`.

Rabatt/AE-Beispiele an bestehenden COM-Verträgen ableiten (nicht neu erfinden).

---

## 7. Abnahmeplan (noch nicht ausführen)

### 7.1 Feature / Pest (isolierte SQLite-`:memory:` / `dispo_test`)

- Formel B1 ohne Spotlängenindex; AT-05-Zahlenbeispiel; Spot-Grundpreise als Basis
- Unterschiedliche Länge/Aufschlag je Inventar ohne Kreuzwirkung; explizite 0 % vs. fehlend
- Mehrere Trailer-Positionen → Gesamtsumme
- Preview → Save → DB-Reload-Parität
- Inventarwechsel → Neukonfiguration/Rebind laut Pin-Vertrag
- Dispoübernahme vollständiger Snapshot (booking `SWF (K)`/`UC`, planning, Beträge)
- Isolation nach Admin-Preis-/Aufschlagänderung
- Rechte; Pflichtfelder; kaufmännische Freigaben unverändert
- Fehlende Preise/Konfiguration; unzulässige Kombi; ungültige Mengen/Längen/Zeiträume/Jahr
- Passende Fehler, keine Teilanlage
- Regression Spot Classic inkl. Komponenten + Settlement-Festpreis + Tandem/Tridem

### 7.2 Browser

- Eigene Playwright-Config, freier Port (Vorschlag **8055**), eigene E2E-SQLite
- Roundtrip: Create Trailer Average → Preview → Save → Reload → Dispo anlegen → Snapshot prüfen
- Mindestens zwei Inventare synthetisch
- **Keine** Ableitung operativer Freischaltung allein aus Fixtures

### 7.3 Reale Datenabnahme (getrennt)

- Nach Gate **A** und Datenlieferung Länge/Aufschlag: Stichprobe je freigeschaltetem Inventar
  gegen Spotlisten (B1) + Rules; Dev-DB-Abdeckung separat dokumentieren

---

## 8. Offene Datenlieferungen

| Lieferung | Verbindlich für |
|---|---|
| Rule-Länge/Aufschlag Radio Hamburg (Seed aus RHH-Ref. zulässig nach Gate **A**) | Erstfreischaltung RHH |
| Rule-Länge/Aufschlag ROCK, OLDIE, CARAVAN (explizit, auch 0 %) | operative Freischaltung dieser Inventare |
| Aktive Spot-Sekundenlisten je Inventar/Jahr (B1; historisch PRI-OPS-1) | jede operative Trailer-Rechnung |
| Hinweistexte Matrix | nicht blockierend für Erst-Slice |

~~Eigene SWF-Preislisten~~ – entfallen durch **B1**.

---

## 9. Offene PO-Fragen

Keine. **Erledigt:** **A1** (UX-GATE-C Teilfreigabe Trailer × Durchschnitt) und **B1**
(Spot-Sekunden-Grundpreise + inventarspezifischer Trailer-Aufschlag).

Alle übrigen Verträge (Rabatt/AE, Pin/Jahr, Matrix-Ausschlüsse, Spot-Regression,
Allonge-Trennung, Produktion außerhalb) sind **nicht** neu zur Abstimmung gestellt.

---

## 10. Umsetzungsstand `BL-P5-01a` (Draft-PR)

### 10.1 Umgesetzt

- `CalculationKind::SwfTrailer`, `EngineProfileRegistry::PROFILE_SWF_TRAILER` (average Released v1; **kein** Calendar/`fixed_price`).
- Nur Medium `trailer_station_voice` → `kind=swf_trailer`; übrige SWF-Medien `kind=null` (unbuchbar).
- Engine: kein Spotlängenindex (`length_index` = 100), Komponenten/Settlement `fixed_price` abgelehnt.
- `inventory_medium_rules.surcharge_percent` nullable ohne Default; Datenmigration nullt nur unbestätigte Trailer-Altdefaults (Aufschlag 0 + Länge Medium-Default/NULL). Individuelle Werte: bei `kind=NULL` Preflight-Abbruch (keine stille Löschung, keine automatische Freischaltung); bei bereits aktivem `kind=swf_trailer` lässt erneutes `up()` Länge/Aufschlag unberührt. Spot-Regeln unverändert.
- Writer/Resolver fail-closed je Inventar (fehlende Regel-Länge oder fehlender Aufschlag; kein Medium-Default-Fallback); Vertrieb ändert nur die Länge, nie den Aufschlag.
- Importer und Initialkatalog (Bootstrapper) setzen für Trailer kein 0 %/30 s.
- Wizard blendet für Trailer die Spot-Komponenten-Aktivierung aus; Kalender/Festpreis erscheinen nicht (backend-gesteuert).
- Tests: Pest (Formel 520,00 / 168,75 / 688,75, Mehrstunden-Durchschnitt, mehrere Zeiträume, kein Index, 0 %, fehlende Konfiguration/Preiszelle, andere SWF unbuchbar, Snapshot-Isolation, Rebind, Rechte, Rabatt/AE, Spot-Regression) und Playwright `playwright.blp501a.config.ts` (Port 8055).

### 10.2 Datenlücken je Inventar (operativ)

| Inventar | Trailer-Regel nach Import/Migration | Fehlt für operative Nutzung |
|---|---|---|
| Radio Hamburg | Länge/Aufschlag `NULL` | Admin pflegt Referenz **20 s / +30 %** (nur für dieses Inventar; **kein** automatischer Seed) + aktive Spot-Preisliste des Jahres |
| ROCK ANTENNE Hamburg | `NULL`/`NULL` | Lieferung Länge und Aufschlag (0 % nur ausdrücklich) + Spot-Preisliste |
| 80er 90er OLDIE ANTENNE Hamburg | `NULL`/`NULL` | wie ROCK |
| CARAVAN.fm | `NULL`/`NULL` | wie ROCK |

### 10.3 Bewusste Entscheidungen

- Der Server verlangt für Trailer eine **nicht leere Regel-Länge**, auch wenn der Wizard `length_seconds` sendet (Vorbelegung aus Medium-Default 30 s würde sonst als „konfiguriert“ durchgehen).
- Beim Inventarwechsel einer Bestandsposition gilt die im Request gesendete Länge als Vertriebs-Länge; der Aufschlag kommt immer aus der Zielregel.
- Rollback der Migration verweigert sich, sobald `swf_trailer`-Positionen existieren.
