# Daten-Readiness: PO-ALLONGE-1 – lokale Startwerte und Abnahme

Status: **LOKAL EINGERICHTET** (nur `dispo_mat_core`; **kein** Deploy)
Stand: 15. Oktober 2026
Code-Basis: `main` @ `092ac36a3a6e1f6f10c20b866cbcea90a3bcc647`
(Merge PR [#142](https://github.com/MORE-m/dispo/pull/142);
Post-Merge-CI [38621950951](https://github.com/MORE-m/dispo/actions/runs/38621950951) SUCCESS)
Ziel-DB: lokale XAMPP-MariaDB **`dispo_mat_core`** @ `127.0.0.1:3306`
PO: [`PO-ALLONGE-1`](../entscheidungen/PO-ALLONGE-1-kombinationspflege.md)
Feature-Readiness vom 14. Oktober bleibt der damalige Slice-Stand:
[`PO-ALLONGE-1-swf-allonge-2026-10-14.md`](PO-ALLONGE-1-swf-allonge-2026-10-14.md)

> Lokale Einrichtung ≠ Feature-Abnahme auf isolierter Datenbank ≠ Deployment.
> Andere Umgebungen unverändert. `.env` und Port 8000 unberührt.
> Keine Dumps und keine Zugangsdaten in diesem Dokument.

## 0. Urteil

| Ebene | Status |
|---|---|
| Code auf `main` | **ja** (`092ac36…`, Merge, kein Squash) |
| Feature-Abnahme PO-ALLONGE-1 | **früherer Stand** (isolierte Suite und CI), hier nicht wiederholt |
| Migration lokal | **einmal angewendet**, keine weitere ausstehende Migration |
| Vier Allonge-Kombinationen | **10 s / 30 %**, bestätigt über die Detailseite |
| Positionslänge / Aufschlag | Länge durch den Nutzer änderbar; Aufschlag bleibt der serverseitige Kombinationswert |
| Trailer der vier Sender | **unverändert** 20 s / 30 %, ohne Allonge-Zeitpunkt |
| Lokale Browser-Abnahme bis Dispo | **bestanden** (Port 8090) |
| `BL-P5-01` vollständig | **nein** |
| Deploy | **nein** |

## 1. Sicherung

| | |
|---|---|
| Pfad | `/Applications/XAMPP/xamppfiles/htdocs/dispo-tool/local-backups/po-allonge-1-20261015-181638/` |
| Dump | `dispo_mat_core-full.sql`, 7.568.334 Bytes |
| SHA-256 | `fd92b72950a1bbc823bf2b1cd6c181c5a477270789c19ceb89bb14dacd631616` |
| Abschluss | `Dump completed on 2026-10-15 18:16:39` |
| Restore | nur laut `RESTORE.md` im Sicherungsordner, hier nicht ausgeführt |

Bestand vor der Migration: 62 angewendete Migrationen, 14 Inventare, 42 Werbemittel, 201 Regeln, 8 Spotlisten, 8 Produktionspreislisten, 3456 CRM-Accounts, Kalkulationen `K-2026-00001`–`00006`, Dispoaufträge `DA-2026-00002-01`, `DA-2026-00002-02`, `DA-2026-00003-01`–`00006-01`.

`max_allowed_packet` der laufenden Instanz bleibt 16777216. Konfiguration, Datenverzeichnis und Socket wurden nicht geändert. MariaDB bleibt für den lokalen Betrieb gestartet.

## 2. Migration

`2026_10_14_140000_po_allonge_1_swf_allonge_confirmation`, einmal, danach keine ausstehende Migration.

Ergebnis: Medium `allonge` hat Kind `swf_allonge`, Override und Average-Profil `swf_allonge`. Kategorie und Trailer bleiben `swf_trailer`. Die Migration hat keine Regellänge, keinen Aufschlag und keinen Bestätigungszeitpunkt gesetzt.

## 3. Admin-Bestätigung

Nur die Detailseite, Writer `InventoryMediumRuleAdminWriter`. Buchungskennzeichen, Planung, Aktiv, Rabatt und AE blieben stehen.

| Inventar | Regel | Länge | Aufschlag | Bestätigt (DB, lokal) | Buchung |
|---|---|---|---|---|---|
| Radio Hamburg | 156 | 10 | 30 % | 2026-10-15 18:19:36 | SWF (K) |
| ROCK ANTENNE Hamburg | 120 | 10 | 30 % | 2026-10-15 18:20:04 | SWF (K) |
| 80er 90er OLDIE ANTENNE Hamburg | 2 | 10 | 30 % | 2026-10-15 18:20:27 | SWF (K) |
| CARAVAN.fm | 39 | 10 | 30 % | 2026-10-15 18:20:43 | UC |

Radio Hamburg und CARAVAN.fm wurden nach dem Speichern neu geladen. ROCK und OLDIE zeigten die Bestätigung in der Antwort des Speicherns; die Datenbankzeilen sind für alle vier gelesen. Andere Allonge-Regeln und die Trailer-Regeln 184, 147, 30 und 65 sind unverändert.

## 4. Preisbasis

Aktive Spotlisten 2026, Stunde 08:00–08:59, Tagesgruppe Mo–Fr, 10 Spots. Der Aufschlag 30 % kommt aus der bestätigten Kombination. Preislisten wurden nicht geändert.

| Inventar | Liste | Sekundenpreis | 10 s | 15 s |
|---|---|---|---|---|
| Radio Hamburg | 1 | 56,0000 | 7.280,00 | 10.920,00 |
| ROCK ANTENNE Hamburg | 5 | 17,0000 | 2.210,00 | 3.315,00 |
| OLDIE | 2 | 8,0000 | 1.040,00 | |
| CARAVAN.fm | 6 | 1,0000 | 130,00 | |

Formel: Anzahl × Sekundenpreis der einen Stunde × Länge × 1,30. Summe der vier Positionen bei 10 s: **10.660,00**.

## 5. Browser-Abnahme

Server `127.0.0.1:8090`, Prozessumgebung `MAIL_MAILER=log`. Port 8000 blieb der bestehende Prozess. Keine Freigabe, kein Versand.

Marker `PO-ALLONGE-1 lokale Abnahme`. Neuer vorläufiger Account, keine bestehende Kundenzeile verändert.

| Fall | Ist |
|---|---|
| a) Vier Positionen, Default 10 s, Vorschau = Speichern = Reload = Dispo | 7.280 + 2.210 + 1.040 + 130 = **10.660,00**. Brutto = N/N |
| b) Radio Hamburg auf 15 s | **10.920,00**. Die anderen drei blieben 2.210 / 1.040 / 130 |
| c) CARAVAN.fm nach gesetzten 20 s (Vorschau 260,00) auf ROCK ANTENNE | Länge zurück auf **10**, Betrag **2.210,00**, Medium weiter Allonge. Danach Länge 15 s: **3.315,00** |
| d) Speichern ohne Neuladen | weiter 4 Positionen, ein vorläufiger Account, keine zweite Kalkulation |
| e) Nach dem Dispo Länge und Inventar geändert | Dispo bleibt der Stand aus a) |
| f) Allonge-Position | kein Aufschlagsfeld, kein Kalender, kein Festpreis, keine Spot-Komponenten, keine Produktion |

