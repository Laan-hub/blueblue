# blueblue

Website auf Basis von **Craft CMS 5 (Solo)** mit **MySQL 8**, lokal betrieben mit **DDEV**.

## Voraussetzungen (einmalig auf deinem Rechner)

1. **Git**
2. **Docker**: Docker Desktop (Windows/macOS/Linux) oder OrbStack (macOS)
3. **DDEV**: Installation siehe https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/

PHP, MySQL oder ein Webserver müssen **nicht** installiert werden, das bringt DDEV mit.

## Ersteinrichtung

```bash
git clone https://github.com/laan-hub/blueblue.git
cd blueblue
ddev start                       # startet Webserver + MySQL, legt .env an
ddev composer install            # lädt Craft und Abhängigkeiten
ddev craft setup/security-key    # erzeugt einen Sicherheitsschlüssel in .env
ddev craft install               # fragt Admin-Benutzer, Passwort, E-Mail, Seitenname und URL ab
ddev launch admin                # öffnet das Control Panel im Browser
```

Bei `ddev craft install` als Website-URL `$PRIMARY_SITE_URL` angeben (oder `https://blueblue.ddev.site`).
Die Struktur (Felder, Sections usw.) wird automatisch aus `config/project/` übernommen.

- Website: https://blueblue.ddev.site
- Control Panel: https://blueblue.ddev.site/admin

## Änderungen holen (Updates aus dem Repository)

```bash
git pull
ddev composer install
ddev craft up        # übernimmt Strukturänderungen und Migrationen, Inhalte bleiben erhalten
```

## Wichtig: Struktur vs. Inhalte

| Was | Wo | Wer ändert es |
|---|---|---|
| Templates, CSS/JS, Struktur (`config/project/*.yaml`) | Git | Entwicklung |
| Inhalte (Einträge, Texte) | lokale Datenbank | Redaktion |
| Hochgeladene Bilder/Dateien (`web/uploads`) | lokal, nicht in Git | Redaktion |

Felder, Sections usw. bitte nicht selbst im Control Panel anlegen, sonst gibt es beim nächsten `git pull` Konflikte in `config/project/`.
Falls doch: die Änderungen in `config/project/` committen und pushen.

## Nützliche Befehle

```bash
ddev stop                     # Projekt anhalten
ddev export-db > backup.sql.gz  # Datenbank sichern
ddev import-db --file=backup.sql.gz
ddev craft clear-caches/all
```
