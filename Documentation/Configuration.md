# Consent Banner – Einrichtung & Konfiguration

## 1. Überblick

Der Banner besteht aus drei Ebenen, die im Panel Seiten sind:

| Ebene | Seite | Inhalt |
| --- | --- | --- |
| **Banner** | `consent-banner` | Texte, Layout, Laufzeiten, Tracking |
| **Gruppe** | Unterseite des Banners | z. B. „Essenziell“, „Statistik“, „Externe Medien“ |
| **Component** | Unterseite einer Gruppe | ein Dienst mit Integrations-Typ und Cookie-Informationen |

Der Status der Seite schaltet sie ein und aus: Ein Banner als Entwurf erscheint
nicht im Frontend, eine Gruppe oder Component als Entwurf fehlt im Banner.
Die Reihenfolge der Gruppen und Components ist die Reihenfolge im Panel;
essenzielle Gruppen stehen immer vorn.

Im Frontend haben diese Seiten keine Adresse: `…/consent-banner` zeigt die
Fehlerseite. Wer `$site->index()` ausgibt (Sitemap, Suche), filtert sie über
die Templates `consent-banner`, `consent-banner-group` und
`consent-banner-component` heraus.

## 2. Bereich „Consent Banner“

Der Bereich im Panel-Menü zeigt Status, Version und Layout des Banners, alle
Gruppen mit ihren Components und führt zum **Consent-Protokoll**. Gibt es noch
keinen Banner, legt **Consent Banner anlegen** ihn mit der Gruppe „Essenziell“
an.

Rollen ohne Zugriff bekommen ihn im Blueprint der Rolle entzogen:

```yaml
# site/blueprints/users/editor.yml
permissions:
  berbach.consent-banner:
    access: false
```

## 3. Banner

| Tab | Inhalt |
| --- | --- |
| **Banner** | Titel, Beschreibung, Links am unteren Rand (Impressum, Datenschutz), Version |
| **Consent-Gruppen** | die Gruppen des Banners |
| **Texte der Beschriftungen** | Schaltflächen und Beschriftungen der Cookie-Informationen; ein leeres Feld zeigt den Standardtext |
| **Banner-Einstellung** | Layout, Öffnen der Einstellungen, Laufzeiten |
| **Tracking / Consent Mode** | Google Tag Manager und Matomo |

**Layout.** `cb-bottom` zeigt den Banner über die volle Breite am unteren Rand,
`cb-overlay` mittig mit allen Einstellungen. `cb-overlay-compact` zeigt zuerst
nur Text und Schaltflächen, darunter „Nur Essenzielle akzeptieren“, und klappt
die Einstellungen über „Erweiterte Einstellungen“ auf. Das X schließt dort nie
ohne Entscheidung: In der kompakten Ansicht speichert es nur die essenziellen
Dienste, in den Einstellungen die aktuelle Auswahl.

**Öffnen der Einstellungen.** Besucher ändern ihre Einwilligung über einen
Textlink in der Fußzeilennavigation oder über ein Schaltflächen-Widget unten im
Fenster. Für den Textlink nennt das Feld „Klasse oder ID der
Fußzeilennavigation“ einen CSS-Selektor; der Link entsteht als Kopie des ersten
Eintrags. Findet der Selektor nichts, erscheint das Widget. Unabhängig davon
öffnet jedes Element mit dem Attribut `data-consent-banner-open` die
Einstellungen:

```html
<button type="button" data-consent-banner-open>Cookie-Einstellungen</button>
```

**Laufzeiten.** Nach der „Laufzeit des Banners“ (14, 21 oder 28 Tage) fragt der
Banner erneut. Die „Laufzeit der Einwilligung“ (1 bis 3 Jahre) gilt für das
Cookie und für die Einträge im Protokoll.

**Version.** Jede Änderung an Banner, Gruppe oder Component zählt die Version
hoch, ebenso Anlegen, Löschen, Umsortieren und ein neuer Status. Besucher, die
einer älteren Version zugestimmt haben, werden erneut gefragt.

## 4. Components

### 4.1 Integrations-Typen

| Typ | Verhalten im Frontend |
| --- | --- |
| **Inhalt / Iframe (Platzhalter)** | Statt des Inhalts erscheint ein Platzhalter mit Schalter; nach Einwilligung wird der Inhalt ohne Reload eingeblendet. |
| **Google Consent Mode (gtag)** | Setzt die gewählten Signale bei Einwilligung auf `granted`, sonst auf `denied`. Braucht die Container-ID im Banner. |
| **Matomo** | Sendet `setConsentGiven`/`setCookieConsentGiven`, beim Widerruf `forgetConsentGiven`. Braucht Matomo-URL und Site-ID oder die Container-URL im Banner. |
| **Script laden** | Fügt „Script bei Einwilligung“ als `<script>` ein; beim Widerruf wird es entfernt und „Script bei Widerruf“ ausgeführt. |