Gespeichert nach b) und c): `K-2026-00007` (id 7), N/N **17.485,00**.

| Position | Inventar | Länge | Betrag |
|---|---|---|---|
| 9 | Radio Hamburg | 15 | 10.920,00 |
| 10 | ROCK ANTENNE Hamburg | 10 | 2.210,00 |
| 11 | OLDIE | 10 | 1.040,00 |
| 12 | ROCK ANTENNE Hamburg | 15 | 3.315,00 |

Dispo `DA-2026-00007-01` (id 7), Entwurf, vier Positionen weiter 10 s und **10.660,00**, CARAVAN.fm mit Buchung `UC`.

Bestand danach: 7 Kalkulationen, 7 Dispoaufträge, 3457 CRM-Accounts (genau ein neuer vorläufiger Account). Katalog 14 / 42 / 201 und 8 + 8 Preislisten unverändert. Die früheren Nummern `K-2026-00001`–`00006` und `DA-2026-00002-01` bis `DA-2026-00006-01` sind unverändert vorhanden.

Die Abnahmedaten bleiben liegen. Die früheren Aufträge sind unverändert. Kein Produktfehler, deshalb kein Code-Fix. Der Separatserver auf Port 8090 ist danach beendet. MariaDB und Port 8000 laufen weiter.

## 6. Grenzen

Die Einrichtung hat keinen Produktcode geändert. Kein Merge, kein Deploy, kein Seed, kein Restore. Die lokale technische Abnahme ersetzt keine darüber hinausgehende Fachabnahme. Weitere SWF, Calendar, Festpreis, Produktion, Standardangebote, Budget und `CRM-004` sind nicht Teil dieser Einrichtung. `BL-P5-01` bleibt teilweise.
