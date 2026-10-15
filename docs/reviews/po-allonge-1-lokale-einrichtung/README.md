# Review: lokale Einrichtung PO-ALLONGE-1

Stand: 15. Oktober 2026
Art: lokale Datenpflege und Browser-Abnahme auf `dispo_mat_core`. Kein Deploy.
Code: `main` @ `092ac36a3a6e1f6f10c20b866cbcea90a3bcc647` (Merge PR #142)
Worktree: `docs/po-allonge-1-local-setup` / `dispo-wt-docs-po-allonge-1-local`
Readiness: [`PO-ALLONGE-1-lokale-einrichtung-2026-10-15.md`](../../readiness/PO-ALLONGE-1-lokale-einrichtung-2026-10-15.md)

Der Review vom 14. Oktober unter `docs/reviews/po-allonge-1/` bleibt der damalige Slice-Stand vor Merge und vor den Startwerten.

## Abgrenzung

| Stand | Bedeutung |
|---|---|
| Feature-Review 14. Oktober | Code und isolierte Abnahme; Startwerte damals offen; Text „nicht gemergt“ ist dieser damalige Stand |
| Merge PR #142 | Code auf `main`; ersetzt die lokale Einrichtung nicht |
| Dieser Bericht | Migration, Admin-Bestätigung 10 s / 30 % und Calc→Dispo auf der lokalen MariaDB |

## Urteil

Die vier Allonge-Kombinationen sind nur über die Detailseite bestätigt: jeweils 10 Sekunden Default und 30 % Aufschlag. Die Positionslänge darf der Nutzer ändern. Der Aufschlag bleibt serverseitig. Die Migration hat die bestehenden Zahlen nicht überschrieben. Trailer bleibt 20 s / 30 %.

Die Browser-Abnahme auf Port 8090 trifft die aus den aktiven Listen 2026, Stunde 08:00–08:59, berechneten Beträge: 7.280 + 2.210 + 1.040 + 130 = 10.660,00 €. Save ohne Neuladen, Inventarwechsel auf den Ziel-Default und die danach wieder editierbare Länge sind bestanden. Der Dispo `DA-2026-00007-01` behält 10.660,00 €, nachdem die Kalkulation danach auf 17.485,00 € geändert wurde. Die Abnahmedaten bleiben liegen. Der Separatserver ist beendet; MariaDB und Port 8000 laufen weiter.

Das ist eine lokale technische Abnahme, kein Deploy und keine darüber hinausgehende Fachabnahme. `BL-P5-01` bleibt teilweise.
