# Readiness: BL-P9-02d – Freigabe-Mails über bestehende Outbox/SMTP

Status: **READY** (PO-Teilfreigabe `PO-APPROVAL-NOTIFY-1` akzeptiert; Slice A)  
Stand: 4. Oktober 2026  
Auditbasis: `origin/main` @ `9962924303fb878dec99870d0c06cdfa9b937037` (nach PR #120)  
IDs: `NOT-001`, `NOT-002` (Teil), `APR-001`–`APR-004`, AUTH-004, Freigabe-Workflow  
Entscheidung: `docs/entscheidungen/PO-APPROVAL-NOTIFY-1-freigabe-entscheidungsmail.md` (**Akzeptiert**)  
Vorbilder: `PO-BLP902B-1` / `PO-BLP902C-1`

Der ursprüngliche Entwurf (READY MIT VORBEDINGUNGEN) bleibt als Ist-Analyse
erhalten; die Vorbedingungen sind durch die PO-Teilfreigabe geschlossen.

## 0. Gate

| Voraussetzung | Status |
|---|---|
| Outbox-Fundament `notification_outbox` (BL-P1-05a) | vorhanden |
| Ask/Answer-Enqueue + Suppress (BL-P9-02b) | vorhanden |
| Ask/Answer-SMTP Delivery (BL-P9-02c) | vorhanden |
| Freigabe Submit/Approve/Reject Services | vorhanden |
| Submit-Empfänger | **bewusst offen** (S4: keine Submit-Mail) |
| Approve/Reject-Empfänger | **verbindlich:** nur `submitted_by_id` |
| UX-GATE-D / PO-Teilfreigabe | **`PO-APPROVAL-NOTIFY-1` akzeptiert** |
| Ablehnungsbegründung im Mail-Payload | **nein** (keine Erweiterung) |

**Readiness-Urteil:** **READY** für Slice A (Approve + Reject, Regular + Special).

Technisch: vorhandene Outbox/SMTP-Schiene erweitern, **keine Migration**.

## 1. Verbindliche Anforderungen (Ist)

| Quelle | Aussage |
|---|---|
| Katalog §22.2 / `NOT-001` | Mail enthält Vorgangsnummer, Kunde/Kampagne, Ereignis, handelnde Person, internen Link; keine unnötigen sensiblen Anlagen |
| Katalog `NOT-002` | E-Mail-Versandfehler dürfen den fachlichen Statuswechsel **nicht** zurückrollen; Fehler protokollieren und erneut versuchen. Admin-Sicht bleibt **offen** → NOT-002 nicht vollständig erledigt. |
| Katalog §22.2 Ereignisliste | u. a. Freigabe angefordert / freigegeben / abgelehnt – **angefordert** bleibt in diesem Slice offen |
| `PO-BLP902B-1` | Outbox-Write in Fach-TX; Suppress ohne Nutzer-UI; 1:1; kein Gruppen-Fallback; kein Self |
| `PO-BLP902C-1` | SMTP after Commit; tries/backoff/stuck; At-least-once; Mail nur NOT-001-Felder |
| `PO-APPROVAL-NOTIFY-1` | siehe Entscheidungsdokument |

## 2. Genehmigter Slice (Ist + Vertrag)

Gemeinsam:

- Service: `DispoOrderApprovalService::approve` / `reject`
- Outbox in derselben `DB::transaction`
- Linkziel: Route `dispo-orders.show` (authentifiziert)
- Regular vs. Special: eingefroren am Request (`kind`); Label im `event_label`
- Ereignisidentität: `source_type = dispo_order_approval_request`, `source_id` = Request-ID
- Neuer Zyklus = neue Request-ID = eigene Benachrichtigung
- Historische Entscheidungen: unverändert, keine Nachsendung

### 2.1 Freigabe erteilt (`approve`)

| Punkt | Vertrag |
|---|---|
| Event-Type | `dispo_order.approval.approved` |
| Empfänger | `submitted_by_id` |
| Actor | frisch geladener, autorisierter Entscheider |
| Auftragsdaten | Dispo-Stand zum Enqueue (nicht später veränderte Calc-Daten) |

### 2.2 Freigabe abgelehnt (`reject`)

| Punkt | Vertrag |
|---|---|
| Event-Type | `dispo_order.approval.rejected` |
| Empfänger | `submitted_by_id` |
| Begründung | nicht in Payload/Mail; Hinweis, den Auftrag zu öffnen |

### 2.3 Nicht in diesem Slice

- Submit (`dispo_order.approval.submitted`)
- Invalidierung
- weitere Status-Mails
- Admin-Outbox-UI

## 3. Empfänger und Suppression

- Nutzer muss ladbar sein und eine gültige Mailadresse haben.
- Empfänger = Entscheider → keine Mail (`self_notification`).
- Fehlender Nutzer / ungültige Adresse → keine Mail.
- Kein Fallback auf Ersteller, Mediaberater oder Rollenverteiler.
- Audit-Action: `dispo_order.approval.notification_suppressed` inkl. Grund.
- Suppression ist kein Outbox-Schreibfehler und blockiert die Fachentscheidung nicht.
- Keine Empfänger-Rollenprüfung.

## 4. Outbox- und Delivery-Vertrag

| Thema | Vertrag |
|---|---|
| Tabelle | `notification_outbox` – keine Migration |
| Enqueue | `NotificationOutboxWriter::enqueue` in der Fach-TX |
| Outbox-Write-Fail | rollt Fach-TX (Status, Request, Entscheidungs-Audit) |
| SMTP-Fail | Status bleibt; Retry/Failed wie 02c |
| Payload | bestehende NOT-001-Keys; kein `rejection_reason` / `detail_text` |
| Idempotenz | bestehender Key Event+Source+Channel+Recipient |
| Delivery | Whitelist um die zwei Approval-Events erweitern; Ask/Answer unverändert |
| Job/Command | bestehende Klassen/Signaturen behalten (queued Jobs kompatibel) |
| Parallelität | ein Dispatcher; Claim/Lock/Retry/Stuck-Recovery unverändert |
| At-least-once | inkl. möglicher Doppelsendung – wie 02c |

## 5. Mailinhalt

Nur Text, keine Anhänge, keine öffentlichen Links, keine Auto-Anmeldung.

Subject/Label-Beispiele:

- `Freigabe erteilt (reguläre Freigabe) – {DA-…}`
- `Freigabe erteilt (Sonderfreigabe) – {DA-…}`
- `Freigabe abgelehnt (reguläre Freigabe) – {DA-…}`

Body: Auftragsnummer, Kunde/Kampagne, Ereignis, Entscheider, Zeitpunkt, interner Link.
Bei Ablehnung zusätzlicher Satz, die Begründung in der Anwendung zu öffnen.

## 6. Tests (DoD)

| Positiv | Negativ / Kante |
|---|---|
| Approve/Reject → korrekte Outbox | Self / fehlender Empfänger / ungültige Mail → Suppress-Audit, Entscheidung ok |
| Regular und Special | Ablehnungsbegründung fehlt in Payload und gerenderter Mail |
| Empfänger = `submitted_by_id` auch wenn ≠ Ersteller/Advisor | Outbox-Write-Fail rollt Entscheidung/Status/Request/Audit |
| Neuer Zyklus → eigene Notification | Wiederholte/stale Requests → keine zusätzliche Mail |
| Delivery inkl. Ask/Answer | SMTP-Fehler belässt Entscheidung, Retry/Failed |
| Isolierter Smoke Entscheidung → Outbox → gerenderte/gefakte Mail | Fach-Rollback hinterlässt keine zustellbare Outbox |

MySQL: bestehende parallele Approve-Garantie plus genau eine Outbox-Zeile.
Keine echten E-Mails, kein Dev-Dispatch, kein Zugriff auf `dispo_mat_core` / Port 8000.

## 7. Datenmodell / Migration

**Keine** Schema-Migration.

## 8. Bewusst nicht

- Submit-Empfänger, Invalidierungsmails, Admin-Outbox-UI
- allgemeine Notification-Engine, Permission-Änderung
- NOT-002 als vollständig erledigt
- Feature-Versand gegen Dev-DB

## Übernahme

Entwurfspfad (Docs-Worktree, unverändert als Vorlage):
`dispo-wt-docs-approval-mail-outbox-readiness/docs/readiness/BL-P9-02d-approval-mails-outbox-2026-10-04.md`

Umsetzung: Branch `feat/po-approval-notify-1`.
