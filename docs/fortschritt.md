# Fortschritt V1

Stand: 3. September 2026 (Dispo-Nummer aus Kalkulationsnummer)

## Aktuelle Phase

Phase 8 (Dispoauftrag) – **Teilslice umgesetzt:** Entwurf aus Kalkulation,
Vier-Augen-Freigabe, Nachbesserung sowie Nummernableitung
`K-JJJJ-NNNNN` → `DA-JJJJ-NNNNN-SS` (Legacy-Familien behalten Stamm).
Nach erfolgreicher Genehmigung steht der Status **„Liegt bei Disposition“**.
UX-GATE-D bleibt **nicht** vollständig abgeschlossen (operative Disposition,
Material, Kommentare, weitere Status weiterhin offen).

## Aktuelle Aufgabe

Branch `feat/dispo-number-from-calculation`: direkte K→DA-Stammableitung.

## Zuletzt abgeschlossene Aufgabe

Vier-Augen-Freigabe und Nacharbeit (PR #13) auf `main` (`b9c1313`).

## Nummernableitung K→DA (September 2026)

| Kriterium | Status |
|---|---|
| Neue Familie: Stamm aus `calculation.number_year` / `number_seq` | umgesetzt |
| Padding wie Kalkulation (`NNNNN`, nicht `NNNNNN`) | umgesetzt |
| Folgeaufträge / Nachbesserung: nur Suffix | umgesetzt |
| Legacy-Familien behalten Stamm inkl. Padding | umgesetzt |
| Keine Umnummerierung historischer Nummern | umgesetzt |
| `dispo_order_number_sequences` nur noch Legacy | umgesetzt |
| MySQL-Concurrency | umgesetzt |

## Vier-Augen-Freigabe (September 2026)

| Kriterium | Status |
|---|---|
| Jeder Auftrag benötigt Freigabe (kein `Entwurf → Disposition`) | umgesetzt |
| Statusübergänge: Entwurf → Wartet → Disposition / Abgelehnt | umgesetzt |
| Regulär: anderer Vertrieb / Admin / GF | umgesetzt |
| Sonderfreigabe: nur Admin / GF | umgesetzt |
| Ersteller-Ausschluss auch bei Admin/GF | umgesetzt |
| Persistente Freigabeanforderung + Historie | umgesetzt |
| Teilübernahme umgeht Sonderfreigabe nicht | umgesetzt |
| Concurrency / `lock_version` / 409 | umgesetzt |
| Audit `submitted_for_approval` / `approved` / `rejected` | umgesetzt |
| Listenstatus nach Mutation ohne Browser-Reload | umgesetzt |
| Nachbesserung abgelehnter Aufträge als neuer Entwurf | umgesetzt |
| UX-GATE-D gesamt | **nicht** abgeschlossen |

## Nachbesserung abgelehnter Aufträge

Der abgelehnte Dispoauftrag bleibt als unveränderbarer, terminaler Snapshot erhalten.
Der Ersteller kann die zugrunde liegende Kalkulation nachbessern und daraus einen
neuen, verknüpften Dispoauftrag im Status Entwurf erzeugen (`revises_dispo_order_id`).

## Bewusst offen in diesem Slice

- Überschreiben oder Rücksetzen desselben abgelehnten Snapshots auf `Entwurf`
- operative Disposition, Material, Kommentare, Benachrichtigungen
- Status ab `In Bearbeitung`
- AUTH-005 als zwei getrennte Freigabeereignisse (hier: eine Entscheidung, Rolle hängt von Freigabeart ab)

## Echte Blocker

| ID | Thema |
|---|---|
| BLK-005 | UX-GATE-C |
| BLK-006 | UX-GATE-D (Rest) |
| BLK-001 | Initialkataloge Kapitel 27 |
| BLK-002 | Speedit-Parameter vor Produktiv-Deploy |
