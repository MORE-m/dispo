# PO-ALLONGE-1 – Allonge × Durchschnitt

Status: **Akzeptiert und auf diesem Branch umgesetzt.**
Nicht gemergt. Kein Deploy. Keine operative Einrichtung.
Startwerte bleiben offen.
Stand: 14. Oktober 2026
Basis: `origin/main` @ `722bc5571a88cbeec64685a3ba9a87eb2327e5e4`
Readiness: [`docs/readiness/PO-ALLONGE-1-swf-allonge-2026-10-14.md`](../readiness/PO-ALLONGE-1-swf-allonge-2026-10-14.md)

Das SWF-Medium Allonge ist nicht die Spot-Allonge-Komponente.
`BL-P5-01` bleibt teilweise.

## Akzeptiert

UX-GATE-C gilt nur für Allonge × Durchschnitt auf Radio Hamburg, ROCK ANTENNE
Hamburg, 80er 90er OLDIE ANTENNE Hamburg und CARAVAN.fm.

Weitere SWF, Calendar, Festpreis, Produktion, Standardangebote, Budget und
Deploy bleiben außerhalb.

Die Kennzeichnung ist `inventory_medium_rules.swf_allonge_confirmed_at`.
Leer heißt unbestätigt. Ein berechtigtes Speichern gültiger Default-Länge
(1–3600) und eines Aufschlags, einschließlich 0 %, auf der Kombinationsdetailseite
setzt den Zeitpunkt. Es gibt keine Checkbox. Der Zeitpunkt kommt nicht aus dem
Client. Neuanlage, Aktivieren, Bootstrap, Matrix-Import und `kind` bestätigen
nicht. Ein späterer Matrix-Import lässt Länge, Aufschlag und Zeitpunkt stehen.

Bestehende 30 s / 0 % bleiben stehen und sind unbestätigt, bis sie so gespeichert
werden.

## Pflegevertrag

- Admin-Länge ist der Default. Der Nutzer darf die Positionslänge ändern.
- Aufschlag nur serverseitig aus der Kombination.
- Neue Position: Default. Inventarwechsel: Ziel-Default, danach editierbar.
- Preisjahr-Wechsel: Positionslänge bleibt, Preis und Aufschlag neu.
- Unveränderter Bezug: gespeicherte Werte bleiben.
- Dispo übernimmt den gespeicherten Calc-Snapshot.
- Eine Nutzerlänge allein ersetzt keine bestätigte Admin-Konfiguration.

Formel:

```text
Anzahl × Ø-Sekunden-Grundpreis × gewählte Positionslänge × (1 + Admin-Aufschlag / 100)
```

Ohne Spotlängenindex. Preisquelle ist die Spot-Sekundenliste des Inventars
und Jahres.

## Grenzen

Aktivieren des Mediums macht ein Inventar noch nicht buchbar. Buchbar wird es
erst nach dem bestätigenden Detail-Speichern. Dieser Slice hinterlegt keine
Startwerte und führt keine operative Migration oder Datenpflege aus.
