# PO-APPROVAL-NOTIFY-1 – Freigabe erteilt/abgelehnt per Outbox und SMTP

Status: **Akzeptiert** (Teilfreigabe, Slice A)  
Stand: 4. Oktober 2026  
Basis: `origin/main` @ `9962924303fb878dec99870d0c06cdfa9b937037` (nach PR #120)  
IDs: `NOT-001`, `NOT-002` (Teil), `APR-001`–`APR-004`, Freigabe-Workflow  
Readiness: `docs/readiness/BL-P9-02d-approval-mails-outbox-2026-10-04.md`  
Vorbilder: `PO-BLP902B-1`, `PO-BLP902C-1`

## PO-Entscheidung

Die offenen Vorbedingungen aus dem Readiness-Entwurf BL-P9-02d sind für diesen
Slice verbindlich wie folgt entschieden:

| Offene Frage | Entscheidung |
|---|---|
| Submit-Empfänger | **S4** – keine Mail bei Submit in diesem Slice |
| Approve/Reject-Empfänger | nur `submitted_by_id` des entschiedenen Zyklus; **kein** Fallback auf Ersteller, Mediaberater oder Rollen |
| Ablehnungsbegründung in der Mail | **nein** – nur in der Anwendung; keine Payload-Erweiterung |
| Slice-Schnitt | **Variante A** – nur Freigabe erteilt und Freigabe abgelehnt |
| Gate | UX-GATE-D Teilfreigabe **`PO-APPROVAL-NOTIFY-1`** für genau diesen Umfang |

Reguläre Freigabe und Sonderfreigabe sind beide enthalten. Die Freigabeart
steht im `event_label`, nicht in einem neuen Payload-Feld.

## Verbindlicher Vertrag

| Punkt | Festlegung |
|---|---|
| Ereignisse | `dispo_order.approval.approved`, `dispo_order.approval.rejected` |
| Freigabeart | Regular und Special |
| Empfänger | ausschließlich `submitted_by_id` des entschiedenen `DispoOrderApprovalRequest` |
| Fehlender Empfänger / ungültige Mail | Suppression + Audit; kein Fallback |
| Selbstbenachrichtigung | Suppression + Audit |
| Mailinhalt | bestehende NOT-001-Auftragsdaten, Ereignis, Entscheider, Zeitpunkt, interner Link |
| Ablehnungsbegründung / Sondergründe | nicht in Payload oder Mail; in der Anwendung einsehbar |
| Submit / Invalidierung | keine Mail in diesem Slice |
| Outbox-Schreibfehler | Fachtransaktion rollt zurück |
| SMTP-Fehler | keine Rücknahme der Fachentscheidung; bestehendes Retry-/Failed-Verhalten (`NOT-002` Versand) |
| Admin-Outbox-UI | separat offen; `NOT-002` daher **nicht vollständig** erledigt |
| Ereignisidentität | `source_type = dispo_order_approval_request`, `source_id` = Request-ID; neuer Zyklus = neue Identität |
| Historie | bereits entschiedene Zyklen bleiben unverändert; keine Nachsendung |

Keine Empfänger-Rollenprüfung: der Einreicher wird über das Ergebnis seines
Vorgangs informiert. Idempotenz nutzt den bestehenden Outbox-Schlüssel
(Event + Quelle + Kanal + Empfänger).

## Bewusst nicht

- Submit-Mails, Invalidierungsmails, weitere Status-Mails
- In-App, Empfängerwahl, Permission-Änderung
- allgemeine Notification-Engine
- Admin-Outbox-/Fehler-UI
- Ablehnungsbegründung oder Sondergründe in der Mail
- Migration (kein Bedarf)

## Kurzfazit

| Thema | Status |
|---|---|
| Teilfreigabe Approve/Reject-Mail | **akzeptiert** |
| Empfänger `submitted_by_id` ohne Fallback | **verbindlich** |
| Begründung nicht in der Mail | **verbindlich** |
| Submit-/Invalidierungsmail | **offen** |
| Admin-Outbox-UI / NOT-002 vollständig | **offen** |
