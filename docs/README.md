# Dokumentationsindex

Dieses Verzeichnis ist die fachliche und technische Wissensbasis des Projekts.
Die Dokumente werden gemeinsam mit dem Code versioniert.

## Quellenhierarchie

| Rang | Quelle | Zweck |
|---:|---|---|
| 1 | `anforderungskatalog.md` | Verbindlicher Umfang und fachliche Anforderungen von V1 |
| 2 | Fachmodelle in diesem Verzeichnis | Präzisierung, Formeln, Zustände und Datenbeziehungen |
| 3 | Akzeptierte ADRs | Verbindliche technische Entscheidungen |
| 4 | Automatisierte Tests | Ausführbares Verhalten der aktuellen Implementierung |

Ein untergeordnetes Dokument darf einer höherrangigen Quelle nicht widersprechen.

## Dokumente

| Datei | Inhalt |
|---|---|
| [`anforderungskatalog.md`](anforderungskatalog.md) | Konsolidiertes Lastenheft mit stabilen Anforderungs-IDs (inkl. `BUD-*`, `STD-*`, Rolle Produktmanagement) |
| [`fachmodell.md`](fachmodell.md) | Begriffe, Aggregate und fachliche Invarianten |
| [`berechnungslogik.md`](berechnungslogik.md) | Formeln, Reihenfolgen und Rundung |
| [`workflows-und-berechtigungen.md`](workflows-und-berechtigungen.md) | Rollen, Freigaben, Status und Sperren |
| [`dynamisches-feldsystem.md`](dynamisches-feldsystem.md) | Felddefinitionen, Regeln, Versionen und Snapshots |
| [`datenmodell.md`](datenmodell.md) | Konzeptuelles Datenmodell und technische Leitplanken |
| [`ui-ux-konzept.md`](ui-ux-konzept.md) | Navigation, zentrale Ansichten und Interaktionsprinzipien |
| [`ux-ui-gate.md`](ux-ui-gate.md) | Gate-/PO-Freigaben (A/B freigegeben; C blockiert; D teilweise freigegeben) |
| [`test-und-abnahmekatalog.md`](test-und-abnahmekatalog.md) | Fachliche Mindestabnahme und Teststrategie |
| [`initialdaten.md`](initialdaten.md) | Startkataloge und noch bereitzustellende Daten |
| [`umsetzungsplan.md`](umsetzungsplan.md) | Phasen-/Reihenfolgeübersicht (kein konkurrierender Paketstatus) |
| [`backlog-v1.md`](backlog-v1.md) | Paket-/Slice-Status (`offen` / `erledigt` / `teilweise` / `blockiert`) |
| [`fortschritt.md`](fortschritt.md) | Aktueller Arbeits-/Umsetzungsstand |
| [`entwicklung-lokal.md`](entwicklung-lokal.md) | Lokales Setup, Prüfungen, Produktionshinweise |
| [`blocker-und-entscheidungslog.md`](blocker-und-entscheidungslog.md) | Blocker und technische Detailentscheidungen |
| [`entscheidungen/`](entscheidungen/) | Architecture Decision Records (ADR) |

## Pflegeprozess

1. Fachliche Änderung mit betroffenen Anforderungs-IDs beschreiben.
2. `anforderungskatalog.md` oder das präzisierende Fachdokument aktualisieren.
3. Bei Architekturfolgen ein ADR anlegen oder ergänzen.
4. Tests aktualisieren.
5. Erst danach die Implementierung als abgeschlossen markieren.

## Statusangaben

- **Verbindlich:** für V1 beschlossen.
- **Vorgeschlagen:** noch zu bestätigende technische Entscheidung.
- **Offen:** konkrete Initialdaten oder Detailausprägung fehlen; keine offene Grundsatzentscheidung.
- **V2:** ausdrücklich nicht Teil des V1-Umfangs.

