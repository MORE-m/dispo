# PO-BLP502-1 – Produktion/Sonstiges (ADV-003) – erster Slice

Status: **Vorgeschlagen**
Stand: 8. Oktober 2026
Auditbasis: `origin/main` @ `7583a3edfbb422b7b9580b4713747201d876b8d1`
(Merge PR #130; **kein** Deploy)
IDs: `ADV-003`, `PRO-001`–`PRO-007`, `AT-11`, `COM-004`/`COM-007`/`COM-008`,
`AUTH-004`/`AUTH-005`, `ADM-001`/`ADM-003`, `VER-*` (Preislisten-Freeze analog),
`MAT-*` / `PO-MAT-BOOKING-VIS-1` (Abgrenzung Kennzeichen), `DSP-*` (Dispo-Zeile)
Slice-Kennung: **`BL-P5-02a`** (vorgeschlagener Teilscope von `BL-P5-02`)
Readiness: [`docs/readiness/BL-P5-02-produktion-2026-10-08.md`](../readiness/BL-P5-02-produktion-2026-10-08.md)

> Docs-only. Keine Implementierungs- oder Gate-Freigabe. Entscheidungen bleiben
> **Vorgeschlagen**, bis der Product Owner akzeptiert.

## Kontext

`BL-P5-02` verlangt Produktions-/Sonstiges-Zusatzzeilen in Kalkulation und Dispo
(`PRO-001`–`PRO-007`, Abnahme `AT-11`). Im Code auf `origin/main` @ `7583a3e…`
existiert **kein** `PriceComponent`-Modell, keine Produktionspreisliste, keine
Wizard-UI, kein Sonderfreigabegrund für Preisüberschreibung und kein Dispo-Snapshot
für Zusatzzeilen. `docs/datenmodell.md` nennt `PriceComponent` nur konzeptionell.

Backlog (`docs/backlog-v1.md`): Abhängigkeit von `BL-P5-01` („hinreichender
SWF-Fortschritt“); **nicht** allein durch `BL-P5-01a` freigegeben.
`BL-P5-01a` (Trailer × Average) ist auf `main` und lokal operativ abgenommen;
`BL-P5-01` bleibt teilweise.

## Empfohlener Erst-Slice (`BL-P5-02a`)

| Dimension | Vorschlag |
|---|---|
| Typ | **Spotproduktion** (ein regulärer Produktionstyp aus `PRO-003` / `initialdaten.md`) |
| Trägerposition | **Spot Classic × Durchschnitt** (bestehende Werbeposition; kein neues Werbemittel) |
| Inventar (operativ zuerst) | **Radio Hamburg** (historisch belegte Spotpreis-/Kombipflege; Dev-DB hier ungeprüft) |
| Ablauf | Admin-Preis → Auswahl Zusatzzeile → Preview → Save → Reload → Dispo |
| Formel (belegt) | `Zeilengesamt = Menge × Einzelpreis` (`berechnungslogik.md`; Menge optional, Default **0**) |
| Rabatt/AE initial | **nicht** rabattierbar, **nicht** AE-fähig (`PRO-007`) |
| Kennzeichen | immer **Spotproduktion/Sonstige (S)** auf der Produktionszeile (`PRO-006`) |
| Architektur | generisches Zusatzzeilen-/Preislistenmodell; weitere Typen (Influencer, Social, Fremdkosten, Sonstiges) **nicht** im Erst-Slice |

**Bewusst außerhalb:** Sonstiges-FreiPreis, Überschreibungs-Sonderfreigabe als
Vollumfang (nur Vertrag/Hook vorbereiten, falls A akzeptiert), Influencer-/Social-
Produktion, Booster, OA/CRM, weitere SWF, Standardangebote/Budget, Deploy.

## Gate-Empfehlung

| Gate | Rolle |
|---|---|
| **UX-GATE-D Teilfreigabe** | Admin-Produktionspreise + Calc-Zusatzzeile an Spot Classic + Dispo-Anzeige eigener S-Zeile |
| **UX-GATE-C** | **nicht** nötig für diesen Teilscope: Produktion ist laut `ADV-003` **kein** Werbemittel; Träger ist Spot Classic (GATE-B freigegeben) |
| **UX-GATE-B** | unverändert; Wizard nur um optionale Zusatzzeile erweitern (Teilfreigabe D) |

Produktionspreislisten sind in `docs/ux-ui-gate.md` bei BL-P4-01a ausdrücklich
**nicht** freigegeben.

## Entscheidungen (alle Vorgeschlagen)

### A – Scope / Gate

| ID | Option | Inhalt |
|---|---|---|
| **A1** (Empfehlung) | Teilscope `BL-P5-02a` | Spotproduktion × Spot Classic Average; Admin-Liste inventar+typ; vollständiger Vertical Slice; UX-GATE-D Teilfreigabe |
| A2 | Enger | Nur Admin-Preisliste ohne Calc/Dispo (kein AT-11) |
| A3 | Keine Freigabe | Warten auf vollständiges `BL-P5-01` / andere Priorität |

### B – Abhängigkeit `BL-P5-01`

| ID | Option | Inhalt |
|---|---|---|
| **B1** (Empfehlung) | Formal entkoppeln für 02a | Technisch reicht Spot Classic; Trailer-Average **nicht** Voraussetzung für Spotproduktion an Spot |
| B2 | Phase-Reihenfolge halten | Implementierung erst nach weiterem SWF-Fortschritt trotz technischer Unabhängigkeit |
| B3 | An Trailer binden | Erst-Slice nur an SWF-Trailer-Positionen (erhöht Abhängigkeit zu 01a unnötig) |

### C – Bindung an Werbeposition

| ID | Option | Inhalt |
|---|---|---|
| **C1** (Empfehlung) | Gebundene Zusatzzeile | Calc: Kind von `CalculationPosition`; Dispo: **eigene** Snapshot-Zeile mit Kennzeichen S (`PRO-001` + `PRO-006`) |
| C2 | Eigenständige Calc-Position | Widerspricht `ADV-003` / `PRO-001` („innerhalb einer Werbemittelposition“) |

### D – Menge und Preiseinheit

| ID | Option | Inhalt |
|---|---|---|
| **D1** (Empfehlung) | Menge optional, Default 0; Einheit „Stück“ implizit; `Gesamt = Menge × Einzelpreis` | Entspricht `PRO-002` / `berechnungslogik.md` / `initialdaten.md` |
| D2 | Menge Pflicht ≥ 1 | Weicht vom dokumentierten Default 0 ab |
| D3 | Andere Einheit (Minuten, Pauschale ohne Menge) | **nicht belegt** – nicht wählen ohne neue Fachlieferung |

### E – Preisquelle / Versionierung

| ID | Option | Inhalt |
|---|---|---|
| **E1** (Empfehlung) | Eigenes Admin-Modul „Produktionspreise“ (Inventar × Typ × Gültigkeit, Version/Freeze analog Spotlisten-Geist) | Entspricht Admin-Tabelle `anforderungskatalog.md` §20.1; **keine** Wiederverwendung der Spot-Stundenpreisliste |
| E2 | Erweiterung `PriceList` um Listenart | Technisch möglich; Fachvertrag nennt separates Modul |
| E3 | Hardcoded Defaults | Verboten (`ADM-003`, Agentenregeln) |

Beträge: **keine** inventarspezifischen Produktionspreise im Repo belegt
(`initialdaten.md`: „Vor Produktivsetzung noch zu liefern“). Nur synthetische
Fixtures für Tests; operative Werte = Datenlieferung vor Abnahme.

### F – Rabatt / AE / Überschreibung

| ID | Option | Inhalt |
|---|---|---|
| **F1** (Empfehlung) | Initial nicht rabatt-/AE-fähig; Flags admin-pflegbar je Preisposition (`PRO-007`); regulärer Einzelpreis für Vertrieb gesperrt (`PRO-004`); Überschreibung + Begründung + Sonderfreigabe im **Folgeslice** oder schmal mit A1, wenn explizit | `workflows-und-berechtigungen.md`: Auslöser „überschreibender regulärer Produktionspreis (noch nicht implementiert)“ |
| F2 | Überschreibungs-Sonderfreigabe Pflicht im Erst-Slice | Größer; braucht neuen `SpecialApprovalReasonCode` + Assessor |
| F3 | Sonstiges-FreiPreis im Erst-Slice | `PRO-005`; bewusst außerhalb 02a |

### G – Kennzeichen S und Dispo-Darstellung

| ID | Option | Inhalt |
|---|---|---|
| **G1** (Empfehlung) | Produktionszeile friert Kennzeichen **S** ein (nicht aus Träger-Kombination L/K); Dispo zeigt eigene Zeile; Calc zeigt Kennzeichen der Zusatzzeile weiterhin **nicht** (PO-MAT-BOOKING-VIS-1 Option A gilt für Träger; S nur Dispo) | `PRO-006` + akzeptierte Booking-Vis |
| G2 | S auch in Calc sichtbar | Braucht eigene Vis-/Gate-Entscheidung; widerspricht aktuellem Option-A-Vertrag |

### H – Lifecycle Trägerposition

| ID | Option | Inhalt |
|---|---|---|
| **H1** (Empfehlung) | Zusatzzeilen bleiben an Position gebunden; bei Inventar-/Mediumwechsel der Trägerposition: Preise der Zusatzzeile fail-closed neu auflösen oder Zeile entfernen (explizit wählen unten); bei Entfernen der Trägerposition: Zusatzzeilen mitlöschen | Keine belegte Detailregel – **Unterentscheidung nötig** |
| H1a | Bei Inventarwechsel: Produktionspreis neu aus aktiver Liste des neuen Inventars (fail-closed wenn fehlend) | |
| H1b | Bei Inventarwechsel: Zusatzzeilen verwerfen und Nutzer neu anlegen lassen | |
| H2 | Zusatzzeilen inventarunabhängig fortbestehen | Fachlich riskant / nicht belegt |

## Nicht-Ziele (auch nach Akzeptanz A1)

- Vollständiges `BL-P5-01` / weitere SWF
- Influencer-/Social-Produktion, Fremdkosten, Sonstiges-FreiPreis (außer explizit erweitert)
- Boosterbudget / Social-Pakete (`SOC-*`)
- CRM, OA, Standardangebote, Budget-Vorlagen
- Deploy / andere Umgebungen
- Zugriff auf `dispo_mat_core` in diesem Readiness-Auftrag (bereits Docs-only)

## Akzeptanzkriterien (nach Gate + Daten)

Siehe Readiness § Definition of Done / `AT-11`. Kurz:

1. Menge Default 0; reguläre Spotproduktion aus Admin-Liste.
2. Preview/Save/Reload-Parität; eingefrorene Preise trotz späterer Admin-Änderung.
3. Dispo eigene Zeile mit Kennzeichen S.
4. Initial kein Rabatt/AE auf der Zeile.
5. Fehlende/inaktive Preiskonfiguration fail-closed.
6. Rechte: Admin pflegt Preise; Sales nutzt Zeile ohne Listenpreis-Edit; Disposition liest Snapshot.
7. Regression Spot Classic + Trailer Average unverändert.

## Stopp

Zur Scope-/Gate-Entscheidung **A–H**. Keine Implementierung ohne akzeptiertes A
(und geklärtes B/C/E/G sowie Datenlieferung).
