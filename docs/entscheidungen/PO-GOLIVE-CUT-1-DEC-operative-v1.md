# PO-GOLIVE-CUT-1-DEC – Operative V1 (Projektpriorisierung)

Status: **Akzeptiert für die hier festgelegte Projektpriorisierung; einzelne Fachparameter bleiben explizite Umsetzungsvoraussetzungen.**  
Stand: 18. Oktober 2026  
Basis: `origin/main` @ `01c5e71201f283eaf5a4369e7679ae84b48e49a3` (nach PR #158; #158 ändert keine Fachlogik)  
IDs: `PO-GOLIVE-CUT-1`, `PO-GOLIVE-CUT-1-DEC`, `SPT-011`, `MAT-003`, `SWF-*`, `REP-007`, `AUTH-*`, Kapitel 27  
Vorgänger-Readiness: Status-quo-Audit nach PR #158 (nicht als eigene Datei in `docs/`) und Auswertung PO-GOLIVE-CUT-1

## Entscheidung

Die operative V1 wird in **vier Stufen** priorisiert. Bereits umgesetzte Träger bleiben Grundlage. Neue Medien und Methoden werden nur freigeschaltet, wenn die genannten Fachparameter vorliegen. Offene Parameter werden **nicht** durch Implementierungsannahmen ersetzt.

### Beschlossener Medienumfang (operative V1)

| Medium (Code) | Bezeichnung | Einordnung |
|---|---|---|
| `spot_classic` | Werbespot | bereits buchbar |
| `spot_tandem` | Tandem / Reminder | bereits buchbar |
| `spot_tridem` | Tridem | bereits buchbar |
| `trailer_station_voice` | Trailer/Station Voice | bereits buchbar (Average) |
| `allonge` | Allonge (SWF-Medium) | bereits buchbar (Average); nicht die Spot-Allonge-Komponente |
| `spot_first` | Werbespot erstplatziert | Stufe 1; nach Werte-Readiness |
| `spot_last` | Werbespot letztplatziert | Stufe 1; nach Werte-Readiness |
| `spot_single` | Single-Spot | Stufe 1; **vorbehaltlich MAT-003-Readiness** |
| `promo_moderation` | Promo/Moderation | Stufe 1; **vorbehaltlich Profil-Readiness** |

Berechnungsmethoden der operativen V1:

- `average`
- `calendar` (Spot-Pfad)
- Festpreis-Abschluss über `pricing_settlement_mode` auf Basis `average`|`calendar`

**Keine** neue Registry-Engine/`fixed_price`-Methode ohne gesonderten fachlichen Bedarf. Der Method-Key `fixed_price` bleibt `planned`.

### Stufen

**Stufe 1 – Operativer Spot-/SWF-Kern**

- Bestehenden Kern nutzen (Werbespot, Tandem, Tridem, Trailer, Allonge).
- Schrittweise ergänzen: Erst-/Letztplatzierung, danach Single-Spot (nach MAT-003), danach Promo/Moderation (nach Profil-Readiness).

**Stufe 2 – Operative Einsatzvoraussetzungen**

- Minimale Benutzerverwaltung als Admin-UI (Anlegen/Rolle/Rabattgrenze/Aktivstatus/Sonderflag; kein AUTH-005-Ausbau).
- Dispo-Nachweis Tag 1: **Dispo-Detailseite plus bestehende Spotverteilung-XLSX (SPT-008)** als vorläufiger Nachweis.
- REP-007 Dispo-PDF bleibt Phase 10 / Stufe 4; Layouts Kapitel 27 bleiben Liefergegenstand.
- Preis-/Katalogdaten validieren; Produktivbetrieb und fachliche End-to-End-Abnahme vorbereiten (BLK-001/002, ADR-003).

**Stufe 3 – Erweiterter operativer Katalog**

Bleibt ausdrücklich im **Projektumfang**, braucht aber **separate Readiness** vor Implementierung:

- weitere SWF und bislang ungeklärte Werbemittel
- Online Audio mit TKP
- Social
- Events
- weitere benötigte Berechnungsmethoden (`tkp`, `free_position`, …)

**Stufe 4 – Begleitende Funktionen**

- weitere Notifications, Outbox-Ausbau
- umfangreiche Auswertungen (REP-001–004)
- Dispo-PDF (REP-007) und weitere PDF/Excel/CSV
- weitere Komfort- und Administrationsfunktionen (u. a. MAT-004)

Betriebskritische Anforderungen (Sicherheit, Backup, SMTP, Domain, Rechtekern) sind **stufenunabhängig** rechtzeitig zu behandeln.

### Fachliche Voraussetzungen (nicht eigenmächtig gesetzt)

**Erst-/Letztplatzierung (`spot_first`, `spot_last`)**

- dokumentierter Soll-Aufschlag **+30 %** (`SPT-011`)
- verbindliche Werte und Inventarzuordnung vor Aktivierung prüfen
- kein stiller Rückgriff auf Import-Nullwerte (`0` ≠ „nicht konfiguriert“)
- nächster Auftrag: `PO-SPOT-SURCHARGE-1`

**Single-Spot (`spot_single`)**

- dokumentierter Soll-Aufschlag **+50 %** (`SPT-011`)
- `MAT-003` und inventarspezifische Zuordnung vor Implementierung klären
- eigener Folgeslice nach Erst/Letzt; nicht stillschweigend in `PO-SPOT-SURCHARGE-1` ziehen

**Promo/Moderation (`promo_moderation`)**

- eigenes SWF-Profil nach Allonge-Muster (nicht stilles Inherit auf `swf_trailer`)
- Länge, Aufschlag und Bestätigungspflicht vor Implementierung verbindlich klären
- eigener Auftrag nach Profil-Readiness (Arbeitstitel `PO-SWF-PROMO-1`)

## Verhältnis zu bestehenden Entscheidungen

| Bestehende Quelle | Verhältnis |
|---|---|
| `PO-MAT-CORE-CATALOG-1` | Historisch: `kind=spot_classic` nur Classic/Tandem/Tridem; übrige `kind=null`. **Nicht überschrieben.** Freischaltung von Erst/Letzt/Single/Promo erfolgt nur über spätere, dokumentierte Katalog-/Bootstrap-Slices unter den Voraussetzungen dieses DEC. |
| `PO-ALLONGE-1` / `PO-BLP501A-1` | Unverändert gültig; Allonge/Trailer bleiben Teil des Kerns. |
| `BL-P4-02d` | Festpreis-Abschluss bleibt Settlement; Registry-`fixed_price` planned. |
| UX-GATE-C | Trailer/Allonge-Teilfreigaben bleiben; weitere SWF (inkl. Promo) brauchen eigene Teilfreigabe nach Readiness. |
| Kapitel 26/27 | 42 Medien bleiben Katalogumfang; Tag-1-Buchbarkeit folgt dieser Priorisierung, nicht der vollen Liste. |

## Explizit nicht entschieden / nicht freigegeben

- operative Aufschlagwerte je Inventar für Erst/Letzt/Single (nur Soll +30 %/+50 % dokumentiert)
- MAT-003-Vollabnahme und Single-Implementierungsfreigabe
- Promo-Profilname, Startwerte, Confirm-Vertrag
- Cluster-E-Medien (u. a. Abbinder-Medium vs. Komponente, Opener-Quartett, Jobspot, Barter, Influencer-/Visual-Formate, Native-Ad)
- Tag-1-Pflicht für OA/Social/Events (Stufe 3, separate Readiness)
- Pflicht-Dispo-PDF vor Produktivsetzung (Interim XLSX+Detail ist beschlossen; PDF bleibt Liefer-/Stufenpunkt)

## Nächster Auftrag

**PO-SPOT-SURCHARGE-1 – Erst- und Letztplatzierung**  
Zuerst Werte-/Konfigurations-Readiness, danach kleiner Implementierungsslice.  
Auftragstext: [`docs/readiness/PO-SPOT-SURCHARGE-1-auftrag-2026-10-18.md`](../readiness/PO-SPOT-SURCHARGE-1-auftrag-2026-10-18.md)

## Nicht freigegeben durch dieses Dokument

- Produktcode, Migrationen, Seeds, Datenpflege, Deploy, Merge
- Freischaltung von `kind` ohne Werte-Readiness
- Single-Spot- oder Promo-Implementierung
