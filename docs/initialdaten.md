# Initialdaten V1

## Zweck

Diese Datei trennt beschlossene Kataloge von Daten, die vor Produktivsetzung noch
geliefert werden müssen. Fehlende Initialdaten sind keine Erlaubnis, Werte im Code
zu erfinden.

## Inventare

1. MORE Hamburg-Kombi
2. Hamburg-Kombi+
3. Radio Hamburg
4. ROCK ANTENNE Hamburg
5. 80er 90er OLDIE ANTENNE Hamburg
6. CARAVAN.fm
7. MORE-Kombi Online Audio
8. MORE-Kombi Podcast
9. ffn Hamburg Plus
10. RADIO BOLLERWAGEN DAB+ Hamburg
11. MORE-Kombi Events Radio Hamburg
12. MORE-Kombi Events 80er 90er OLDIE ANTENNE Hamburg
13. MORE-Kombi Events CARAVAN.fm
14. MORE-Kombi Events ROCK ANTENNE Hamburg

Festlegungen:

- HAMBURG ZWEI ist in der MORE Hamburg-Kombi durch 80er 90er OLDIE ANTENNE Hamburg ersetzt.
- `radio ffn` aus der Referenz entspricht `ffn Hamburg Plus`.
- `BOLLERWAGEN` aus der Referenz entspricht `RADIO BOLLERWAGEN DAB+ Hamburg`.
- Kombis besitzen eigene Preislisten; enthaltene Sender werden zusätzlich gepflegt.

## Oberkategorien

ADV-001a legt die sechs kanonischen Oberkategorien per Migration an (stabile
technische Keys; nicht beiläufig umbenennen):

| Key | Anzeigename |
|---|---|
| `spots` | Spots |
| `special_advertising_formats` | SWF / Sonderwerbeformen |
| `online_audio` | Online Audio |
| `social_online` | Social Media / Online |
| `events_promotion` | Events / Promotion |
| `barter` | Gegengeschäft |

Bekannte Bestands-Codes der ADV-001a-Migration (explizite Map, fail-closed):
`spot_classic` → `spots`. Unbekannte Codes dürfen nicht pauschal zugeordnet
werden. Test-only-Codes wie `spot_classic_alt` entstehen erst nach der Migration
und gehören nicht zum historischen Produktions-Backfill.

Produktion/Sonstiges ist keine Oberkategorie für normale Werbemittel, sondern eine
Zusatzzeile innerhalb einer Position.

## Werbemittel

1. Werbespot
2. Werbespot erstplatziert
3. Werbespot letztplatziert
4. Single-Spot
5. Showsponsoring-Single-Spot
6. Tandem / Reminder
7. Tridem
8. Jobspot
9. Gegengeschäft
10. Promo/Moderation
11. Event-Tipp
12. Veranstaltungstipp
13. Preseller
14. Trailer/Vorpr. Element Station Voice
15. Abbinder
16. Allonge
17. Opener
18. Bumper
19. Stinger
20. Closer
21. Gewinnspiel/Pay-Off
22. Sondersendung (4x90 Sek.)
23. Influencer-Spot
24. Influencer-Spot als Single-Spot
25. Infomercial / Profi-Tipp
26. Visual-Spot als Single-Spot
27. Visual-Spot mit .de-Nennung
28. Presenting-Spot
29. Online Anzeigencontainer
30. Online Facebook
31. Online GWS
32. Online Instagram
33. Online Instagram (Influencer)
34. Online Sondersendung
35. Online TikTok
36. Off-Air
37. Pre-Stream
38. In-Stream
39. Pre-Stream Influencer
40. In-Stream Influencer
41. Mid-Roll Spotify / Deezer / YouTube Musikumfeld
42. Native-Ad

Die kombinierten Werbemittel `Pre-/In-Stream` und `Pre-/In-Stream Influencer`
entfallen. Single-Spots für Hamburg-Kombi+, Bollerwagen und ffn werden durch die
Kombinationstabelle ausgeschlossen.

## Standard-Auswahlwerte

### Einplanung durch

- Disposition
- OAP
- PDM-Digital / Niklas Farin
- Redaktion
- Moderator
- Events
- darf nicht geplant werden
- kombinierte Sonderhinweise, z. B. `Disposition, bitte Abbinder nutzen`

### Buchungskennzeichen – bekannte Beispiele

- Spots `(L)`
- SWF `(K)`
- Online Audio `(UA)`
- Social Media `(US)`
- Mod-Influencer `(UI)`
- Gegengeschäft `(B)`
- Events/Promotion `(P)`
- Spotproduktion/Sonstige `(S)`
- `UC`

Die konkrete Zuordnung wird ausschließlich durch die vollständige
Inventar-Werbemittel-Kombinationstabelle geliefert.

### Upload-Kategorien

- Kundenbestätigung
- Audio-Motiv
- Briefing
- Skript/Text
- Layout/Grafik
- Event-Unterlagen
- Sonstiges

### Produktionstypen

- Spotproduktion
- Influencer-Produktion
- Social-Media-Produktion
- Fremdkosten
- Sonstiges

## Beschlossene Preis-/Regeldefaults

- Spotlängenindex: 1–15 = 110; 16–24 = 105; 25–34 = 100; ab 35 = 95.
- AE-Standard: 15 Prozent.
- Single-Spot-Aufschlag initial: +50 Prozent.
- Erst-/Letztplatzierung initial: +30 Prozent.
- Audio-Upload: MP3/WAV, Standardlimit 50 MB je Datei.
- Produktionsmenge: optional, Standardwert 0.
- Social Media initial vollständig nicht rabattierbar und nicht AE-fähig.
- Boosterbudget separat, nicht rabattierbar und nicht AE-fähig.
- Adserver-TKP Spotify/Deezer/YouTube: 15 EUR als Verkaufsbestandteil.

### RHH-Referenzen SWF

| Produkt | Standardlänge | Aufschlag |
|---|---:|---:|
| Trailer | 20 s | +30 % |
| Allonge | 10 s | +30 % |
| Promo | 20 s | +50 % |
| CityLife | 20 s | +50 % |
| Gewinnspieldurchgang | 25 s | +50 % |

### RHH-Referenzen Social Media

| Element | Medienpreis | Booster | Gesamt |
|---|---:|---:|---:|
| Facebook Post | 450 EUR | 50 EUR | 500 EUR |
| Instagram/Facebook Story | 900 EUR | 50 EUR | 950 EUR |
| Instagram/Facebook Reel | 1.300 EUR | 100 EUR | 1.400 EUR |

## Vor Produktivsetzung noch zu liefern

- vollständige Kombinationstabelle mit Buchungskennzeichen, Zuständigkeit und Hinweisen,
- Jahrespreislisten je Inventar im vereinbarten Excel-Format,
- Festpreise für Online Audio, Podcast, Events und weitere digitale Produkte,
- Produktionspreise je Inventar und Typ,
- finale technische und DMP-Targetings einschließlich Aufschlägen,
- erforderliche Kunden-, Agentur- und Kontaktstammdaten,
- Nutzer, Rollen, Rabattgrenzen und Sonderfreigaberechte,
- genaue Texte der Sonderhinweise,
- freigegebene PDF-Layouts,
- Browsermatrix, E-Mail-Absender, Domain, Backup- und Aufbewahrungsparameter.