Matomo hat einen Tracker: Die Einwilligung in irgendeine Matomo-Component gilt
für ihn.

### 4.2 Iframes

Das Plugin ersetzt in jeder HTML-Seite die Iframes fremder Anbieter:

- Nennt eine Component vom Typ „Inhalt / Iframe“ die Domain des Iframes,
  erscheint ihr Platzhalter mit Schalter. Die Domains stehen im Feld **Domains
  der Iframes**; ist es leer, gelten die „Domains des Anbieters“ aus den
  Cookie-Informationen. `youtube.com` gilt auch für `www.youtube.com` und
  andere Subdomains.
- Für alle anderen fremden Iframes erscheint der Hinweis „Drittanbieter HTML
  wurde entfernt.“ ohne Schalter; der Iframe ist dann nicht mehr in der Seite.
- Iframes der eigenen Domain, relative Iframes und Iframes ohne `src` bleiben.

Der Platzhalter ist für alle Besucher derselbe und verträgt sich mit dem
Seiten-Cache. Titel und Text stehen im Tab **Platzhalter**; ohne Titel steht
dort der Titel der Component.

### 4.3 Eigene Inhalte hinter die Einwilligung legen

Für alles, was kein Iframe ist (Karten per Script, Social-Media-Embeds), gibt
es das Snippet `consent-banner/gate`. `component` ist der Slug der Component
oder ihre ID:

```php
<?php snippet('consent-banner/gate', ['component' => 'google-maps'], slots: true) ?>
  <div id="map" data-lat="52.52" data-lng="13.40"></div>
<?php endsnippet() ?>
```

Oder als Funktion, etwa in einem Block-Snippet:

```php
<?= consentBanner()->gate('youtube', $block->toHtml()) ?>
```

Der Inhalt liegt bis zur Einwilligung in einem `<template>` und wird danach in
die Seite gesetzt. Scripts, die ihn brauchen, hören auf das Ereignis
`bb:content-injected`:

```js
document.addEventListener('bb:content-injected', (event) => {
  initMap(event.detail.element);
});
```

Gibt es die Component nicht oder ist sie ein Entwurf, gibt `gate` den Inhalt
unverändert aus.

### 4.4 Cookie-Informationen

Der Tab **Cookie-Informationen** enthält, was das Cookie-Informationsfenster
im Frontend zeigt: Dienst, Domains, Anbieter, Link zur Datenschutzerklärung,
erhobene Daten und die Cookies.

Das Feld **Dienst** sucht in der mitgelieferten Dienst-Datenbank
(`data/cookie_database.json`) nach Name, Kennung und Aliassen. Ein Klick auf
einen Vorschlag füllt die Felder darunter und hängt die Cookies des Dienstes an
die Liste an; Cookies, die schon in der Liste stehen, kommen nicht doppelt.

- Die Suche ist eine Ausfüllhilfe: Alle Werte bleiben editierbar und werden
  erst mit dem Speichern der Seite übernommen.
- Übernommen wird der Text in der Sprache, die gerade bearbeitet wird
  (de/en, sonst en, sonst de).
- Weitere Dienste stehen in `cookie_database.json` unter `services`. Felder je
  Dienst: `service_name`, `service_identifier`, `service_aliases` (Array),
  `service_provider`, `service_publisher`, `service_privacy_link`,
  `service_data_collected` (`{de, en}`) und `cookies[]` mit `name`, `type`
  sowie `lifetime`/`purpose`/`description` (jeweils `{de, en}`).

In eigenen Blueprints heißt der Feldtyp `consentservice`. Er füllt die Felder
`service_provider`, `service_publisher`, `service_privacy_link`,
`service_data_collected` und die Struktur `cookies` derselben Seite.

## 5. Tracking / Consent Mode

Aus den Feldern des Tabs **Tracking / Consent Mode** entsteht ein Script früh
im `<head>`, vor allen Tags:

- **Google:** gtag-Stub, `consent default` (alles `denied`, `security_storage`
  `granted`, `wait_for_update: 500`), für wiederkehrende Besucher sofort ein
  `consent update` aus dem Cookie, danach der GTM-Loader. Ohne Container-ID
  entsteht nichts.
