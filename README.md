# Dispo – Kalkulation und Disposition

Internes Websystem für die Kalkulation, Freigabe und Übergabe von Radio-, Audio-,
Social-Media-, Event- und Sonderwerbeaufträgen an die Disposition.

## Projektstatus

V1-Fachlichkeit und Technologie-ADRs sind verbindlich. Phase 0 (Projektbasis)
ist **technisch endgültig abgenommen** (GitHub-Actions-Jobs `ci` und `mysql`).
UX-GATE-A und UX-GATE-B sind freigegeben (App-Shell, Kalkulations-Wizard,
Spot Classic). UX-GATE-C und UX-GATE-D bleiben blockiert
([`docs/ux-ui-gate.md`](docs/ux-ui-gate.md)).

## Start

Vollständige Befehle: [`docs/entwicklung-lokal.md`](docs/entwicklung-lokal.md).

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm ci
npm run build
npx playwright install chromium
```

## Verbindliche Quellen

1. [`docs/anforderungskatalog.md`](docs/anforderungskatalog.md) – vollständige V1-Anforderungen
2. Fachspezifische Dokumente unter [`docs/`](docs/README.md) – präzisierende Modelle und Regeln
3. Akzeptierte Architekturentscheidungen unter [`docs/entscheidungen/`](docs/entscheidungen/)
4. Automatisierte Tests – ausführbare Spezifikation des implementierten Verhaltens

Bei Widersprüchen gilt diese Reihenfolge. Ein Widerspruch darf nicht stillschweigend
im Code aufgelöst werden, sondern muss als fachliche oder technische Entscheidung
dokumentiert werden.

## Dokumentation

- [Anforderungskatalog](docs/anforderungskatalog.md)
- [Fachmodell und Begriffe](docs/fachmodell.md)
- [Berechnungslogik](docs/berechnungslogik.md)
- [Workflows und Berechtigungen](docs/workflows-und-berechtigungen.md)
- [Dynamisches Feldsystem](docs/dynamisches-feldsystem.md)
- [Fachliches Datenmodell](docs/datenmodell.md)
- [UI-/UX-Konzept](docs/ui-ux-konzept.md)
- [UX/UI-Gates](docs/ux-ui-gate.md)
- [Test- und Abnahmekatalog](docs/test-und-abnahmekatalog.md)
- [Initialdaten](docs/initialdaten.md)
- [Umsetzungsplan](docs/umsetzungsplan.md)
- [Backlog V1](docs/backlog-v1.md)
- [Fortschritt](docs/fortschritt.md)
- [Lokale Entwicklung](docs/entwicklung-lokal.md)
- [Blocker und Entscheidungslog](docs/blocker-und-entscheidungslog.md)

## Arbeitsweise mit Cursor

Cursor liest die globalen Projektanweisungen aus [`AGENTS.md`](AGENTS.md) und die
fokussierten Regeln aus [`.cursor/rules/`](.cursor/rules/). Vor einer Umsetzung
soll Cursor die betroffenen Anforderungs-IDs nennen und anschließend passende
Tests ergänzen oder aktualisieren.

Beispielauftrag:

> Setze V1 weiter um.

## Noch nicht Teil der laufenden Umsetzung

- vollständige Kombinationstabelle Sender/Inventar × Werbemittel
- Jahres-, Produktions- und Digitalpreislisten
- produktive Infrastruktur- und Zugangsdaten
- produktives Deployment
- Trailer/SWF-, Influencer-/Social-, Dispo-, Freigabe-, Standardangebots- und
  Admin-Fachoberflächen (UX-GATE-C/D)
