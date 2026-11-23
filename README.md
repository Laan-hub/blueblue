# blueblue – GfT-Website

Website der (fiktiven) **Gesellschaft für Testzwecke (GfT)** auf Basis von **Craft CMS 5 (Solo)** mit **MySQL 8**, zweisprachig (**DE** unter `/`, **EN** unter `/en/`), lokal betrieben mit **DDEV**.

Alle Texte, Zahlen, Bilder und Kontakte sind Dummy-Inhalte für den Konzeptentwurf.

---

## Voraussetzungen (einmalig, macOS)

```bash
brew install git
brew install --cask orbstack        # oder Docker Desktop
brew install ddev/ddev/ddev
mkcert -install
```

## Ersteinrichtung

```bash
git clone https://github.com/laan-hub/blueblue.git
cd blueblue
ddev start                       # Webserver + MySQL, legt .env an
ddev composer install            # Craft + Plugins
ddev craft setup/security-key    # Sicherheitsschlüssel in .env
ddev craft install               # Admin-Benutzer anlegen (URL: $PRIMARY_SITE_URL)
ddev craft up                    # Struktur übernehmen + Dummy-Inhalte anlegen
ddev launch admin
```

- Website: https://blueblue.ddev.site (DE) · https://blueblue.ddev.site/en (EN)
- Control Panel: https://blueblue.ddev.site/admin

## Änderungen holen

```bash
git pull
ddev composer install
ddev craft up        # übernimmt Struktur (config/project) und Migrationen; Inhalte bleiben erhalten
```

`craft up` legt beim ersten Mal auch die Dummy-Inhalte an (Content-Migration `migrations/m261123_150000_seed_content.php`).
Bereits vorhandene Einträge werden dabei **nie überschrieben**.

---

## Struktur im Control Panel

### Bereiche (Einträge)

| Bereich | Typ | URL DE / EN | Zweck |
|---|---|---|---|
| Startseite | Single | `/` · `/en/` | Seitenbaukasten |
| Seiten | Struktur | `/{slug}` | Über uns, Kontakt, Impressum, Übersichtsseiten (Projekte, Akzente …) |
| Projekte | Channel | `/projekte/…` · `/en/projects/…` | Projektbeschreibungen mit Fakten, Status, Ländern, Themen |
| Leistungen | Struktur | `/leistungen/…` · `/en/services/…` | Themenfelder / Leistungsbeschreibungen |
| Weltweit | Struktur (Region → Land) | `/weltweit/afrika/kenia` | Regionen- und Länderseiten, zeigen Projekte automatisch |
| Akzente (Magazin) | Channel | `/akzente/…` | Artikel / Geschichten |
| News | Channel | `/aktuelles/…` · `/en/news/…` | Meldungen |
| Ausschreibungen | Channel | `/ausschreibungen/…` | Vergaben mit Frist und Status |
| **Teaser** | Channel | – | Freie Teaser, die in Teaser-Listen **wiederverwendet** werden |
| **Wiederverwendbare Module** | Channel | – | Zentral gepflegte Module (z. B. CTA, Newsletter), die auf vielen Seiten eingebunden werden |
| **Grounding** | Single | `/grounding` | Faktenseite für Suchmaschinen und KI-Systeme |

### Seitenbaukasten („Seiteninhalte“)

Fast alle Seiten werden aus denselben Modulen zusammengesetzt:

Bühne (Hero-Slider) · Seiteneinstieg · Suche · Kennzahlen · Teaser-Liste · Regionen-Übersicht · Leistungen · Bild & Text (geteilt, auch als Zitat-Panel) · Ausschreibungen · Call to Action mit Zielgruppen · Text (zweispaltig) · Karten / Prinzipien / Schritte · Fließtext (Artikel) · Zitat · Bild(er) · Bildergalerie · Daten-Panels (Fortschritt, Budgetverteilung) · Downloads & Kontakt · Kontaktformular · Newsletter · Rechtliche Angaben · Hinweis · **Wiederverwendbares Modul**

**Wiederverwendung**
- **Teaser** werden automatisch aus jedem Eintrag gebaut (Bild, Dachzeile, Titel, Kurzbeschreibung). Eine Teaser-Liste kann Einträge manuell auswählen oder automatisch zeigen (neueste Projekte/News/Akzente, alle Leistungen, ähnliche Einträge).
- Eigene, freie Teaser legst du unter **Teaser** an und wählst sie in beliebig vielen Teaser-Listen aus.
- Ganze Module (z. B. „Lassen Sie uns Wandel gestalten“) pflegst du einmal unter **Wiederverwendbare Module** und bindest sie mit dem Modul „Wiederverwendbares Modul“ ein. Änderungen wirken überall.
- **Sprungmarke**: Wenn du bei einem Modul eine Sprungmarke einträgst, erscheint es in der Seiten-Navigation (z. B. bei Projekten: Überblick · Ansatz · Ergebnisse …).

