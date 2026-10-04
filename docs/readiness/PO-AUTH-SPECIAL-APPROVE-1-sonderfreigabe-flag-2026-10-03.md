# Readiness: PO-AUTH-SPECIAL-APPROVE-1 – Sonderfreigabe-Flag verdrahten

Status: **READY** (PO A Option 1 akzeptiert; Vergabeweg festgelegt)  
Stand: 4. Oktober 2026  
Basis: `origin/main` @ `4e975a347754235208cd56114d9da5fe9fa6045e`  
Entscheidung: `docs/entscheidungen/PO-AUTH-RIGHTS-1-rechtekonflikte-entscheidungsvorlage.md` (**Akzeptiert**)

## 0. Gate

| Voraussetzung | Status |
|---|---|
| PO A Option 1 bestätigt | **ja** |
| Admin/GF immer sonderfreigabeberechtigt | ja |
| Vier-Augen (`AUTH-004`) | ja |
| `AUTH-005` außerhalb | ja |
| Keine Permission-Engine | ja |
| Admin-Vergabe-/Entzugsweg spezifiziert | **ja** (schmale Admin-UI) |

## 1. Fachvertrag

| Akteur | Special Approve/Reject |
|---|---|
| Admin (nicht Ersteller) | ja |
| GF (nicht Ersteller) | ja |
| Vertrieb mit `can_special_approve` (nicht Ersteller) | ja |
| Vertrieb ohne Flag / Disposition / PM | nein |
| Ersteller (jede Rolle) | nein |

### Vergabe-/Entzugsweg (verbindlich)

| Regel | Festlegung |
|---|---|
| Oberfläche | `/administration/sonderfreigaben` (Inertia), Hub-Modul nur für Admin |
| Mutation | `PUT …/sonderfreigaben/{user}` nur Feld `can_special_approve` |
| Wer | nur Rolle Admin (`manage-special-approve-rights`); Management allein nein |
| Ziel | nur Rolle Vertrieb |
| Self-Service / Selbständerung | verboten |
| Audit | `user.special_approve_right.updated` mit Actor, Ziel, alt/neu |
| Nebenfelder | Rollen/Passwort/andere Rechte werden nicht geschrieben |
| `can_special_approve` | aus Model-`fillable` entfernt; nur Writer `forceFill` |

### Parallelität Entzug ↔ Entscheidung

1. Grant/Revoke: Transaktion + `lockForUpdate` auf Zielnutzer.  
2. Approve/Reject: in der Entscheidungstransaktion zuerst Actor `lockForUpdate`,
   dann Order; erneute Gate-Prüfung mit frischem Flag.  
3. FormRequest/Show: `User::refresh()` vor Capability-Check.

Geprüft (MySQL `dispo_test`, Barrier-Worker, keine Sleep-Sync):

| Reihenfolge | Erwartung |
|---|---|
| Entzug hält Sales-Zeile → Entscheidung wartet → nach Commit abgewiesen | Auftrag/Approval/Entscheidungs-Audit unverändert |
| Entscheidung hält Sales-Zeile → Entzug wartet → Entscheidung ok, dann Entzug | Historie gültig; weitere Entscheidungen mit stale Actor scheitern |

Browser-Smoke Port 8026: getrennte Contexts Admin/Sales; nach Entzug **keine** Neuanmeldung;
staler Approve-Dialog wird serverseitig abgewiesen; Reload ohne Approve/Reject-Capabilities.

Bereits abgeschlossene Freigaben bleiben historisch unverändert.

## 2. Migration / Bestand

- Keine Schema-Migration (Spalte vorhanden).
- Dev-DB inventarisiert read-only: alle Nutzer `can_special_approve = 0` (Stand Analyse).
- Andere Umgebungen: **ungeprüft** – vor Deploy Inventur; keine Massenaktivierung in diesem Auftrag.
- Dieser Auftrag setzt keinem Dev-Nutzer ein Recht.

## 3. DoD

- [x] Policy liest Flag für Sales
- [x] Vier-Augen unverändert
- [x] Approve/Reject/UI konsistent
- [x] Admin-Vergabe/Entzug + Audit + Selbstschutz
- [x] Positiv-/Negativtests
- [x] Isolierter Browser-Smoke (Port 8026, getrennte Contexts, Sitzung ohne Relogin)
- [x] MySQL-Parallelität Entzug↔Entscheidung (beide Reihenfolgen) + Service-Stale-Actor
- [x] Docs Matrix/Entscheidung nachgezogen
- [x] CI auf Feature-HEAD (`ci`/`mysql`/`e2e-spt008`)
- [ ] Review/Merge (nicht Teil dieses Auftrags); Staging-/Prod-Inventur vor Deploy
