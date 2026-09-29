# AT-13 – Freigabeinvalidierung (Kundenbestätigung) – Design-/Gate-Vorschlag

Status: **Vorgeschlagen** (kein Produktionscode; UX-GATE-D-Freigabe ausstehend)  
Stand: 29. September 2026  
Basis: `origin/main` @ `363def9932e6473904cfe09a02fa4a8193804c68` (nach PR #104)  
IDs: `AT-13`, `APR-004`, `APR-001`–`APR-003`, `UPL-001`, `BL-P7-02` Rest

## Zweck

Eng begrenzter Kandidat für den ersten Invalidierungs-Slice:

| Teil | Kandidat |
|------|----------|
| Auslöser | Admin **archiviert** oder **ersetzt** eine Kundenbestätigung (`customer_confirmation`), **nachdem** der Auftrag genehmigt wurde |
| Wirkung | Der **vollständige** erforderliche Freigabeprozess muss erneut durchlaufen werden (`APR-004`) |
| Historie | Alte `dispo_order_approval_requests` / Zyklen bleiben append-only erhalten |
| Audit | Invalidierung mit Akteur und Ursache |
| Nicht-Auslöser | Allgemeine Kommentare; additive (nicht ersetzende) Uploads |
| Außerhalb | Preis-/Rabatt-Edit am genehmigten Dispo (keine Editierfläche); `invoice_end_months`; Calc-only; Freigaberückzug; `AUTH-005`; Notifications; andere Uploadklassen |

**Stopp vor Feature-Code:** Der **Zielstatus nach Invalidierung** lässt sich aus dem bestehenden Status-/Submit-/CC-Modell **nicht zweifelsfrei** ableiten (siehe unten). Ohne PO-Entscheidung keine Implementierung.

## Ist-Stand (Codebelege auf `main`)

| Fakt | Beleg |
|------|--------|
| Submit nur `draft` → `awaiting_sales_approval` | `DispoOrderApprovalService::submit` + `DispoOrderStatusTransition::assertCanTransition` |
| CC-Upload und CC-Ausnahme nur im **Draft** | `DispoOrderUploadService::uploadCustomerConfirmation`; `DispoOrderCustomerConfirmationService::update`; Policy `update`/`uploadCustomerConfirmation` |
| Submit braucht aktive CC (Upload **oder** Ausnahme) | `resolveSubmitEvidence` / `assertReadyForSubmit` |
| Keine Kante operativ/post-approval → `draft` oder → `awaiting_sales_approval` | `DispoOrderStatusTransition::allowedTargets` |
| `approval_rejected` ist terminal am **selben** Auftrag; Nachbesserung = **neuer** Entwurf | `DispoOrderRevisionRules`, `DispoOrderWriter::createRevision` |
| Admin-Archiv von Uploads **ohne** Statusgate; nach Approve möglich; **keine** Invalidierung | `DispoOrderUploadService::archive` |
| CC-**Replace** nach Genehmigung derzeit **unmöglich** (nur Draft) | Upload-Service Draft-Guard |
| UX-GATE-D: Freigabeinvalidierung **nicht** freigegeben | `docs/ux-ui-gate.md`, `docs/fortschritt.md` |

## Warum der Zielstatus nicht eindeutig ist

Nach Archivierung der einzigen aktiven Kundenbestätigung fehlt die Submit-Evidenz. Ein erneuter vollständiger Zyklus braucht laut Ist-Gates:

1. wieder eine CC (Upload/Ausnahme) setzen können, und  
2. erneut `submit` → Freigabe → Disposition.

Beides ist im bestehenden Modell an Status **`draft`** und die Kante **`draft` → `awaiting_sales_approval`** gebunden. Ein Rücksprung aus `at_disposition` / operativen Statusen nach `draft` **existiert nicht**. Andere Zielzustände erfordern neue Semantik (siehe Optionen).

`approval_rejected` ist fachlich **Ablehnung einer offenen Freigabe**, nicht Invalidierung einer **bereits erteilten** Freigabe; Revision erzeugt einen **neuen** Auftrag – das widerspricht dem Kandidaten „derselbe Auftrag, Historie append-only, vollständiger Zyklus erneut“.

## Optionen (PO)

### Option A – Zurück auf `draft`

- Invalidierung setzt Status auf `draft` (neue Transition-Kanten nötig).
- Danach greifen bestehende CC-Upload-/Ausnahme- und Submit-Pfade unverändert.
- **Pro:** Wiederverwendet Submit/CC ohne Policy-Erweiterung; passt zu „voller Zyklus“.
- **Contra:** Erfindet Statuskanten; operativer Fortschritt (`in_progress`, Material, …) geht verloren bzw. muss explizit verworfen werden.
- **Zusätzlich zu klären:** Welche Quellstatus? Nur `at_disposition`? Auch `in_progress` / Material / `disposed` / `completed` / `sales_inquiry`? `cancelled` nein?

### Option B – Operativen Status behalten, Freigabe nur „ungültig“ markieren

- Status bleibt z. B. `at_disposition` / `in_progress`; Approval-Historie + Flag/Audit „ungültig“.
- Operative Weiterarbeit und/oder Abschluss bis zur erneuten Freigabe sperren.
- **Pro:** Kein Zurücksetzen des Dispo-Fortschritts.
- **Contra:** Neue Orthogonalität Status vs. Freigültigkeit; CC setzen und erneutes Einreichen **außerhalb** Draft; UI-Hinweis ohne neue Admin-Oberfläche, aber neue Fachregel.

### Option C – Direkt nach `awaiting_sales_approval`

- Nur sinnvoll, wenn in derselben Transaktion bereits **neue** Submit-Evidenz existiert (Replace) und ggf. Auto-Submit.
- Reines **Archivieren** ohne neue Evidenz: Submit-Gate scheitert → Zustand ohne gültige Freigabe und ohne CC.
- **Contra:** Erfindet Auto-Submit und/oder `awaiting` ohne Evidenz; deckt Archiv-allein nicht sauber ab.

### Option D – Wie Ablehnung: terminal + neuer verknüpfter Entwurf

- **Contra:** Semantik von `approval_rejected`/Revision; nicht „derselbe Auftrag erneut freigeben“. Für diesen Kandidaten **nicht empfohlen**.

## Empfehlung (nicht bindend)

**Option A**, eingeschränkt auf frühe Post-Approval-Status (Empfehlung Start: mindestens `at_disposition`; weitere operative Status nur nach expliziter PO-Liste), weil nur so der **bestehende** Submit-/CC-Vertrag ohne Parallelwelt greift.

Das bleibt eine **PO-Entscheidung** inkl. neuer Transition-Kanten und UX-Gate-Teilfreigabe – kein automatischer Ableitungsschluss aus dem Code.

Zusätzlich muss der PO festlegen:

1. UX-GATE-D Teilfreigabe Kennung (Vorschlag: `PO-AT13-CC-1` / Slice `BL-P7-02a`) – ja/nein und Scope.  
2. Ob **Replace** nach Genehmigung im selben Slice erlaubt wird (heute Draft-only) oder nur **Archiv** der erste Auslöser ist.  
3. Ob bei Option A der operative Status fortfällt (Reset) oder Invalidierung in späteren Status verboten ist.

## Bewusst nicht in diesem Vorschlag

- Preis-/Rabatt-Invalidierung, `invoice_end_months`, Calc-only  
- Freigaberückzug (`APR-003`), `AUTH-005`  
- Weitere Status-Mails, In-App, Admin-/Audit-/Outbox-UI  
- Calendar-/Budget-Vorlagen, Abbinder  

## Nächster Schritt nach PO-Antwort

1. Entscheidung in Blocker-Log + UX-GATE-D Teilfreigabe eintragen.  
2. Erst dann Feature-Branch: zentraler Invalidierungspfad + CC-Archiv/(Replace) + Tests (SQLite Feature, MySQL Concurrency, Regression Kommentar/additiv/E7).  
3. Ohne Entscheidung: **kein** Produktionscode.
