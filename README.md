# Consent Banner für Kirby

Kirby-Plugin `berbach/kirby-consent-banner`: ein Consent-Banner mit
**Google Consent Mode v2**, **Matomo**- und **Script**-Steuerung, einem
**Consent-Protokoll** und der Freischaltung eingebetteter Inhalte **ohne
Seiten-Reload**. Es ist die Kirby-Fassung der TYPO3-Extension
`bb/consent_banner` und hat denselben Aufbau.

*Consent banner for Kirby 5 with Google Consent Mode v2, Matomo and script
control, a consent log (SQLite), a cookie information window and placeholders
for embedded content. The documentation is in German; the panel and the banner
speak German and English.*

## Funktionen

- **Drei Ebenen:** Banner → Gruppen → Components (einzelne Dienste), jeweils
  mit Cookie-Liste.
- **Vier Integrations-Typen** je Component:
  - **Inhalt / Iframe:** Platzhalter statt Inhalt; nach Einwilligung erscheint
    der Inhalt ohne Reload und verschwindet beim Widerruf wieder.
  - **Google Consent Mode (gtag):** setzt die Consent-Signale
    (`default`/`update`), optional mit GTM-Container.
  - **Matomo:** steuert `_paq` (`setConsentGiven`/`forgetConsentGiven`), auch
    mit Matomo Tag Manager.
  - **Script laden:** fügt bei Einwilligung ein Script ein, entfernt es beim
    Widerruf und führt ein Aufräum-Script aus.
- **Iframes fremder Anbieter** werden automatisch ersetzt: durch den
  Platzhalter der passenden Component, sonst durch einen Hinweis.
- **Consent-Protokoll** in SQLite, je Besucher eine Zeile, mit Suche und
  automatischer Löschung nach der Laufzeit der Einwilligung.
- **Versionierung:** Jede Änderung an Banner, Gruppe oder Component zählt die
  Version hoch; Besucher werden danach erneut gefragt.
- **Cookie-Informationen** als eigenes Fenster im Frontend; im Panel füllt eine
  **Dienst-Suche** Anbieter, Domains, Datenschutz-Link, erhobene Daten und die
  Cookies eines bekannten Dienstes aus.
- **Drei Layouts:** Banner unten, Overlay, kompaktes Overlay mit
  Ablehnen-Button.
- Panel und Standardtexte auf **Deutsch und Englisch**, alle Texte je Sprache
  überschreibbar.

## Voraussetzungen

| Komponente | Anforderung |
| --- | --- |
| Kirby | 5 |
| PHP | 8.2 oder neuer, mit `pdo_sqlite` |

## Installation

```bash
composer require berbach/kirby-consent-banner
```

Solange das Paket nicht bei Packagist liegt, zuvor das Repository eintragen:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/berbach/kirby-consent-banner.git" }
]
```

Ohne Composer: das Repository nach `site/plugins/consent-banner` legen.

## Schnellstart

1. Im Panel den Bereich **Consent Banner** öffnen und **Consent Banner
   anlegen** wählen. Es entsteht die Seite `consent-banner` mit der Gruppe
   „Essenziell“.
2. Texte, Layout und Laufzeiten eintragen, im Tab **Consent-Gruppen** Gruppen
   und darin Components anlegen. Je Component den **Integrations-Typ** wählen.
3. Im Tab **Cookie-Informationen** einer Component den Dienst suchen
   (z. B. „YouTube“); ein Klick füllt die Felder und die Cookie-Liste.
4. Gruppen und Components auf **Aktiv** stellen, zuletzt den Banner selbst.

Mehr ist nicht nötig: Das Plugin fügt Stylesheet, Banner und Script in jede
HTML-Seite ein und ersetzt die Iframes fremder Anbieter. Auf einer Website mit
nur einer Sprache legt die Option `language` die Sprache der Standardtexte
fest:

```php
// site/config/config.php
return [
    'berbach.consent-banner.language' => 'de',
];
```

Einrichtung, Optionen, Platzhalter für eigene Inhalte und das Protokoll
beschreibt **[Documentation/Configuration.md](Documentation/Configuration.md)**.

## Aufbau

| Baustein | Aufgabe |
| --- | --- |
| `blueprints/pages/consent-banner*.yml` | Banner, Gruppe und Component als Seiten des Panels |
| `src/ConsentBanner.php` | findet Banner, Gruppen und Components, zählt die Version |
| `src/BannerData.php` | die Daten des Banners für das Frontend (`script#bbBannerData`) |
| `src/Renderer.php` | fügt Stylesheet, Daten und Script in die Seite ein, ersetzt Iframes |
| `src/Placeholder.php` | Platzhalter mit Einwilligungs-Schalter und zurückgestelltem Inhalt |
| `src/ConsentMode.php` | früher Bootstrap im `<head>` für Google Consent Mode und Matomo |
| `src/SaveAction.php`, `src/ConsentLog.php` | Route `consent-banner-api/save` und das Protokoll in SQLite |
| `src/Panel.php`, `index.js` | Bereich „Consent Banner“ mit Übersicht und Protokoll, Feld `consentservice` |
| `src/ServiceDatabase.php`, `data/cookie_database.json` | Dienst-Datenbank der Suche |
| `resources/`, `assets/dist/` | Quellen und gebaute Fassung von `CbLoader.js` und `ConsentBanner.css` |

## Assets bauen

```bash
npm install
npm run build        # Produktion
npm run build:dev    # lesbar, mit Konsolenausgabe
```

Das Ergebnis in `assets/dist/` gehört ins Repository. Kirby liefert jede Datei
eines Plugins unter einem eigenen Hash aus; deshalb ist das JavaScript eine
einzige Datei und das Stylesheet bindet Bilder als Data-URI ein.

## Tests

```bash
composer install
composer test
```

## Lizenz

GPL-2.0-or-later