- **Matomo:** `requireConsent` und `requireCookieConsent`, für wiederkehrende
  Besucher `setConsentGiven`, danach der Tracker oder der Container des Matomo
  Tag Managers.

Das Script liest das Cookie im Browser; die Seite bleibt für alle Besucher
gleich und kann aus dem Cache kommen.

## 6. Verhalten im Frontend

- **Erster Besuch:** Signale stehen auf `denied`, Iframes sind ersetzt, Scripts
  nicht geladen.
- **Einwilligung** im Banner oder am Schalter eines Platzhalters: Inhalte
  erscheinen ohne Reload, Signale und Matomo werden aktualisiert, Scripts
  eingefügt.
- **Widerruf:** Platzhalter kehren zurück, Signale gehen auf `denied`, Matomo
  wird widerrufen, eingefügte Scripts werden entfernt.

Gespeichert wird die Auswahl im Cookie `BbConsentPreferences`
(`{"<ID der Component>": true|false}`) und im gleichnamigen localStorage-Eintrag
(dazu User-ID, Version und Zeitpunkt).

## 7. Consent-Protokoll

Jede Entscheidung geht per `POST` an `consent-banner-api/save` und steht danach
im Protokoll: User-ID, Version des Banners, die Dienste mit Ja oder Nein,
Zeitpunkt der ersten und der letzten Entscheidung. Die User-ID errechnet der
Browser aus seinen Merkmalen; sie steht auch im Banner, damit Besucher sie
nennen können. Eine neue Entscheidung desselben Besuchers ersetzt seine Zeile.

- Die Datei liegt unter `site/logs/consent-banner/consent-log.sqlite`. Sie
  gehört nicht ins Repository und nicht ins Deployment, aber in die
  Datensicherung.
- Die Ansicht im Panel sucht nach der vollständigen User-ID und löscht beim
  Öffnen Einträge, die älter sind als die Laufzeit der Einwilligung.
- Die Route nimmt nur Components des Banners an, kürzt Texte und begrenzt die
  Größe der Anfrage.

Das Protokoll enthält eine Kennung je Besucher und gehört damit in das
Verzeichnis der Verarbeitungstätigkeiten und in die Datenschutzerklärung.

## 8. Optionen

Alle in `site/config/config.php`, mit dem Präfix `berbach.consent-banner.`:

| Option | Bedeutung | Vorgabe |
| --- | --- | --- |
| `language` | Sprache der Standardtexte auf einer Website mit nur einer Sprache, z. B. `'de'` | `null` (Englisch) |
| `page` | ID der Banner-Seite | `'consent-banner'` |
| `inject` | Stylesheet, Bootstrap, Daten und Script in jede HTML-Seite einfügen | `true` |
| `iframes` | Iframes fremder Anbieter ersetzen | `true` |
| `css` | Stylesheet des Plugins laden | `true` |
| `database` | Datei des Protokolls | `site/logs/consent-banner/consent-log.sqlite` |
| `nonce` | Nonce für Inline-Scripts: String oder Closure | `null` |

Mit `inject => false` kommen die Teile über Snippets ins Template:

```php
<head>
  …
  <?php snippet('consent-banner/head') ?>
</head>
<body>
  …
  <?php snippet('consent-banner/body') ?>
</body>
```

## 9. Aussehen

Farben und Radius der Schaltflächen lassen sich mit Custom Properties setzen:

```css
:root {
  --bb-cb-accent: #016882;
  --bb-cb-accent-contrast: #fff;
  --bb-cb-radius: 4px;
}
```

Alle Klassen beginnen mit `bb-`. Wer das Aussehen ganz selbst bestimmt,
schaltet das Stylesheet mit `css => false` ab oder baut es aus
`resources/Scss` neu.

## 10. Content Security Policy

Sendet die Website eine CSP mit Nonce, bekommt das Plugin sie über die Option
`nonce`:

```php
'berbach.consent-banner.nonce' => fn () => MyCsp::nonce(),
```

Die Inline-Scripts des Plugins und die Scripts vom Typ „Script laden“ tragen
sie dann. Von GTM, Matomo oder YouTube nachgeladene Scripts brauchen eine
Freigabe ihrer Domain, z. B. `https://www.googletagmanager.com`.

## 11. Mehrsprachigkeit

Auf einer mehrsprachigen Website werden Texte, Titel, Beschreibungen und
Cookie-Informationen je Sprache gepflegt; Einstellungen wie Layout, Laufzeiten,
Integrations-Typ und Tracking gelten für alle Sprachen. Standardtexte gibt es
auf Deutsch und Englisch.
