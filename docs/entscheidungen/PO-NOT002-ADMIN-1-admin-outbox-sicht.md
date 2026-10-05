# PO-NOT002-ADMIN-1 – Admin-Sicht auf Benachrichtigungs-Outbox (lesend)

Status: **Akzeptiert**  
Stand: 5. Oktober 2026  
Basis: `origin/main` @ `a4ba38cc7193ac1cb3eec6307659bd27abf292be` (nach PR #121)  
IDs: `NOT-002` (Admin-Sicht), Lesekontext `NOT-001`  
Readiness: `docs/readiness/BL-P9-02e-admin-notification-outbox-read-2026-10-05.md`  
Slice: `BL-P9-02e`

## PO-Entscheidung

| Punkt | Festlegung |
|---|---|
| Zugriff | ausschließlich Rolle **Admin**; Management allein **nein** |
| Outbox | paginierte Liste + Detail; Default-Filter Status **`failed`**; alle Status wählbar |
| Ereignisse | nur die vier freigegebenen: Ask, Answer, Approved, Rejected; Kanal `email` |
| Suppression | eigener Tab „Unterdrückt (kein Versand)“; getrennt von SMTP-Fehlern |
| Mutationen | keine (kein Retry/Resend/Dispatch/Löschen/Statusänderung) |
| Manueller Resend | bewusst nicht |
| Allgemeine Audit-Explorer-UI | bewusst nicht |

## Vertrag

- Hub-Kachel und alle Listen-/Detailrouten nur mit Gate `view-notification-outbox`.
- Pagination 25 / Seite, Sortierung `id` DESC; Filter bleiben in Query-Params.
- Auftragszuordnung nur über bekannte Quellen Comment / ApprovalRequest; Links serverseitig.
- Fehlertexte serverseitig maskieren/kürzen vor Inertia; gespeicherte Daten unverändert.
- Suppression-Actions: `dispo_order.sales_inquiry.notification_suppressed`,
  `dispo_order.approval.notification_suppressed`.

## NOT-002

Dieser Slice schließt „für Admin sichtbar protokolliert“.  
„Erneut versucht“ bleibt das bestehende automatische Retry.  
Vollständig auf `main` erst nach Merge dieses Slices.

## Bewusst nicht

Submit-/Invalidierungsmails, weitere Events, In-App, Empfängerwahl, Permission-Engine,
Migration ohne Bedarf, Worker/Dispatch, Deploy.
