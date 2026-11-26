# Go-live Masterplan

**Dokument-ID:** `GO-LIVE-MASTERPLAN`  
**Version:** `1.0.0` (2026-11-26)  
**Status:** **verbindlich ab 26.11.2026**  
**Quelle:** PO-MASTER-AUDIT / verbindliche PO-Entscheidungen vom 26.11.2026  
**Basis-SHA (Dokumentationsstand):** `2283d5fc0c836998e2c59253d24a821373762f79` (`origin/main` nach PR #278)  
**Führendes Dokument:** dieses File ersetzt widersprechende frühere Go-live-Abgrenzungen für den weiteren Projektverlauf. Historische Entscheidungen bleiben erhalten und werden nicht rückwirkend gelöscht.

---

## 0. Verbindliche Arbeitsweise

Bei jedem zukünftigen Arbeitspaket:

1. Diesen Masterplan lesen.
2. Arbeitspaket der entsprechenden Phase zuordnen.
3. Abhängigkeiten und Acceptance-Gates prüfen.
4. Ausschließlich den freigegebenen Umfang bearbeiten.
5. Nach Abschluss diesen Masterplan aktualisieren (Status, PRs, SHAs, nächster Schritt).
6. Nächsten sinnvollen Arbeitsschritt empfehlen.
7. **STOP** bis zum nächsten PO-GO.

Keine isolierten REFOCUS-Arbeiten außerhalb dieses Masterplans ohne ausdrückliche PO-Freigabe.

### Statuswerte (Arbeitspakete / Phasen)

| Status | Bedeutung |
|---|---|
| `OPEN` | Freigegeben im Scope, noch nicht gestartet |
| `READINESS` | Fachliche/technische Klärung läuft oder steht an |
| `IN_PROGRESS` | Aktive Implementierung oder Dokumentation |
| `BLOCKED` | Wartet auf Abhängigkeit, PO-Entscheidung oder Gate |
| `IMPLEMENTED` | Technisch umgesetzt / dokumentiert – **nicht** abgenommen |
| `VERIFIED` | Tests/Belege vorhanden – **nicht** fachlich freigegeben |
| `ACCEPTED` | Explizite PO-/Fachabnahme erteilt |

**`IMPLEMENTED` ≠ `ACCEPTED`.** Eine Phase gilt erst nach Erfüllung der dokumentierten Abnahmekriterien als abgeschlossen.

### Harte Betriebsgrenzen (dauerhaft)

- Kein eigenmächtiger Merge
- Kein operativer Staging-Apply ohne PO-GO
- Kein Deployment ohne explizite Freigabe
- Keine realen Datenänderungen außerhalb freigegebener Slices
- Keine automatische Testdatenlöschung
- Kein vorgezogener CRM-Transfer in Slice 6b
- Keine eigenmächtige Implementierung von Social Media, Events oder Barter ohne gesonderte Readiness und PO-GO

---

## 1. Verbindliches Zielbild

Go-live auf **Produktion** (`dispo.more-marketing.de`) mit:

- freigegebenem Code
- vollständigen, geprüften Stammdaten (inkl. CRM)
- vollständiger Buchbarkeit aller vorgesehenen Werbemittel × Inventare × Berechnungsmethoden
- Social Media, Events, Barter und Contest **im Go-live-Scope**
- vollständigen Produktionspreisen und Werbepreislisten für den vorgesehenen Buchungsumfang (inkl. 2027)
- Mitarbeiter-UAT auf Staging nach kontrollierter Testdatenbereinigung
- Staging als dauerhaft eigenständige Testumgebung auch nach Go-live

### Umgebungen

| Umgebung | Domain / DB | Rolle |
|---|---|---|
| **LOKAL** | DB `dispo_mat_core` | Entwicklung; derzeitige Quelle fachlicher Stammdaten |
| **STAGING** | `staging.dispo.more-marketing.de` + separate Staging-DB | Vollständige geprüfte Stammdaten; eigene Testvorgänge und Benutzer; kontrollierter Mailversand; fachliche Abnahme und Mitarbeiter-UAT |
| **PRODUKTION** | `dispo.more-marketing.de` + separate Produktiv-DB | Freigegebener Code und freigegebene Stammdaten; **keine** lokalen/Staging-Testvorgänge |

---

## 2. Verbindliche PO-Entscheidungen (PO-01 … PO-07)

### PO-01 – CRM-Stammdaten

Alle echten, geprüften CRM-Stammdaten der lokalen Entwicklungsumgebung sollen nach Staging und später nach Produktion übernommen werden (aktuell ca. **3.460** CRM-Accounts sowie fachlich erforderliche abhängige Stammdaten).

Vor der Übernahme Pflichtprüfungen:

- Vollständigkeit und Datenqualität
- Echt- vs. Testdaten
- Dubletten und natürliche Schlüssel
- FK-Abhängigkeiten
- Datenschutz und Zugriffsschutz
- Eigenständiger, wiederholbarer Transfermechanismus bzw. Erweiterung des bestehenden Transfervertrags

**CRM-Transfer nicht ungeprüft in Slice 6b integrieren.** Eigenständige Readiness und Implementierung.

### PO-02 – Bewegungs- und Testdaten

Lokale Kalkulationen, Dispoaufträge und zugehörige Bewegungsdaten werden **niemals** als Bestandteil des Stammdatentransfers übernommen.

Bestehende Staging-Testvorgänge bleiben während technischer und fachlicher Projektabnahme zunächst erhalten und werden **unmittelbar vor Mitarbeiter-UAT** kontrolliert bereinigt.

Bereinigung erfordert: separates Verfahren, Backup, Dry-Run, Schutz der Stammdaten, explizites PO-GO. **Keine automatische Datenlöschung.** Spätere Mitarbeiter-Testvorgänge gehen nicht nach Produktion.

### PO-03 – Staging-Zugriff

Staging verwendet **ausschließlich** den regulären App-Login. **Keine zusätzliche Basic Auth.**

Die frühere Audit-Einstufung „fehlende Basic Auth“ ist **kein Go-live-Blocker** mehr.

Stattdessen zu prüfen:

- Authentifizierung und Autorisierung
- Login-Rate-Limiting
- Sichere Passwortverwaltung und Passwort-Reset
- Benutzeraktivierung/-deaktivierung
- Rollen und Zugriffsrechte
- Session-Sicherheit
- HTTPS
- Schutz vor Suchmaschinenindexierung
- `APP_DEBUG` deaktiviert
- Schutz personenbezogener CRM-Daten

### PO-04 – Produktionspreise 2027

Alle für die vorgesehenen Werbemittel erforderlichen Produktionspreise 2027 müssen **vor Beginn der Mitarbeiter-UAT** vollständig gepflegt sein.

Fehlende Preise dürfen nicht erfunden, geschätzt oder automatisch mit Null befüllt werden.

Liefergegenstand: vollständige Liste fehlender Preise (Medium, Inventar, Preisjahr, Preisposition), getrennt nach Produktionspreisen und regulären Werbepreislisten. Beide müssen für den vorgesehenen Buchungsumfang vollständig sein.

### PO-05 – Erweiterter Go-live-Scope

**Social Media, Events, Barter und Contest** gehören verbindlich zum Go-live-Scope.

Sie dürfen nicht mehr pauschal als V2 / Future / Stufe 3 / nachgelagerte Verbesserung aus dem Go-live ausgeschlossen werden.

Je Bereich gesonderte Readiness (Anforderungen, vorhandene Entscheidungen, Medien/Inventare, Werbemittel/Felder, Berechnungsmethoden, Preise/Regeln, Kalkulation, Speichern/Wiederöffnen, Dispo-Übernahme, Freigaben/Revisionen, Admin-Pflege, automatisierte Tests, fachliche Staging-Abnahme).

**Keine Berechnungslogik eigenmächtig definieren**, wenn fachliche Entscheidungen fehlen. Offene Punkte gesammelt dem PO vorlegen.

### PO-06 – Vollständige Buchbarkeit

Alle zum Go-live vorgesehenen Werbemittel und fachlich vorgesehenen Berechnungsmethoden müssen freigeschaltet, korrekt konfiguriert und getestet sein.

Liefergegenstand: Buchbarkeitsmatrix **Inventar × Werbemittel × vorgesehene Berechnungsmethode**. Keine Beschränkung auf Kernkombinationen oder Radio Hamburg. Nicht fachlich vorgesehene Kombinationen müssen nicht künstlich freigeschaltet werden.

### PO-07 – Umgebungen und Domains

Siehe Abschnitt 1 (Lokal / Staging / Produktion). Staging bleibt nach Go-live als eigenständige Testumgebung erhalten.

---

## 3. Go-live-Scope (Ziel)

### Im Go-live-Scope (verbindlich)

- Bestehender Spot-/SWF-/OA-/Free-Position-Kern soweit fachlich vorgesehen und freigeschaltet
- **Contest**
- **Social Media**
- **Events**
- **Barter / Gegengeschäft**
- Alle fachlich vorgesehenen Berechnungsmethoden für den Buchungsumfang
- Vollständige Stammdaten inkl. CRM (nach eigenem Transferverfahren)
- Produktionspreise und Werbepreislisten für den vorgesehenen Umfang (inkl. 2027)
- Staging-Abnahme, Mitarbeiter-UAT, Produktionsvorbereitung, freigegebener Go-live

### Explizit nicht durch diesen Masterplan freigegeben / unverändert getrennt

- Eigenmächtige Implementierung ohne Readiness/PO-GO
- Übernahme von Bewegungs-/Testdaten Lokal → Staging → Produktion
- Automatische Datenlöschung
- Merge / Deploy / Staging-Apply ohne PO-GO
- Budget-Produktisierung OA/Free/Mixed und vollständiger Standardangebots-Produktumfang bleiben an bestehende Entscheidungen `PO-OA-BUDGET-V2` / `PO-STD-V2` gebunden, **solange** der PO keine neue Entscheidung trifft (kein stilles Zurückholen)

---

## 4. Ablösung früherer Scope- und Priorisierungsentscheidungen

Historische Dokumente bleiben bestehen. Widersprechende Go-live-Abgrenzungen sind ab 26.11.2026 **für den weiteren Verlauf abgelöst**.

| Historisches Dokument | Was abgelöst wird | Was weiter gilt |
|---|---|---|
| [`PO-GOLIVE-CUT-1-DEC`](../entscheidungen/PO-GOLIVE-CUT-1-DEC-operative-v1.md) | Einstufung Social/Events (und verwandter Cluster) als **Stufe 3 / nicht Tag-1**; pauschale Go-live-Abgrenzung gegen erweiterten Katalog | Historischer Priorisierungskontext; bereits umgesetzte Kernmedien/Methoden; Fachparameter bleiben Umsetzungsvoraussetzungen |
| [`PO-V1-CLOSURE-1`](../entscheidungen/PO-V1-CLOSURE-1-backlog-status.md) | Social/Events = Stufe 3 / kein Tag-1-Blocker; Contest nicht als Go-live-Pflicht; Priorisierung P4 „Stufe-3-Entscheidungen“ als Go-live-Abgrenzung | Statuskonsolidierung zum 15.11.2026 als Historie; Tag-1-Betriebsblocker (BL-P11-01 etc.) bleiben relevant, werden aber in diesen Phasenplan eingeordnet |
| [`fortschritt.md`](../fortschritt.md) / [`backlog-v1.md`](../backlog-v1.md) / [`test-und-abnahmekatalog.md`](../test-und-abnahmekatalog.md) | Formulierungen „Social/Events = Stufe 3“, „kein Tag-1“ als aktuelle Go-live-Regel | Historische Statuszeilen; künftige Arbeit folgt diesem Masterplan |
| EPIC-03-F Vertrag/`includes_crm=false` | **Nicht** stillschweigend geändert | CRM bleibt **außerhalb** Slice 6b; eigener Transfermechanismus (PO-01), Phase 1 |
| Audit „fehlende Basic Auth“ | Kein Go-live-Blocker mehr (PO-03) | App-Login- und Sicherheitsprüfungen gemäß PO-03 |

---

## 5. Phasen, Abhängigkeiten und Acceptance-Gates

### Übersicht

| Phase | Name | Status | Abhängigkeit |
|---|---|---|---|
| **0** | Master-Audit und PO-Entscheidungen | `ACCEPTED` | — |
| **1** | Transfermechanismus vervollständigen | `IN_PROGRESS` | Phase 0 |
| **2** | Fachliche Vollständigkeit lokal | `OPEN` | Phase 0; parallel vorbereitend zu 1, Abschluss vor Phase 4/5 |
| **3** | Stammdaten vervollständigen | `OPEN` | fachliche Entscheidungen aus Phase 2 wo nötig |
| **4** | Vollständige Staging-Synchronisation | `BLOCKED` | Phase 1 (+ CRM-Mechanismus), Phase 3 Datenreife |
| **5** | Technische und fachliche Staging-Abnahme | `OPEN` | Phase 4 |
| **6** | Mitarbeiter-UAT vorbereiten | `OPEN` | Phase 5; PO-02 Bereinigungs-GO; PO-04 Preise |
| **7** | Mitarbeiter-UAT und Fehlerbehebung | `OPEN` | Phase 6 |
| **8** | Produktionsvorbereitung | `OPEN` | Phase 7 ACCEPTED |
| **9** | Explizit freigegebener Go-live | `OPEN` | Phase 8 + explizites PO-GO |

### Phase 0 – Master-Audit und PO-Entscheidungen

**Status:** `ACCEPTED` (26.11.2026)

Acceptance-Gate:

- [x] PO-01 … PO-07 verbindlich dokumentiert
- [x] Zentraler Masterplan im Repository
- [x] Widersprechende frühere Abgrenzungen nachvollziehbar abgelöst

### Phase 1 – Transfermechanismus vervollständigen

**Status:** `IN_PROGRESS`

| Arbeitspaket | Status | Hinweise / Belege |
|---|---|---|
| EPIC-03-F Slice 6b – Master Data Transfer 2.0.0 Apply | `VERIFIED` (Code gemergt); Hardening im Slice-7-PR | PR [#279](https://github.com/MORE-m/dispo/pull/279) **MERGED**; Merge-SHA `171aad700ee2cd0a43e8e434a042fa16fcb28d09`; Feature-/Post-Merge-CI grün. Hardening (Rollback-Test, Backup-Proof v2) im Branch `feat/epic-03-f-slice7-price-status-apply` – Merge-Status separat. **Kein** Staging-Apply. CRM nicht in Scope. |
| EPIC-03-F Slice 7 – Preisstatus Apply | `IMPLEMENTED` → CI → Ziel `VERIFIED` | Feature-Branch `feat/epic-03-f-slice7-price-status-apply`; Verarbeitungsvertrag 2.1.0; S7-PO-01…05; CLI `--dry-run-price-status` / `--apply-price-status`. **Kein** operativer Status-Apply. |
| CRM-Transfermechanismus (PO-01) | `OPEN` / `READINESS` | eigenständig; vor ungeprüftem Staging-CRM-Apply |

Acceptance-Gate Phase 1:

- [x] Slice 6b technisch implementiert und CI-verifiziert (`VERIFIED`; `ACCEPTED` erst nach PO)
- [x] Slice 6b gemergt (PR #279 → `171aad700ee2cd0a43e8e434a042fa16fcb28d09`)
- [ ] Slice 7 CI-verifiziert und gemergt (`VERIFIED`; operativ weiter STOP)
- [ ] CRM-Transfervertrag/-mechanismus readynessseitig geklärt und freigegeben (ohne ungeprüfte Integration in 6b)
- [x] Kein operativer Staging-Apply ohne gesondertes PO-GO (weiterhin STOP)

### Phase 2 – Fachliche Vollständigkeit lokal

**Status:** `OPEN`

| Arbeitspaket | Status |
|---|---|
| Contest Readiness + Lücken | `READINESS` |
| Social Media Readiness | `READINESS` |
| Events Readiness | `READINESS` |
| Barter Readiness | `READINESS` |
| Vorgesehene Berechnungsmethoden vollständig | `OPEN` |
| Buchbarkeitsmatrix Inventar × Werbemittel × Methode (PO-06) | `OPEN` |
| Weitere Audit-Blocker fachlich | `OPEN` |

Acceptance-Gate Phase 2:

- [ ] Je Bereich (Contest/Social/Events/Barter) Readiness mit offenen PO-Fragen vorgelegt
- [ ] Keine eigenmächtige Berechnungslogik ohne PO-Entscheidung
- [ ] Buchbarkeitsmatrix erstellt und gegen vorgesehenen Scope geprüft

### Phase 3 – Stammdaten vervollständigen

**Status:** `OPEN`

| Arbeitspaket | Status |
|---|---|
| Werbemittel / Medien / Inventare | `OPEN` |
| Preislisten (Werbung) | `OPEN` |
| Produktionspreise 2027 (PO-04) – Fehlermeldeliste | `OPEN` |
| CRM-Qualität vor Transfer (PO-01) | `READINESS` |
| Weitere Referenz-/Konfigurationsdaten | `OPEN` |

Acceptance-Gate Phase 3:

- [ ] Fehlende Produktions- und Werbepreise gelistet; keine Null-/Schätzbefüllung
- [ ] Für UAT vorgesehene Preise vollständig gepflegt (Gate vor Phase 6/7)
- [ ] CRM-Datenqualität geprüft (ohne ungeprüften Transfer)

### Phase 4 – Vollständige Staging-Synchronisation

**Status:** `BLOCKED` (wartet auf Phase-1-Mechanismen + Datenreife)

Acceptance-Gate:

- [ ] Geprüfter Stammdatentransfer (Fachkatalog 2.0.0) mit Backup/Dry-Run/Report
- [ ] CRM-Transfer nach eigenem freigegebenem Verfahren
- [ ] Bewegungsdaten nicht übernommen (PO-02)
- [ ] Staging-Schutzziele (Admin, bestehende Pins/Testvorgänge während Abnahme) eingehalten

### Phase 5 – Technische und fachliche Staging-Abnahme

**Status:** `OPEN`

Acceptance-Gate:

- [ ] Technische Checks (HTTPS, `APP_DEBUG`, Session, AuthZ, Indexierungsschutz, Mail-Kontrolle) laut PO-03
- [ ] Fachliche Abnahme der Go-live-Scope-Bereiche auf Staging
- [ ] Bekannte Blocker dokumentiert oder behoben

### Phase 6 – Mitarbeiter-UAT vorbereiten

**Status:** `OPEN`

Acceptance-Gate:

- [ ] Kontrollierte Testdatenbereinigung mit Backup/Dry-Run/PO-GO (PO-02)
- [ ] Benutzerkonten und Rollen
- [ ] Mail-Sicherheit
- [ ] UAT-Testkatalog
- [ ] Feedback-/Fehlerprozess
- [ ] Produktionspreise 2027 vollständig (PO-04)

### Phase 7 – Mitarbeiter-UAT und Fehlerbehebung

**Status:** `OPEN`

Acceptance-Gate:

- [ ] UAT durchgeführt und dokumentiert
- [ ] Kritische Befunde behoben oder bewusst akzeptiert
- [ ] Keine Übernahme der UAT-Bewegungsdaten nach Produktion

### Phase 8 – Produktionsvorbereitung

**Status:** `OPEN`

Acceptance-Gate:

- [ ] Produktivumgebung vorbereitet (Code, Stammdaten, Benutzer, Mail, Monitoring)
- [ ] Go-live-Checkliste vollständig
- [ ] Rollback-/Backup-Plan bestätigt

### Phase 9 – Explizit freigegebener Go-live

**Status:** `OPEN`

Acceptance-Gate:

- [ ] Explizites PO-GO
- [ ] Produktionsschaltung durchgeführt und verifiziert
- [ ] Staging bleibt als Testumgebung erhalten

---

## 6. Priorisierte offene Aufgaben (aktuell)

| Prio | Aufgabe | Phase | Status |
|---:|---|---|---|
| 1 | EPIC-03-F Slice 7 + Slice-6b-Hardening – CI/Review/Merge (kein Auto-Merge; kein operativer Apply) | 1 | `IMPLEMENTED` |
| 2 | CRM-Transfer Readiness + Mechanismus (PO-01) | 1 | `OPEN` |
| 3 | Buchbarkeitsmatrix (PO-06) | 2 | `OPEN` |
| 4 | Contest / Social / Events / Barter Readiness-Pakete (PO-05) | 2 | `READINESS` |
| 5 | Fehlende Preise 2027 listen und pflegen (PO-04) | 3 | `OPEN` |
| 6 | Staging-Sicherheitscheckliste ohne Basic Auth (PO-03) | 5 | `OPEN` |
| 7 | UAT-Bereinigungsverfahren spezifizieren (PO-02) | 6 | `OPEN` |

---

## 7. Risiken und Go-live-Blocker (aktueller Stand)

| ID | Risiko / Blocker | Schwere | Status | Bemerkung |
|---|---|---|---|---|
| GLB-01 | Transfer Apply 2.0.0 + Status-Phase: Code/Hardening/`Slice 7` im Feature-PR; fachlich/operativ nicht `ACCEPTED`; Inhalts- und Status-Apply offen | hoch | teilweise entschärft | PR #279 MERGED; Slice-7-PR Draft; operativer Apply = eigenes GO |
| GLB-02 | CRM-Transfer fehlt (eigenständig) | hoch | offen | PO-01; nicht in 6b |
| GLB-03 | Social / Events / Barter fachlich unvollständig | hoch | offen | PO-05; Readiness zuerst |
| GLB-04 | Contest Go-live-Vollständigkeit ungeprüft | mittel–hoch | offen | Code vorhanden; Readiness/Abnahme offen |
| GLB-05 | Produktionspreise 2027 lückenhaft | hoch | offen | PO-04; UAT-Gate |
| GLB-06 | Buchbarkeitsmatrix fehlt | hoch | offen | PO-06 |
| GLB-07 | Staging-Sicherheitslage jenseits Basic Auth | mittel | offen | PO-03 Prüfungen |
| GLB-08 | UAT-Bereinigung ohne automatische Löschung | mittel | offen | PO-02 Verfahren + PO-GO |
| GLB-09 | Betriebs-/Deploy-Readiness (BL-P11-01 / ADR-003) | hoch | offen | Phase 8/9 |
| — | „Fehlende Basic Auth“ | — | **kein Blocker mehr** | PO-03 |

---

## 8. Offene PO-Entscheidungen (Sammelstelle)

Noch **nicht** durch PO-MASTER-AUDIT abschließend entschieden bzw. nachzureichen:

1. Fachliche Berechnungslogik / Preisregeln für Social Media, Events und Barter (soweit fehlend) – gesammelt vorlegen, nicht spekulieren.
2. Ob/wie `PO-STD-V2` und `PO-OA-BUDGET-V2` für den erweiterten Go-live-Scope angepasst werden.
3. Konkreter CRM-Transfervertrag (Schema-Erweiterung vs. separates Paket) nach Readiness.
4. Zeitpunkt und Verfahren der Staging-Testdatenbereinigung vor UAT (nur mit PO-GO).
5. Explizite Go-live-Freigabe (Phase 9).

---

## 9. Relevante PRs und Commit-SHAs

| Bezug | PR / SHA | Anmerkung |
|---|---|---|
| Dokumentationsbasis Masterplan | `2283d5fc0c836998e2c59253d24a821373762f79` | `main` nach PR #278 |
| EPIC-03-F Vertrag 2.0.0 | Docs auf `main` (u. a. PR #276 Operational Readiness) | technisch abgenommen |
| Slice 6a Dry-Run CLI | PR #277 / Merge in Basislinie | Voraussetzung für operativen Dry-Run |
| Slice 6b Apply | PR [#279](https://github.com/MORE-m/dispo/pull/279) MERGED / Merge-SHA `171aad700ee2cd0a43e8e434a042fa16fcb28d09` | Feature-/Post-Merge-CI pass; Staging-Apply weiter STOP |
| Slice 6b Hardening + Slice 7 | Branch `feat/epic-03-f-slice7-price-status-apply` / Verarbeitungsvertrag 2.1.0 | Draft-PR; CI ausstehend/zu prüfen; kein Auto-Merge; kein operativer Apply |
| Tabellenklassifikation | `docs/readiness/EPIC-03-F-table-classification-2-0-0.md` | alle Tabellen; CRM = PO-01 separat |
| Historische CUT/Closure | siehe Abschnitt 4 | abgelöst bzgl. Scope-Abgrenzung |

*(Tabelle bei jedem abgeschlossenen Arbeitspaket aktualisieren.)*

---

## 10. Aktuelle Phase und nächster freigegebener Arbeitsschritt

- **Aktuelle Phase:** **Phase 1 – Transfermechanismus vervollständigen** (`IN_PROGRESS`)
- **Slice 6b:** Code gemergt und `VERIFIED` (PR #279); Hardening im Slice-7-Feature-PR; **nicht** `ACCEPTED`
- **Slice 7:** `IMPLEMENTED` auf Feature-Branch (Verarbeitungsvertrag 2.1.0, S7-PO-01…05); Ziel `VERIFIED` nach CI; **kein** operativer Status-Apply
- **Operativer Inhalts-Apply / Preisstatus-Apply:** weiterhin `OPEN` / STOP ohne gesondertes PO-GO
- **Empfohlener nächster PO-Schritt nach Merge/CI von Slice 7:** CRM-Transfer-Readiness (PO-01) – explizites PO-GO; noch nicht starten
- CRM bleibt `OPEN`

---

## 11. Änderungsprotokoll

| Version | Datum | Änderung |
|---|---|---|
| `1.0.0` | 2026-11-26 | Erste verbindliche Fassung nach PO-MASTER-AUDIT; Ablösung widersprechender Go-live-Abgrenzungen dokumentiert |
| `1.1.0` | 2026-11-26 | Slice 6b auf `IMPLEMENTED` gesetzt (Draft-PR #279); Tabellenklassifikation verlinkt; Staging-Apply weiter STOP; Phase 1 weiter `IN_PROGRESS` |
| `1.2.0` | 2026-11-26 | Slice 6b auf `VERIFIED` nach CI `ci`/`mysql`/`e2e-spt008` grün (Run `45795039370`); Staging-Apply/Slice 7/CRM weiter OPEN; kein ACCEPTED |
| `1.2.1` | 2026-11-26 | Slice 6b Merge-Status nachgezogen: PR #279 MERGED (`171aad7…`); Post-Merge-CI `45806036882` grün; Staging-Apply/Slice 7/CRM weiter OPEN; Hardening-Nachweise ausstehend; kein ACCEPTED |
| `1.3.0` | 2026-11-26 | Slice 7 + Slice-6b-Hardening `IMPLEMENTED` (Feature-Branch; Verarbeitungsvertrag 2.1.0; S7-PO-01…05); operativer Inhalts-/Status-Apply und CRM weiter OPEN; kein ACCEPTED |
