# Readiness: BL-P9-02e – Admin-Sicht auf Benachrichtigungs-Outbox (lesend)

Status: **FREIGEGEBEN / AUF `main`** (PR #122)
Stand: 7. Oktober 2026 (Statusnachzug Merge)
Auditbasis (historisch): `origin/main` @ `a4ba38cc7193ac1cb3eec6307659bd27abf292be` (nach PR #121)
Merge: `68b7d8bf2dd604c44f703374b47d9815c73284ac`; Post-Merge-CI
[37303343328](https://github.com/MORE-m/dispo/actions/runs/37303343328) SUCCESS; **kein** Deploy
IDs: `NOT-002` (Admin-Sicht), Teil `NOT-001` (Lesekontext), Vorbilder `PO-BLP902B-1` / `PO-BLP902C-1` / `PO-APPROVAL-NOTIFY-1`
Gate-Kennung: `PO-NOT002-ADMIN-1`
PO-Entscheidung: `docs/entscheidungen/PO-NOT002-ADMIN-1-admin-outbox-sicht.md` (**Akzeptiert**)

> Admin-Outbox-Sicht auf `main`. Submit-/Invalidierungsmails und In-App bleiben offen.
> Deploy nicht erfolgt. Statuskorrektur ersetzt keine fehlende manuelle Fachabnahme.

## 0. Gate

| Voraussetzung | Status |
|---|---|
| Outbox-Tabelle + Zustände (BL-P1-05a) | vorhanden |
| Ask/Answer Enqueue + SMTP (02b/02c) | vorhanden |
| Approve/Reject Outbox + SMTP (02d / PO-APPROVAL-NOTIFY-1) | vorhanden auf `main` |
| Automatisches Retry/Failed (Versandteil von NOT-002) | vorhanden |
| Admin-Hub / Inertia-Admin-Muster | vorhanden |
| Allgemeine Admin-Audit-UI | **fehlt** (bewusst nicht) |
| UX-GATE-D / PO-Teilfreigabe für Admin-Outbox-UI | **freigegeben** (PO-NOT002-ADMIN-1) |
| Wer darf sehen (Admin vs. Management) | **nur Admin** (PO-Festlegung) |
| Suppression in Scope von NOT-002? | **eigener Tab** (PO-Festlegung) |

**Readiness-Urteil:** **FREIGEGEBEN**; Slice auf `main` (PR #122).

---

## 1. Verbindliche NOT-002-Anforderungen (Ist)

| Quelle | Aussage |
|---|---|
| Katalog §22.2 / `NOT-002` | Fehler beim E-Mail-Versand dürfen den fachlichen Statuswechsel **nicht** zurückrollen; sie werden **für Admin sichtbar protokolliert** und **erneut versucht**. |
| Ist Versand | Rollback-Verbot + Retry/`failed` umgesetzt (02c/02d) |
| Ist Admin-Sicht | **offen** – bisher ausdrücklich aus 02b/02c/02d ausgeschlossen |
| `PO-APPROVAL-NOTIFY-1` | Admin-Outbox-UI separat; NOT-002 deshalb nicht vollständig |
| `PO-BLP902B-1` | Suppress ohne Nutzer-UI; Audit dauerhaft; **keine** Outbox-Zeile bei Suppress |

**Auswertung gegen rein lesende Admin-UI:**

| NOT-002-Teil | Stand nach vorgeschlagenem Slice |
|---|---|
| Kein Fach-Rollback bei SMTP-Fehler | bereits erfüllt; Slice ändert das nicht |
| Erneut versuchen | bereits automatisches Retry (max. 3) → `failed`; **kein** manueller Resend nötig für Katalogtext |
| Für Admin sichtbar protokolliert | **dieser Slice** schließt die Lücke |

**Teilabdeckung klar benennen:** Eine rein lesende Ansicht erfüllt den Katalogtext
„sichtbar protokolliert“, solange das automatische Retry unverändert bleibt.
Manueller Resend/Retry-Button ist **nicht** in NOT-002 gefordert und bleibt
bewusst ausgeschlossen. Suppression ist **kein** SMTP-Fehler und darf nicht als
solcher dargestellt werden; ob sie im Slice mit angezeigt wird, ist PO-Entscheidung (§7).

---

## 2. Datenquellen (Code-Ist)

### 2.1 Outbox (`notification_outbox`)

Vorhandene Felder (Migration BL-P1-05a):  
`id`, `idempotency_key`, `event_type`, `source_type`, `source_id`, `channel`,
`status`, `recipient_user_id`, `recipient_email`, `recipient_name`, `payload_json`,
`attempt_count`, `last_error`, `last_attempt_at`, `sent_at`, `available_at`, Timestamps.

Statusmodell: `pending` | `queued` | `sending` | `sent` | `failed`.

Zustellbare Events heute (`NotificationOutboxDeliveryService::DELIVERABLE_EVENT_TYPES`):

| Event | Quelle | Empfänger-Snapshot |
|---|---|---|
| `dispo_order.sales_inquiry.asked` | `dispo_order_comment` / Comment-ID | Advisor zum Enqueue |
| `dispo_order.sales_inquiry.answered` | `dispo_order_comment` / Response-Comment-ID | Ask-Autor |
| `dispo_order.approval.approved` | `dispo_order_approval_request` / Request-ID | `submitted_by_id` |
| `dispo_order.approval.rejected` | `dispo_order_approval_request` / Request-ID | `submitted_by_id` |

`payload_json` = eingefrorene NOT-001-Keys (`order_number`, `customer_name`,
`campaign`, `event_label`, `actor_id`, `actor_name`, `internal_url`, `occurred_at`).
Keine Ablehnungsbegründung, keine Frage-/Antworttexte.

Indizes vorhanden:

- Unique `idempotency_key`
- `(status, available_at, id)` Due-Dispatch
- `(event_type, source_type, source_id)` Quelle
- `(recipient_user_id, id)` Empfänger

`last_error`: wird beim Delivery aus `Throwable::getMessage()` geschrieben und auf
**2000 Zeichen** gekürzt. Potenziell technische SMTP-Details; Secrets sind nicht
bewusst vorgesehen, aber Exception-Text kann Hostnamen/Auth-Hinweise enthalten →
Anzeigevertrag §5.

### 2.2 Suppression (Audit, ohne Outbox)

| Action | Payload (`new_values`) | Auditable |
|---|---|---|
| `dispo_order.sales_inquiry.notification_suppressed` | `event_type`, `reason`, `comment_id`, `intended_recipient_user_id` | `DispoOrder` |
| `dispo_order.approval.notification_suppressed` | `event_type`, `reason`, `approval_request_id`, `intended_recipient_user_id` | `DispoOrder` |

Gründe (Ist): `missing_advisor`, `missing_ask_author`, `missing_submitter`,
`self_notification`, `recipient_not_loadable`, `invalid_email`.

**Wichtig:** Suppression ≠ SMTP-Fehler. Kein `last_error`, kein Retry, kein Outbox-Status.

Index `audit_events`: nur `(auditable_type, auditable_id)`. Filter nach `action`
hat **keinen** eigenen Index. Für den ersten Slice akzeptabel (kleine Mengen);
Migration nur bei nachgewiesenem Bedarf.

### 2.3 Allgemeine Admin-Audit-Ansicht

**Nicht vorhanden.** Keine Route/Controller/Inertia-Seite für `audit_events`.
Sonderfreigabe-Admin und andere Module schreiben Audit, zeigen aber keine globale Historie.

### 2.4 Dev-DB Inventur (read-only)

Gegen `dispo_mat_core` (XAMPP-MySQL, nur SELECT, Stand Analyse):

| Größe | Wert |
|---|---|
| `notification_outbox` Zeilen | **0** |
| Suppress-Audits (Notification) | **0** (keine Treffer auf `action LIKE '%notification%'`) |

Keine Echtdaten für Fehlertexte in Dev. Port 8000 / Storage / Worker **nicht** angefasst.

---

## 3. Zuordnung Auftrag / Ereignis / Empfänger

### 3.1 Primärpfad (empfohlen)

1. Outbox-Zeile lesen (Empfänger bereits als Snapshot).  
2. Quelle auflösen:
   - `dispo_order_comment` → `DispoOrderComment::find(source_id)` → `dispo_order_id`
   - `dispo_order_approval_request` → `DispoOrderApprovalRequest::find(source_id)` → `dispo_order_id`
3. Auftrag laden; Link nur wenn Zeile existiert und Admin Dispo sehen darf (Admin: ja).

Comments und ApprovalRequests sind append-only / historisch stabil (keine Löschpfade
im Code). Hard-Delete wäre ein Datenbruch außerhalb des Normalbetriebs.

### 3.2 Fallback bei fehlender Quelle

| Situation | Anzeige |
|---|---|
| Comment/Request nicht ladbar | Ereignis + `source_type`/`source_id` + Payload-`order_number`; **kein** Deep-Link |
| Order nicht ladbar | wie oben; optional Payload-`internal_url` **nicht** blind rendern |
| Empfänger-User gelöscht | Snapshot `recipient_email`/`recipient_name` weiter zeigen; User-ID als Hinweis |

`internal_url` aus Payload nur verwenden, wenn serverseitig als App-Route
`dispo-orders.show` validiert; sonst weglassen (kein offenes Redirect-Risiko).

### 3.3 Suppression-Zuordnung

- `auditable` = DispoOrder → direkter Auftragslink  
- Ask: `comment_id` → optional Comment prüfen  
- Approval: `approval_request_id` → optional Request prüfen  
- `intended_recipient_user_id` auflösen wenn möglich; sonst nur ID

---

## 4. Vorgeschlagene Oberfläche (schmal, lesend)

**Modul:** Administration → „Benachrichtigungen / Outbox“  
**Pfadvorschlag:** `/administration/benachrichtigungen` (Index) +
`/administration/benachrichtigungen/{outbox}` (Detail)  
**Zugriff:** **nur Rolle Admin** (neuer Gate z. B. `view-notification-outbox`);
Management allein **nein** – analog Sonderfreigaben, enger als `access-administration`.  
Hub-Kachel nur sichtbar, wenn Gate greift. Backend **und** UI denselben Gate prüfen.

### 4.1 Outbox-Liste

- Paginierung (Vorschlag: 25 / Seite; Laravel `paginate` – im Admin bisher kaum genutzt,
  daher schmales neues Listenmuster mit PageHeader + Tabelle wie Kombinationen/Sonderfreigaben)
- Filter:
  - Status: alle / `pending` / `queued` / `sending` / `sent` / `failed`
  - Ereignis: alle unterstützten vier Event-Types (Whitelist)
- Default-Filter-Empfehlung: Status **`failed`** (NOT-002-Fokus), Ereignis alle
- Spalten: Zeitpunkt (`created_at` / `last_attempt_at`), Ereignis-Label, Auftragsnummer,
  Empfänger (Name + Mail), Status-Label (deutsch), Versuche, Kurzfehler
- Keine Mutations-Buttons

### 4.2 Outbox-Detail

Sichtbar:

| Feld | Anzeige |
|---|---|
| Status | deutsche Bezeichnung |
| Event | Type + Label aus Payload |
| Empfänger | Name, E-Mail, User-ID falls gesetzt |
| Versuche / Zeiten | `attempt_count`, `available_at`, `last_attempt_at`, `sent_at`, `created_at` |
| Auftrag | Nummer + Link wenn auflösbar |
| Actor | Name (Payload) |
| Fehler | maskierte/gekürzte `last_error` (§5) |
| Quelle | `source_type` / `source_id` (technisch, klein) |

**Nicht** anzeigen: vollständiges `payload_json` roh, `idempotency_key` optional ausblenden,
keine Exception-Stacks, keine SMTP-Credentials.

### 4.3 Suppression

**Empfehlung S1:** zweiter Tab/Listenbereich „Unterdrückt (kein Versand)“ unter demselben Modul,
gefiltert auf die zwei Suppress-Actions; klarer Hinweistext, dass dies **kein** SMTP-Fehler ist.

Alternative S2: nur Doku-Hinweis + späterer Slice (NOT-002 Kern wäre dann nur Outbox-`failed`).

### 4.4 Statusbezeichnungen (Vorschlag)

| Code | Label |
|---|---|
| `pending` | Ausstehend |
| `queued` | In Warteschlange |
| `sending` | Wird gesendet |
| `sent` | Gesendet |
| `failed` | Fehlgeschlagen |

---

## 5. Fehlertexte und Datenzugriff

| Regel | Vorschlag |
|---|---|
| Gespeicherte Daten | **unverändert** lassen; keine Migration/Backfill von `last_error` |
| Listen-Kurzform | max. ~160 Zeichen, einzeilig |
| Detail | max. 500 Zeichen Anzeige; bei Kürzung Kennzeichnung „gekürzt“ |
| Maskierung (Anzeige) | serverseitig vor Inertia: case-insensitive Redaction von Mustern wie `password=…`, `secret=…`, `Bearer …`, `api[_-]?key=…`; Rest unverändert |
| Payload | nur ausgewählte NOT-001-Felder; **kein** Dump |
| Suppress-Payload | nur `event_type`, `reason` (als Label), IDs, Auftrag; keine freien Exception-Texte (gibt es dort nicht) |

Autorisierung fail-closed: Sales/Disposition/PM/Management/Gast → 403 auf Index und Detail.

---

## 6. Architektur und Tests (DoD-Skizze)

### 6.1 Erwartete Dateien

| Bereich | Dateien |
|---|---|
| Gate | `AppServiceProvider` + `User::canViewNotificationOutbox()` (nur Admin) |
| HTTP | `NotificationOutboxAdminController` (index/show), optional schmaler Suppress-Index |
| Query | kleiner Read-Service/Query-Builder (Filter, Pagination, Source-Resolve, Error-Present) |
| Routes | unter Admin-Gruppe, zusätzlich `can:view-notification-outbox` |
| Hub | Modul-Kachel in `AdministrationHubController` / `administration/index` |
| UI | `resources/js/pages/administration/notification-outbox/index.tsx`, `show.tsx` (+ optional suppress) |
| Tests | Feature Auth+/-; Filter/Pagination; Failed/Pending/Sent; fehlende Quelle; Error-Masking; Suppress getrennt |
| Docs | workflows, backlog BL-P9-02e, fortschritt, ux-ui-gate (bei Umsetzung) |

Wiederverwenden: `PageHeader`, Tabellen-/Filter-Muster aus Kombinationen/Sonderfreigaben,
`NotificationOutbox` Model/Enum, bestehende Event-Konstanten aus SalesInquiry/Approval-Publisher.
**Keine** neue Monitoring-Plattform, kein zweiter Dispatcher.

### 6.2 Query-/Filtervertrag

- Nur `channel = email` und Event in Deliverable-Whitelist (andere Events, falls später
  persistiert, bleiben unsichtbar bis freigegeben)
- Sortierung: `id` DESC (neueste zuerst) oder `coalesce(last_attempt_at, created_at)` DESC
- Pagination serverseitig; Filter als Query-Params
- Source-Resolve eager/batched, N+1 vermeiden

### 6.3 Migration

**Keine** Schema-Migration für den Start. Optional später Index
`audit_events(action, created_at)` nur bei Lastnachweis.

### 6.4 Tests

| Positiv | Negativ / Kante |
|---|---|
| Admin sieht Index/Detail | Management/Sales/Disposition/PM/Gast 403 |
| Filter Status + Event | Unbekanntes Event/Status → Validation oder ignore-to-default |
| `failed` mit maskiertem Fehler | Roh-Payload nicht in Props |
| `pending`/`queued`/`sending`/`sent` Labels | Mutation-Endpunkte existieren nicht |
| Auftragslink bei vorhandener Quelle | Fehlende Quelle → kein Link, Nummer aus Payload |
| Suppress-Liste getrennt beschriftet | Suppress nicht als `failed` Outbox |
| Isolierter Browser-Smoke (eigener Port/SQLite) | Kein echter SMTP, kein Dispatch, kein Dev-DB-Write |

---

## 7. Offene fachliche Entscheidungen

| # | Frage | Optionen | Empfehlung |
|---|---|---|---|
| 1 | Zugriff | **A1** nur Admin · **A2** Admin+Management (`access-administration`) | **A1** – NOT-002 sagt „Admin“; analog Sonderfreigaben; Management sieht Hub, aber nicht dieses Modul |
| 2 | Suppression im Slice | **S1** eigener Tab/Liste · **S2** bewusst später · **S3** in Outbox-Liste mischen | **S1** – operativ nützlich, klar getrennt; S3 verboten (Verwechslung mit SMTP) |
| 3 | Default-Filter Outbox | **D1** `failed` · **D2** alle Status | **D1** – NOT-002-Fokus; alle Status weiter anwählbar |
| 4 | Gate-ID / Slice-Name | `PO-NOT002-ADMIN-1` / `BL-P9-02e` | so übernehmen, sofern PO zustimmt |
| 5 | Manueller Resend | nein in diesem Slice / ja später | **nein** – Katalog verlangt Sichtbarkeit + automatisches Retry (bereits da) |

Nach Klärung von 1–2 (und Gate) → Readiness auf **READY** heben und Umsetzungsauftrag.

---

## 8. Scope und Ausschlüsse

### In Scope (Vorschlag)

- Rein lesende Admin-Outbox-Liste + Detail
- Filter Status + unterstützte Events
- Deutsche Statuslabels
- Sichere Fehlerdarstellung
- Auftragslink soweit auflösbar
- Optional/empfohlen: Suppress-Liste getrennt
- Auth-Tests + isolierter Smoke

### Bewusst nicht

- Retry-/Resend-/Dispatch-Buttons
- Statusmutation, Löschen, Bearbeiten
- Neue Notification-Ereignisse (Submit, Invalidierung, Material, …)
- In-App-Kanal
- Rollenverteiler / Empfängerwahl
- Allgemeine Audit-Explorer-UI
- Permission-Engine
- Migration ohne Bedarf
- Worker/Scheduler starten, Testmails, Dev-Outbox-Dispatch

---

## 9. Bewusst nicht in diesem Docs-Auftrag

- Keine Feature-Implementierung / kein Commit/PR/Merge/Deploy  
- Kein Mailversand, kein Dispatch, keine Worker  
- `fortschritt.md` / „erledigt“-Flags unverändert  
- Entwürfe nicht als genehmigt gekennzeichnet  

## Entwurfspfad

`dispo-wt-docs-admin-notification-outbox-readiness/docs/readiness/BL-P9-02e-admin-notification-outbox-read-2026-10-05.md`  
Branch: `docs/admin-notification-outbox-readiness` @ `a4ba38c`