### Globals

- **Website**: Text in der oberen Leiste, Footer-Claim, Kontakt-E-Mail, Social-Media-Links
- **Navigation**: Servicenavigation, Hauptnavigation mit Untermenüs, Footer-Spalten, rechtliche Links
- **Organisation (JSON-LD)**: Name, Beschreibung, Logo, Adresse, Kontaktpunkte, USt-IdNr., Register, offizielle Profile (`sameAs`), Fachgebiete (`knowsAbout`), Tätigkeitsgebiete (`areaServed`)

### Zweisprachigkeit

- Deutsch ist die Hauptsprache, Englisch liegt unter `/en/`. Zwischen den Sprachen wechselst du im Control Panel oben links.
- Texte, Links, Tabellen und Bildtexte (Alt-Text, Bildunterschrift) sind pro Sprache übersetzbar; Bilder, Verknüpfungen und Einstellungen gelten für beide.
- Slugs sind pro Sprache eigenständig (z. B. `/projekte/lokale-energie-kenia` ↔ `/en/projects/local-energy-kenya`); `hreflang` wird automatisch gesetzt.
- Feste Template-Texte stehen in `translations/de/site.php`.

## KI-Lesbarkeit & SEO

- **JSON-LD** auf jeder Seite (`templates/_partials/jsonld.twig`): Organization/NGO, WebSite mit Suchfunktion, WebPage, BreadcrumbList, Article/NewsArticle, Project, Service und FAQPage. Die Daten kommen aus dem Global „Organisation (JSON-LD)“ und den Feldern der Einträge.
- **Grounding-Seite** (`/grounding`, `/en/grounding`): klare Fakten, FAQ und Organisationsdaten an einem Ort.
- **`/llms.txt`**: maschinenlesbare Zusammenfassung mit Links auf alle wichtigen Seiten.
- **`/sitemap.xml`** mit Sprachvarianten, **`/robots.txt`**, Open-Graph-Tags und Canonical-URLs.
- Im Dev-Modus sind alle Seiten auf `noindex` gesetzt.

## Frontend

- Templates: `templates/` (Layout, Partials, ein Template pro Modul in `templates/_blocks/`, Teaser-Komponente in `templates/_components/teaser.twig`)
- CSS: `web/assets/css/site.css` (Design-Tokens aus dem Styleguide), JS: `web/assets/js/site.js`, ohne Build-Schritt
- Schriften: Inter & Lora, selbst gehostet (SIL Open Font License), damit keine Daten an Google übertragen werden
- Bilder werden automatisch als WebP in passenden Größen ausgeliefert (Fokuspunkt im Control Panel setzbar)

## Struktur vs. Inhalte

| Was | Wo | Wer ändert es |
|---|---|---|
| Templates, CSS/JS, Struktur (`config/project/*.yaml`) | Git | Entwicklung |
| Inhalte (Einträge, Texte) | lokale Datenbank | Redaktion |
| Hochgeladene Bilder/Dateien (`web/uploads`) | lokal, nicht in Git | Redaktion |

Felder, Bereiche usw. bitte nicht selbst im Control Panel anlegen, sonst gibt es beim nächsten `git pull` Konflikte in `config/project/`.
Falls doch: die Änderungen in `config/project/` committen und pushen.

Das Schema wird in der Entwicklung mit `php scripts/build-schema.php` aufgebaut (idempotent). Auf deinem Rechner brauchst du das Skript nicht, dafür reicht `craft up`.

## Nützliche Befehle

```bash
ddev stop                               # Projekt anhalten
ddev export-db > backup.sql.gz          # Datenbank sichern
ddev import-db --file=backup.sql.gz     # Datenbank einspielen
ddev craft clear-caches/all
```

## Offene Punkte / nächste Schritte

- Kontakt- und Newsletter-Formular sind statisch (Konzept); für echten Versand z. B. das Plugin „Contact Form“ anbinden.
- Suche nutzt die Craft-Suche; das Briefing sieht später Elasticsearch vor.
- Seiten „Weltweit“ mit interaktiver Karte, Ausschreibungs-Tabelle mit Sortierung und Cookie-Banner sind im Briefing genannt, aber noch nicht gestaltet.
