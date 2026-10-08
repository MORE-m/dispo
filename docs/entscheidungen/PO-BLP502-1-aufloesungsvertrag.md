# PO-BLP502-1 – Auflösungs- und Pin-Vertrag Produktionspreise

Status: **Akzeptiert** (technische Ableitung aus PO-BLP502-1 E1/H1a + PO-PRI-YEAR-1;
keine neue fachliche Preisjahres-Entscheidung)
Stand: 8. Oktober 2026
Basis: `feat/bl-p5-02a-spot-production` auf `origin/main` @ `7583a3e…`
IDs: `PRO-004`, `VER-*`, `PO-PRI-YEAR-1`, `PO-BLP502-1` E1/H1a
Slice: **BL-P5-02a**

## Gültige Produktionsliste

Auflösung einer Live-Bindung:

```text
aktive production_price_lists
  WHERE inventory_id = Träger.inventory_id
    AND production_type = Zeile.production_type
    AND year = Träger.price_year
    AND status = active
```

- **`price_year`** der Trägerposition ist dasselbe Kalenderjahr wie bei Spotlisten
  (`Europe/Berlin`, PO-PRI-YEAR-1). Kein zweites Produktions-Preisjahr im Wizard.
- Höchstens **eine** aktive Liste je `(inventory_id, production_type, year)`;
  Aktivierung archiviert die bisherige Active (Writer-Vertrag, analog Spotlisten).
- Mehrdeutigkeit (keine oder >1 Active) → **fail-closed** bei vorhandener Zusatzzeile.
- Ohne Zusatzzeile blockiert fehlende Produktionskonfiguration die Spot-Kalkulation **nicht**.

## Pin / Freeze

Beim Speichern je Zusatzzeile eingefroren:

- `production_price_list_id`, `production_price_list_version`
- `unit_price`, `is_discountable`, `is_ae_eligible`
- `label`, `quantity`, `remark`, `line_gross`, Rabatt-/AE-/N/N-Beiträge
- Dispo zusätzlich Kennzeichen **S**

### Wann bleibt der Pin?

- Mengenänderung, Bezeichnung/Bemerkung, Re-Save ohne Inventar-/Jahrwechsel
- Reload / Preview mit gleichem Pin (keine stillen Live-Preisänderungen)

### Wann neu binden?

- Expliziter **Inventarwechsel** der Trägerposition → Active des Zielinventars für
  dasselbe `price_year` (H1a). Fehlt Active → Preview/Save blockieren, Zeile behalten.
- Expliziter **Preisjahrwechsel** der Trägerposition → Active für neues Jahr
  (gleiche Logik wie Spot-Rebind).

### Wann blockieren ohne Löschen?

- Träger-Medium ≠ Spot Classic oder Methode ≠ Average bei vorhandenen Zusatzzeilen
- Zeile bleibt; Speichern/Übergabe bis Entfernung oder zulässige Auswahl gesperrt

### Träger löschen

Zugehörige Zusatzzeilen werden mitgelöscht (Cascade). Dispo-Snapshots unverändert.

## Sales-Payload

Erlaubt: `client_key`, `production_type`, `label`, `quantity`, `remark`, `sort`,
optional `production_price_list_id` nur als Expected-Pin-Hinweis.
**Verboten** (serverseitig ignoriert/abgelehnt): `unit_price`, Flags, `line_gross`,
Versions-/Beitragsmanipulation, freie Preise.

## Summe

```text
line_gross     = quantity × unit_price
production_nn  = je Zeile nach Flags Rabatt/AE (initial beide false)
position.nn_invest = media_nn_invest + Σ production_nn
media_gross unverändert (ohne Produktion)
```
