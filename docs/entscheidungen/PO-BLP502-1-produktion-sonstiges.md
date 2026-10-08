# PO-BLP502-1 – Produktion/Sonstiges (ADV-003) – erster Slice

Status: **Akzeptiert** (A1, B1, C1, D1, E1, F wie unten, G1, H1/H1a)
Stand: 8. Oktober 2026
Auditbasis: `origin/main` @ `7583a3edfbb422b7b9580b4713747201d876b8d1`
(Merge PR #130; **kein** Deploy)
IDs: `ADV-003`, `PRO-001`–`PRO-007`, `AT-11`, `COM-004`/`COM-007`/`COM-008`,
`AUTH-004`/`AUTH-005`, `ADM-001`/`ADM-003`, `VER-*`,
`MAT-*` / `PO-MAT-BOOKING-VIS-1`, `DSP-*`
Slice-Kennung: **`BL-P5-02a`** (Teilscope von `BL-P5-02`, nicht vollständig)
Readiness: [`docs/readiness/BL-P5-02-produktion-2026-10-08.md`](../readiness/BL-P5-02-produktion-2026-10-08.md)
Auflösungsvertrag: [`PO-BLP502-1-aufloesungsvertrag.md`](PO-BLP502-1-aufloesungsvertrag.md)

## Akzeptierte Entscheidungen

| ID | Entscheidung |
|---|---|
| **A1** | Spotproduktion an Spot Classic × Average; Admin → Auswahl → Preview → Save → Reload → Dispo; **UX-GATE-D Teilfreigabe** für die erforderlichen Oberflächen |
| **B1** | Formal unabhängig vom übrigen `BL-P5-01`-Ausbau; keine pauschale Freigabe anderer Produktions-/SWF-Slices |
| **C1** | Gebundene Zusatzzeile in der Werbeposition; mehrere Zeilen je Träger möglich; kein normales Werbemittel |
| **D1** | Menge optional, Default 0; `Zeilengesamt = Menge × Einzelpreis`; keine Kopplung an Spotanzahl/-länge |
| **E1** | Eigenes Admin-Modul inventarspezifischer Produktionspreise mit Gültigkeit/Versionierung/Freeze; **keine** Spot-Stundenpreise |
| **F** | Initial nicht rabatt-/AE-fähig; Flags je Preisposition admin-pflegbar und vertragskonform wirksam; Sales-Einzelpreis gesperrt; **Preisüberschreibung vollständig ausgeschlossen** (auch serverseitig); Sonstiges-FreiPreis und Überschreibungs-Sonderfreigabe folgen separat |
| **G1** | Dispo eigene Produktionszeile mit eingefrorenem Kennzeichen **S**; kein Träger-Kennzeichen; keine zusätzliche S-Anzeige in der Kalkulation |
| **H1/H1a** | Inventarwechsel: Zielpreis neu auflösen; fehlt Preis → Preview/Save/Übergabe blockieren, Zeile behalten; nicht unterstütztes Medium/Methode: Zeile behalten, Vorgang sperren bis Entfernung/zulässige Auswahl; Träger löschen entfernt Zusatzzeilen; Dispo-Snapshots unverändert |

## Inventarübergreifend

Für alle zulässigen Spot-Classic-Inventare konfigurierbar. Keine hardcodierten
Sender-IDs. Operative Nutzung nur bei vollständiger Admin-Preiskonfiguration.
Reale Produktionspreise sind **keine** Implementierungsvoraussetzung;
synthetische Fixtures für technische Abnahme. Keine operative Freischaltung
oder Datenlieferung behaupten.

## Nicht-Ziele

- Calendar / Tandem/Tridem / Trailer als Produktionsträger
- Weitere Produktionsarten, freie Preise, Preisüberschreibungen
- CRM, OA, weitere SWF, Standardangebote/Budget, Deploy

## Folge

`BL-P5-02` und `AT-11` bleiben nach diesem Slice **teilweise**.
