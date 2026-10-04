# PO-AUTH-RIGHTS-1 – Rechtekonflikte Freigabe / Draft / Force-Complete

Status: **Akzeptiert** (PO bestätigt Optionen A1 / B1 / C1 / D1)  
Stand: 4. Oktober 2026  
Basis: `origin/main` @ `4e975a347754235208cd56114d9da5fe9fa6045e` (nach PR #116)  
Umsetzung Sonderfreigabe: **PO-AUTH-SPECIAL-APPROVE-1**  
IDs: `AUTH-001`–`AUTH-005`, `AUTH-007`, `STA-006`, `APR-*`, `COM-002`, `BL-P1-03`, `BL-P8-01b`, `BL-P8-02d`, `BL-P9-02a`

## Entscheidung (PO bestätigt)

| Thema | Verbindlich |
|---|---|
| A Sonderfreigabe | **Option 1:** Admin/GF immer; Vertrieb nur mit `can_special_approve`; Approve+Reject; Ersteller nie; Disposition/PM auch mit Flag nicht; Admin-Vergabe-/Entzugsweg |
| B PM Extra-Recht | **Option 1:** `can_view_dispo_orders` = Dispo-Ansicht/Kommentare, **keine** Freigabe |
| C Disposition Draft | **Option 1:** Draft Create/Update/Submit/CC = Sales/Admin/GF; Disposition operativ **nach** Freigabe |
| D Force-Complete | **Option 1:** nur Admin (`STA-006`); regulärer Abschluss unverändert |

`AUTH-005` bleibt außerhalb (nicht umgesetzt).

## Kurzfazit nach Entscheidung

| Thema | Status |
|---|---|
| A Sonderfreigabe-Flag | **entschieden** → Implementierung PO-AUTH-SPECIAL-APPROVE-1 |
| B PM Extra-Recht | **entschieden** → Docs-Präzisierung |
| C Disposition Draft | **entschieden** → Docs-Präzisierung Matrix „operativ“ |
| D Force-Complete | **entschieden** → Docs-Korrektur Matrix (GF ohne Force-Complete) |

## Historische Analysefundstellen

Siehe frühere Entwurfsfassung und Audit-Nachzug; Code-Ist vor Slice:
`DispoOrderPolicy::approveSpecial` nur Admin/GF, Flag ungelesen; keine Benutzer-Admin-UI.

## Ausdrücklich ausgeschlossen

- AUTH-005 Zwei-Protokoll-Bedienvorgang
- Allgemeine Benutzer-/Permission-Engine
- Löschen der Spalte `can_special_approve`
- DSP-DCP-001, Calc-Lifecycle
- Automatische Aktivierung von Bestandsflags
