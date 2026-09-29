# AT-13 – Freigabeinvalidierung Kundenbestätigung (PO-AT13-CC-1)

Status: **Akzeptiert** (UX-GATE-D Teilfreigabe **PO-AT13-CC-1**)  
Stand: 29. September 2026  
Basis: `origin/main` @ `363def9932e6473904cfe09a02fa4a8193804c68` (nach PR #104)  
IDs: `AT-13`, `APR-004`, `UPL-001`, `BL-P7-02a`, `PO-AT13-CC-1`

## Entscheidung (PO bestätigt)

| Teil | Verbindlich |
|------|-------------|
| Auslöser | **Nur** Admin **archiviert** eine **aktive** Kundenbestätigung (`customer_confirmation`) |
| Aktive CC | Gleiche Regel wie Submit/UI: neueste nach `uploaded_at` DESC, dann `id` DESC |
| Freigabebezug | Archivierte Datei muss der `customer_confirmation_upload_id` der genehmigten Freigabe entsprechen; sonst fail-closed ohne Mutation |
| Quellstatus | **Nur** `at_disposition` (Auftrag bereits genehmigt) |
| Zielstatus | `draft` |
| Wirkung | Vollständiger erforderlicher Freigabezyklus erneut (`APR-004`): CC setzen → Submit → Approve |
| Historie | Bestehende `dispo_order_approval_requests` / Zyklen **unverändert** append-only |
| Audit | Invalidierung mit Akteur, Ursache und Bezug zur betroffenen Freigabe (`approval_request_id`) |
| Statushistorie | Append-only Event `at_disposition` → `draft` mit Begründung |
| Transition | Neue erlaubte Kante **nur** `at_disposition` → `draft` (nicht in operativen UI-Buttons) |

## Ausdrücklich ausgeschlossen

- **Replace** der Kundenbestätigung nach Genehmigung
- Alle Quellstatus außer `at_disposition` (u. a. `in_progress`, Material, `disposed`, `completed`, `sales_inquiry`)
- Preis-/Rabatt-Edit, `invoice_end_months`, Calc-only-Änderungen
- Freigaberückzug (`APR-003`), `AUTH-005`
- Weitere Status-Mails, In-App, Admin-/Audit-/Outbox-UI
- Calendar-/Budget-Vorlagen, Abbinder
- Invalidierung durch Kommentare oder additive Uploads (Regression unverändert)
- Dyn-Feld-Dateien: E7 unverändert (keine Freigabeinvalidierung)

## Fail-closed

Archiv einer Kundenbestätigung ist nur zulässig:

1. im Status `draft` (bisheriges Verhalten, **ohne** Invalidierung), oder  
2. unter den PO-AT13-CC-1-Bedingungen oben (Archiv **plus** Invalidierung in einer Transaktion).

Jeder andere Fall: Validierungsfehler, **keine** Mutation an Order/Upload/Approval.

## Nach Invalidierung

Bestehende Draft-Pfade:

- CC-Upload / Ausnahme (`DispoOrderUploadService::uploadCustomerConfirmation`, `DispoOrderCustomerConfirmationService`)
- Submit → `awaiting_sales_approval` → Approve/Reject

UI: vorhandene Statusanzeige, Statushistorie und Approval-Historie – keine neue Admin-/Audit-Oberfläche.

## Verworfene Alternativen (historisch)

B (Status behalten + Flag), C (`awaiting` ohne/mit Auto-Submit), D (Revision wie Ablehnung) – nicht gewählt.
