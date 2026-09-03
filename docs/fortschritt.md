# Fortschritt V1

Stand: 3. September 2026 (Vier-Augen-Freigabe + Nachbesserung)

## Aktuelle Phase

Phase 8 (Dispoauftrag) – **Teilslice umgesetzt:** Entwurf aus Kalkulation,
Vier-Augen-Freigabe sowie Nachbesserung abgelehnter Aufträge über einen neuen,
verknüpften Entwurf. Nach erfolgreicher Genehmigung steht der Status
**„Liegt bei Disposition“**. UX-GATE-D bleibt **nicht** vollständig abgeschlossen
(operative Disposition, Material, Kommentare, weitere Status weiterhin offen).

Ausgangsbasis für den Freigabe-Slice: `main` @ `6aa2563`.

## Aktuelle Aufgabe

Branch `feat/dispo-order-approval` / PR #13: Vier-Augen-Freigabe und Nacharbeit
(Listen-Cache, Nachbesserung).

## Zuletzt abgeschlossene Aufgabe

Dispoauftrag-Entwurf aus Kalkulation (PR #12) auf `main` (`6aa2563`).

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
