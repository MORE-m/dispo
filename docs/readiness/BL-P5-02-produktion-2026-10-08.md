# Readiness: BL-P5-02 – Produktionsleistungen in Kalkulation und Dispo

Status: **READY MIT VORBEDINGUNGEN**
Stand: 8. Oktober 2026
Auditbasis: `origin/main` @ `7583a3edfbb422b7b9580b4713747201d876b8d1`
(Merge PR #130; **kein** Deploy)
IDs: `ADV-003`, `PRO-001`–`PRO-007`, `AT-11`, `COM-004`/`COM-007`/`COM-008`,
`AUTH-004`/`AUTH-005`, `ADM-001`/`ADM-003`, `PO-MAT-BOOKING-VIS-1`,
`DSP-*` (Übernahme), Abgrenzung `BL-P5-01` / `BL-P5-01a`
Entscheidung: [`docs/entscheidungen/PO-BLP502-1-produktion-sonstiges.md`](../entscheidungen/PO-BLP502-1-produktion-sonstiges.md)
(**alle Punkte Vorgeschlagen**)
Worktree: `dispo-wt-docs-production-readiness` / Branch `docs/production-readiness`

> Docs-only. Keine Implementierung, kein Commit/PR/Merge/Deploy, keine DB-/Browser-Mutation.
> Dev-DB `dispo_mat_core` **nicht** geöffnet; lokale Ist-Daten **ungeprüft**.
> Kein Zugriff auf Port 8000 / `dispo-main` / `.env`.

## 0. Urteil

| Voraussetzung | Status |
|---|---|
| Fachvertrag Zusatzzeile (`ADV-003`, `PRO-001`–`PRO-007`) | **verbindlich dokumentiert** |
| Formel `Menge × Einzelpreis`, Menge Default 0 | **belegt** (`berechnungslogik.md`, `initialdaten.md`) |
| Kennzeichen S + eigene Dispo-Zeile (`PRO-006`) | **belegt**; Dispo-Mapping **fehlt** im Code |
| Inventar-/typbezogene Produktionspreisliste | **gefordert**; Code/Admin/Daten **fehlen** |
| Sonderfreigabe bei Überschreibung (`PRO-005`) | Vertrag ja; Assessor-Code **fehlt** (Doku: „noch nicht implementiert“) |
| UX-GATE-D Teilfreigabe Admin + Calc-Zusatzzeile + Dispo-S-Zeile | **fehlt** |
| Operative Produktionspreise je Inventar/Typ | **Lieferung offen** (`initialdaten.md`) |
| Formale Abhängigkeit `BL-P5-01` vs. technische Unabhängigkeit Spot Classic | **PO-Frage B** |
| Trailer-Average (`BL-P5-01a`) als technischer Blocker für Spotproduktion@Spot | **nein** (Abgrenzung) |

**Readiness-Urteil: READY MIT VORBEDINGUNGEN** – Der kleinste vollständige Slice
(`BL-P5-02a`: Spotproduktion an Spot-Classic-Average) ist fachlich und architektonisch
abgrenzbar. Implementierungsfreigabe setzt voraus: akzeptiertes PO-BLP502-1 (mind. A/C/E/G
sowie B und H-Unterentscheidung), UX-GATE-D-Teilfreigabe und lieferbare Admin-Preise
(oder klare Fixture-only-Abnahme ohne operative Freischaltung). Ohne diese Vorbedingungen
**keine** Implementierung. Dieses Dokument behauptet **keine** Gate- oder Code-Freigabe.

---

## 1. Fachvertrag und Abhängigkeiten

### 1.1 Kanonische Regeln (Auszug)

| ID | Inhalt |
|---|---|
| `ADV-003` | Produktion/Sonstiges ist **kein** normales Werbemittel, sondern Zusatzzeile **innerhalb** einer Werbemittelposition |
| `PRO-001` | Optionale Zusatzzeile; mehrere Zeilen zulässig |
| `PRO-002` | Typ, frei benennbare Bezeichnung, Menge optional Default 0, Einzelpreis, Gesamtpreis, Bemerkung |
| `PRO-003` | Typen mind.: Spotproduktion, Influencer-Produktion, Social-Media-Produktion, Fremdkosten, Sonstiges |
| `PRO-004` | Reguläre Preise aus inventar- und typbezogener Liste; Vertrieb grundsätzlich gesperrt |
| `PRO-005` | Sonstiges frei; reguläre Überschreibung nur mit Begründung + kaufm. Sonderfreigabe |
| `PRO-006` | Immer Buchungskennzeichen **S**; im Dispoauftrag **eigene Zeile** |
| `PRO-007` | Initial nicht rabattierbar / nicht AE-fähig; Admin kann je Preisposition ändern |
| `AT-11` | Menge 0; regulär und Sonstiges; Preislistenbezug; Überschreibung nur mit Freigabe |
| Formel | `Zeilengesamt = Menge × Einzelpreis` (`docs/berechnungslogik.md`) |

### 1.2 Was `BL-P5-01` / Trailer-Average wirklich brauchen

| Frage | Bewertung | Beleg |
|---|---|---|
| Braucht Produktion SWF als Medium? | **Nein** laut `ADV-003` – Zusatzzeile an einer Werbeposition | Katalog §6 / §13 |
| Reicht `BL-P5-01a` technisch für Spotproduktion an Spot Classic? | **Ja, und mehr als nötig** – Spot Classic Average ist seit Phase 4 auf `main`; Trailer ist **nicht** Träger-Voraussetzung für 02a | `fortschritt.md`, Engine/Writer Spot Classic |
| Verlangt Doku formale PO-Teilfreigabe / Abhängigkeit? | **Ja** – Backlog: Abhängigkeit `BL-P5-01`; PO-BLP501A-1: Produktion **keine** Freigabe durch 01a; UX: Produktionspreislisten nicht in BL-P4-01a | `backlog-v1.md`, `PO-BLP501A-1`, `ux-ui-gate.md` |
| Technische Unabhängigkeit vs. formale Freigabe | **Getrennt** bewerten (PO-Frage **B**) | dieses Dokument |

**Fazit Abhängigkeit:** Für den empfohlenen Erst-Slice an **Spot Classic** ist Trailer × Average
**kein** technischer Blocker. Die formale Backlog-Abhängigkeit und fehlende UX-GATE-D-
Teilfreigabe bleiben eigenständige Vorbedingungen und dürfen nicht still übersprungen werden.

### 1.3 UX-Gates

| Gate | Bezug Produktion | Stand |
|---|---|---|
| UX-GATE-B | Träger Spot Classic | freigegeben |
| UX-GATE-C | weitere Werbeelemente / SWF-Rest | teilweise (nur Trailer×Average); **nicht** der Ort für ADV-003-Zusatzzeilen am Spot |
| UX-GATE-D | Admin Produktionspreise, Calc-Zusatzzeilen-UI, Dispo-S-Zeile, Sonderfreigabe-Hook | **Teilfreigabe fehlt**; Spot-Preislisten-Admin explizit ohne Produktionspreislisten |

---

## 2. Ist-Abdeckung Code / Tests

Einstufung je Prüfpunkt. Belege: Suche in `app/`, `database/migrations`,
`resources/js`, `tests/` auf Auditbasis – **kein** Treffer für Produktions-Fachmodell
außer allgemeinen „production“-ENV-Strings.

| Prüfpunkt | Einstufung | Beleg / Lücke |
|---|---|---|
| Zusatzzeilen- / `PriceComponent`-Modell | **fehlt** | `datenmodell.md` nennt `PriceComponent`; kein Model/Migration/Relation an `CalculationPosition` |
| Produktionspreislisten + Admin-Pflege | **fehlt** | `PriceList` = Spot-Stundenlisten (Inventar/Jahr/Revision); Navigation/UI ohne Produktionsmodul; Gate-D explizit ausgenommen |
| Mengen, Einheiten, Preisberechnung | **fachlich belegt / Code fehlt** | Formel dokumentiert; Engine addiert nur Positions-`nn_invest` ohne Zusatzzeilen-Pfad |
| Rabatt-/AE-Behandlung Zusatzzeile | **fehlt** (Vertrag klar) | `PRO-007`; Engine kennt nur Positions-Flags; kein Zeilen-Flag |
| Kennzeichen S | **teilweise (Infrastruktur) / Fachpfad fehlt** | `booking_code` an Calc-/Dispo-Position und Kombi-Admin vorhanden; Freeze aus Matrix für Träger. `PRO-006` erzwingt S auf **Produktionszeile** – kein Writer-Pfad. Matrix-Workbook enthält Label `Spotproduktion/Sonstige (S)` in Plan-Abschnitten, **keine** operative Preislieferung |
| Sonderfreigabe Produktionsüberschreibung | **fehlt** | `SpecialApprovalReasonCode` nur Rabatt-/Unattributable; `workflows-und-berechtigungen.md`: Auslöser „noch nicht implementiert“ |
| Preview / Save / Reload | **fehlt** für Zusatzzeilen | Writer/Engine/Wizard ohne Felder |
| Übernahme Dispo | **fehlt** | `DispoOrderSnapshotMapper` mappt plan_rows, time_ranges, planner, components, discounts – **kein** price_components/Zusatzzeilen-Snapshot; keine zweite Positionszeile S |
| Freeze / spätere Admin-Preisänderung | **Muster vorhanden / Produktion fehlt** | Spotlisten-Pin + Snapshot-Parität etabliert; Produktionsäquivalent nicht vorhanden |
| Tests `AT-11` / `PRO-*` | **fehlt** | keine Feature-/Unit-/E2E-Tests mit PRO/AT-11-Bezug |

### 2.1 Vorhandene wiederverwendbare Muster (kein Ersatz)

- Spot-`PriceList`-Lifecycle (Draft/Activate/Archive, Audit, Optimistic Lock) als **Vorbild**, nicht als Datenquelle für Produktionspreise (Stundenpreise ≠ Typ-Einzelpreis).
- `CalculationPositionComponent` = Spot-Komponenten (Länge/Index), **nicht** Produktions-Zusatzleistung.
- Sonderfreigabe-Pipeline (Assessor → Calc-/Dispo-Snapshot → Approve) erweiterbar um neuen Reason-Code.
- Dispo-Create aus Calc mit Positions-Snapshot; Calc bleibt editierbar (`PO-CALC-DISPO-LIFECYCLE-1`).
- Buchungskennzeichen-Freeze Träger (`PO-MAT-BOOKING-VIS-1`); Calc ohne Anzeige.

Keine allgemeinen Festpreis- oder Medienmodelle für Produktion erfinden: Produktion bleibt
Zusatzleistung gemäß `ADV-003`.

---

## 3. Empfohlener kleinster vollständiger Slice (`BL-P5-02a`)

### In Scope

1. Admin: Produktionspreis **Spotproduktion** je Inventar (mind. Architektur für alle; operativ zuerst ein Inventar mit geliefertem Preis).
2. Calc: an einer **Spot-Classic-Average**-Position optional eine oder mehrere Spotproduktions-Zusatzzeilen (Typ fix/enum, Bezeichnung, Menge Default 0, Einzelpreis aus Liste gesperrt, Gesamt, Bemerkung).
3. Server: `Gesamt = Menge × Einzelpreis`; Summe in Positions-/Auftrags-N/N gemäß Vertrag (nicht rabatt-/AE-fähig initial).
4. Preview → Save → DB-Reload-Parität.
5. Dispo: eigene Zeile mit Kennzeichen **S**, eingefrorene Beträge/Bezeichnung/Typ/Menge.
6. Fail-closed ohne aktive Preiskonfiguration.
7. Architektur: generische Zusatzzeile + Preisposition so, dass spätere Typen andocken – **ohne** sie umzusetzen.

### Außerhalb

- Sonstiges-FreiPreis und Überschreibungs-Sonderfreigabe (außer PO nimmt F2 in A1 auf)
- Influencer-/Social-Produktion, Fremdkosten, Booster
- Anbindung an Trailer/SWF als Pflichtträger
- CRM, OA, Standardangebote, Budget, weitere SWF, Deploy

### Klärungsbedarf (nur PO; siehe PO-BLP502-1)

1. Eigenständig vs. an Werbeposition gebunden? → Vertrag: gebunden (**C1**); Dispo eigene Zeile.
2. Menge / Preiseinheit? → Default 0, `× Einzelpreis` (**D1**); keine erfundenen Einheiten.
3. Preisquelle / Gültigkeit / Versionierung? → separates Admin-Modul (**E1**); Beträge Lieferung.
4. Rabatt-/AE-Fähigkeit? → initial nein (**F1**).
5. Bedeutung/Auslöser Kennzeichen S? → immer auf Produktionszeile (**G1**); nicht manuell.
6. Sonderfreigabe-Regeln? → Überschreibung separat (**F1/F2**).
7. Änderung/Entfernung/Inventarwechsel Träger? → **H1** + H1a/H1b.

---

## 4. Admin und Daten

| Thema | Stand |
|---|---|
| Admin-Modul Produktionspreise | **fehlt**; in Katalog §20.1 gefordert; Gate-D nicht freigegeben |
| Spot-`PriceList` | **vorhanden** – nur Sekunden-/Stundenpreise; **kein** Produktionsersatz |
| Belegte operative Produktionspreise im Repo | **keine** (`initialdaten.md`: „Produktionspreise je Inventar und Typ“ noch zu liefern) |
| Synthetische Fixtures | für künftige Tests zulässig; **≠** operative Freischaltung |
| Dev-DB `dispo_mat_core` | in diesem Auftrag **nicht** geprüft |
| Hardcodes | unzulässig (`ADM-003`) |

**Datenbedarf vor operativer Abnahme:** mindestens ein Inventar × Typ Spotproduktion mit
gültig aktivem Einzelpreis und klarer Gültigkeit/Version; weitere Inventare fail-closed
bis gepflegt.

---

## 5. Tests und Definition of Done (geplant, hier nicht ausgeführt)

Isolierte Test-DB; Browser-Abnahme auf eigenem Port (nicht 8000). Keine Tests in diesem Auftrag.

| # | Fall |
|---|---|
| 1 | Kontrollrechnung: bekannte Menge × Listen-Einzelpreis = Zeilen-/Positionsbeitrag |
| 2 | Preview = Save = Reload (Calc) |
| 3 | Nach Save Admin-Preis ändern → Calc-/Dispo-Snapshot unverändert (Freeze) |
| 4 | Dispo-Übernahme: eigene Zeile, Kennzeichen S, Beträge/Bezeichnung |
| 5 | Menge 0 → Zeilengesamt 0; Speichern erlaubt laut `AT-11`/Default |
| 6 | Fehlende/inaktive Preiskonfiguration → fail-closed (Preview/Save) |
| 7 | Initial kein Rabatt-/AE-Abzug auf Zusatzzeile; Mediabrutto-Position unverfälscht |
| 8 | Rechte: Admin Preise; Sales Zeile ohne Listen-Edit; Disposition Snapshot read-only |
| 9 | Trägerposition entfernen / Inventarwechsel gemäß PO-H |
| 10 | Falls F2: Überschreibung ohne Begründung/Sonderfreigabe blockiert Übergabe; mit Flag freigabefähig |
| 11 | Regression Spot Classic Average + Trailer Average (Summen/Snapshots unverändert ohne Zusatzzeile) |
| 12 | Browser-Abnahme Vertical Slice auf isolierter DB |

---

## 6. Statusdokumente

Geprüft: `docs/fortschritt.md` und `docs/backlog-v1.md` beschreiben `BL-P5-02` als offen /
ohne Freigabe durch 01a – **kein Drift**. Keine Statuskorrektur in diesem Auftrag.
Neu: Readiness, PO-Entwurf (Vorgeschlagen), Review-README; Index-Einträge in
`docs/README.md` und `docs/entscheidungen/README.md`.

---

## 7. Bewusst nicht getan

- Keine Implementierung, Migration, Seeding, Browser-Flows, DB-Zugriff
- Keine erfundenen Preise oder Standardwerte
- Keine Behauptung einer Gate-/Implementierungsfreigabe
- Kein Commit/PR/Merge/Deploy
- Standardangebote, CRM, OA, weitere SWF unberührt
