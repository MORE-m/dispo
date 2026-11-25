# EPIC-03-F – Risiken und Recovery Schema 2.0.0

**Status:** Vorbereitung. Keine automatische Reparatur oder Datenkorrektur.
**Datum:** 2026-11-25
**Runbook:** [`EPIC-03-F-operational-runbook-2-0-0.md`](EPIC-03-F-operational-runbook-2-0-0.md)

## 1. Voraussetzungen für eine verifizierte Staging-Sicherung

Mindestens erfüllt:

| Kriterium | Anforderung |
|---|---|
| Zeitpunkt | Frisch vor Apply; empfohlen auch vor erstem Dry-Run-Fenster dokumentieren |
| Ziel | Staging-DB `spn26jz7_dispo_staging` vollständig |
| Ablage | außerhalb Webroot; restriktive Rechte (Pilot-Referenz: `/files/dispo-staging/backups/`, mode 600) |
| Integrität | Dateigröße, SHA256 des Dumps notiert |
| Gate | `BACKUP_GATE` o. ä. = **PASS** (Pilot-Referenz: `BACKUP_GATE.txt`) |
| Restore-Pfad | schriftlich bestätigt und getestet (Pilot: same-DB-Import; Temp-DB oft ohne CREATE-Privileg) |
| Scope | DB-Inhalt; Code-Rollback ist getrennt zu betrachten |

Fehlt ein Kriterium → **kein Apply-GO**.

## 2. Wiederherstellungsablauf im Fehlerfall

1. Sofort **STOP** – keine weiteren Import-/Status-Befehle.
2. Fehler, Report-Pfad, Paket-SHA256, Deploy-SHA, Backup-Pfad dokumentieren.
3. Staging-App in sicheren Zustand bringen (keine parallelen Schreibjobs am Katalog, soweit steuerbar).
4. Restore ausschließlich aus dem **vor dem Apply** erstellten Dump über den freigegebenen Restore-Pfad.
5. Nach Restore: Schutzmatrix C und Fachanker stichprobenartig gegen Pre-Apply-Baseline prüfen.
6. Kein „Reparatur-Import“, kein Löschen der Testvorgänge als Rollback, keine manuelle Datenflickerei als Ersatz für Restore.
7. Neues PO-GO erforderlich, bevor erneut Dry-Run/Apply gestartet wird.

## 3. Abbruch bei Konflikten

| Situation | Reaktion |
|---|---|
| Dry-Run `conflicts > 0` | STOP; kein Apply |
| Security `result = STOP` | STOP; kein Apply |
| Apply-Fehler / Transaktionsabbruch | STOP; bei Bedarf Restore aus Pre-Apply-Backup |
| Konflikt nach Teil-Schreiben | darf nicht vorkommen (eine Transaktion); sonst Restore |

Konflikte werden ausgewertet und an PO eskaliert. **Keine** automatische Korrektur, kein Überschreiben, keine stille neue Preisrevision.

## 4. Umgang mit inkonsistenten Fachschlüsseln

Beispiele: gleicher Schlüssel anderer Inhalt; mehrdeutige Referenzauflösung; fehlende Pflicht-FK-Ziele; Regelmenge abweichend.

Vorgehen:

1. Lauf bricht fail-closed ab.
2. Konfliktzeilen aus dem Report isolieren (Entity + Key + Detail).
3. Quelle und Staging **lesend** vergleichen.
4. Fachliche Entscheidung durch PO (Daten in Quelle korrigieren, Staging belassen, Vertrag anpassen – **nicht** im Importpfad „heilen“).
5. Erst nach Korrektur außerhalb des Imports: neuer Export, neues SHA256, neues GO.

## 5. SHA256- / Paketabweichungen

| Abweichung | Reaktion |
|---|---|
| `checksums.sha256` stimmt nicht | Paket verwerfen; neu exportieren |
| Manifest-Counts ≠ Dateien | STOP |
| Schema ≠ `2.0.0` | STOP |
| Paket nach Dry-Run verändert | Apply verboten; neuer Dry-Run mit neuem GO |
| Status-Phase gegen anderes Paket | STOP |

Kein Weiterlaufen mit „fast gleichem“ Paket.

## 6. Unerwartete Änderungen am Staging-Bestand

Wenn zwischen Baseline, Dry-Run und Apply Schutz-Counts/Digests oder Fachanker abweichen:

1. STOP
2. Ursache klären (manuelle Admin-Änderung, anderer Job, fehlgeschlagenes Restore)
3. Neue Baseline + ggf. neues Backup
4. Neues PO-GO

Der Import darf parallel laufende Staging-Änderungen nicht „überstimmen“.

## 7. Preislisten-Pin-Konflikte

| Fall | Reaktion |
|---|---|
| Paket würde gepinnte Liste inhaltlich ändern | conflict → STOP |
| Pin-IDs/Versionen/Status/Fingerabdruck drift nach Plan/Apply | STOP; Restore wenn geschrieben |
| Status-Phase trifft gepinnte Liste | verboten → STOP |

Pins der Staging-Testvorgänge (hist. RH-Calcs/Dispo) haben Vorrang vor Katalogangleichung.

## 8. Fehlende Inventar-Logodateien

- Paket überträgt `logo_path` als DB-Feldinhalt, nicht zwingend die Binärdatei.
- Fehlt die Datei im Staging-Storage: **melden**, nicht automatisch aus der Quelle kopieren (kein ungeprüfter Dateitransfer in diesem EPIC-Schritt).
- Inhaltlich abweichender `logo_path` bei gleichem Inventar-Code → fachlicher Konflikt laut Vertrag.
- Operative Entscheidung über Dateinachzug ist separates PO-Thema, nicht Teil des Inhalts-Apply.

## 9. Offene Entscheidungen für die spätere Preisstatus-Phase

Aus Vertrag Abschnitt 15 / Readiness, weiterhin offen:

| Thema | Hinweis |
|---|---|
| Timing | Status-Phase im selben operativen Fenster wie Apply oder getrennt? |
| Umfang | Alle neuen Drafts mit `source_status=active/archived` oder Teilmenge? |
| Kommunikation | Wer prüft Fingerabdrücke unmittelbar vor Status-GO? |
| Fehlende Produktionspreise 2027 | bleiben Lücke; Status-Phase erfindet sie nicht |
| Social/Events/Barter | Katalog kann da sein, bepreiste Kalkulation ggf. nicht |

Status-Phase nur gegen dasselbe SHA256-Paket; nur `draft→active/archived` gemäß `source_status`; nie Pins; nie bereits active/archived anfassen.

## 10. Weitere Risiken (kurz)

| Risiko | Mitigation |
|---|---|
| Apply gegen Quelle/Produktion | Allowlist + `--confirm-database`; ohne Slice 6 nicht freigeben |
| CLI noch gesperrt für 2.0.0 | Slice 6 abschließen; bis dahin kein operativer Dry-Run |
| Verwechslung Schema 1.0.0 / 2.0.0 | Export immer `--schema=2.0.0`; Import Schema-Bindung |
| Historische Counts als Ist verwendet | Neuablesung vor Lauf verpflichtend |
| Parallelarbeit an Slice-5-Nachbesserungen | Dieser Docs-Branch ändert keinen Importcode; nach Code-Merges Doku re-checken |

## 11. Was bewusst nicht vorgesehen ist

- Automatische Konflikt-Reparatur
- Constraint-Deaktivierung
- Löschen fehlender Zielzeilen
- Erfinden fehlender Preise
- Rollback durch Löschen von Testvorgängen
- Ungeprüfte DB-Kopie Quelle → Staging
