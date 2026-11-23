<?php
/**
 * Baut das Content-Schema (Sites, Volumes, Felder, Module, Sections, Globals) per Craft-API auf.
 *
 * Nur für die Entwicklung gedacht. Das Ergebnis landet automatisch als YAML in config/project/
 * und wird auf anderen Rechnern per `craft up` übernommen. Das Skript ist idempotent:
 * vorhandene Elemente werden aktualisiert statt doppelt angelegt.
 *
 * Aufruf: php scripts/build-schema.php
 */

use craft\base\Field;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\fieldlayoutelements\assets\AltField;
use craft\fieldlayoutelements\assets\AssetTitleField;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Assets;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Entries;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\fields\Tags;
use craft\fs\Local;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\TagGroup;
use craft\models\Volume;

require dirname(__DIR__) . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
Craft::$app->getProjectConfig()->writeYamlAutomatically = true;

function out(string $m): void { fwrite(STDOUT, $m . PHP_EOL); }
function fail(string $what, $model): never {
    fwrite(STDERR, "FEHLER bei $what: " . json_encode($model->getErrors(), JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

// ---------------------------------------------------------------------------
// Sites: Deutsch (primär, /) und Englisch (/en)
// ---------------------------------------------------------------------------
$sitesSvc = Craft::$app->getSites();
$de = $sitesSvc->getPrimarySite();
$de->name = 'Deutsch';
$de->handle = 'de';
$de->language = 'de';
$de->baseUrl = '$PRIMARY_SITE_URL';
if (!$sitesSvc->saveSite($de)) fail('Site de', $de);

$en = $sitesSvc->getSiteByHandle('en');
if (!$en) {
    $en = new Site(['groupId' => $de->groupId]);
}
$en->name = 'English';
$en->handle = 'en';
$en->language = 'en';
$en->hasUrls = true;
$en->baseUrl = '$PRIMARY_SITE_URL/en';
$en->primary = false;
$en->sortOrder = 2;
if (!$sitesSvc->saveSite($en)) fail('Site en', $en);
$de = $sitesSvc->getSiteByHandle('de');
$siteIds = ['de' => $de->id, 'en' => $en->id];
out('Sites ok');

// ---------------------------------------------------------------------------
// Dateisysteme & Volumes
// ---------------------------------------------------------------------------
$fsSvc = Craft::$app->getFs();
$volSvc = Craft::$app->getVolumes();
$fieldsSvc = Craft::$app->getFields();

function field(string $class, string $handle, string $name, array $config = []): Field
{
    $svc = Craft::$app->getFields();
    $existing = $svc->getFieldByHandle($handle);
    $f = $existing && get_class($existing) === $class ? $existing : new $class();
    if ($existing && get_class($existing) !== $class) {
        $svc->deleteField($existing);
    }
    $f->handle = $handle;
    $f->name = $name;
    $translatable = $config['_translatable'] ?? null;
    unset($config['_translatable']);
    foreach ($config as $k => $v) {
        if ($k === 'entryTypes' && $f instanceof Matrix) {
            $f->setEntryTypes($v);
            continue;
        }
        $f->$k = $v;
    }
    if ($translatable !== null) {
        $f->translationMethod = $translatable ? Field::TRANSLATION_METHOD_SITE : Field::TRANSLATION_METHOD_NONE;
    }
    if (!$svc->saveField($f)) fail("Feld $handle", $f);
    return $f;
}

function layout(string $type, array $tabs, bool $title = true, ?string $titleLabel = null, ?FieldLayout $existing = null): FieldLayout
{
    // UIDs bestehender Layout-Elemente wiederverwenden – Craft speichert Inhalte unter diesen UIDs.
    $uids = [];
    $tabUids = [];
    if ($existing) {
        foreach ($existing->getTabs() as $t) {
            $tabUids[$t->name] = $t->uid;
            foreach ($t->getElements() as $el) {
                $key = $el instanceof CustomField ? 'field:' . $el->getField()->handle . ':' . ($el->handle ?? '') : get_class($el);
                $uids[$key] = $el->uid;
            }
        }
    }
    $layout = new FieldLayout(['type' => $type]);
    if ($existing) {
        $layout->id = $existing->id;
        $layout->uid = $existing->uid;
    }
    $tabModels = [];
    $first = true;
    foreach ($tabs as $tabName => $items) {
        $tab = new FieldLayoutTab(['name' => $tabName, 'layout' => $layout, 'uid' => $tabUids[$tabName] ?? null]);
        $els = [];
        if ($first && $title) {
            if ($type === Asset::class) {
                $els[] = new AssetTitleField(['uid' => $uids[AssetTitleField::class] ?? null]);
                $els[] = new AltField(['uid' => $uids[AltField::class] ?? null]);
            } else {
                $els[] = new EntryTitleField(array_filter(['label' => $titleLabel, 'uid' => $uids[EntryTitleField::class] ?? null]));
            }
        }
        foreach ($items as $item) {
            [$f, $opts] = is_array($item) ? $item : [$item, []];
            $field = $f instanceof Field ? $f : Craft::$app->getFields()->getFieldByHandle($f);
            if (!$field) throw new Exception("Unbekanntes Feld $f");
            $opts['uid'] = $uids['field:' . $field->handle . ':' . ($opts['handle'] ?? '')] ?? null;
            $els[] = new CustomField($field, array_filter($opts, fn($v) => $v !== null));
        }
        $tab->setElements($els);
        $tabModels[] = $tab;
        $first = false;
    }
    $layout->setTabs($tabModels);
    return $layout;
}

function entryType(string $handle, string $name, array $tabs, array $config = []): EntryType
{
    $svc = Craft::$app->getEntries();
    $et = $svc->getEntryTypeByHandle($handle) ?? new EntryType();
    $et->handle = $handle;
    $et->name = $name;
    $hasTitle = $config['hasTitleField'] ?? true;
    $titleLabel = $config['_titleLabel'] ?? null;
    unset($config['_titleLabel']);
    foreach ($config as $k => $v) {
        $et->$k = $v;
    }
    $layout = layout(Entry::class, $tabs, $hasTitle, $titleLabel, $et->id ? $et->getFieldLayout() : null);
    $et->setFieldLayout($layout);
    if (!$svc->saveEntryType($et)) fail("Entry-Type $handle", $et);
    return $et;
}

function volume(string $handle, string $name, string $path): Volume
{
    $fsSvc = Craft::$app->getFs();
    $fs = $fsSvc->getFilesystemByHandle($handle) ?? new Local();
    $fs->handle = $handle;
    $fs->name = $name;
    $fs->path = '@webroot/uploads/' . $path;
    $fs->hasUrls = true;
    $fs->url = '@web/uploads/' . $path;
    if (!$fsSvc->saveFilesystem($fs)) fail("FS $handle", $fs);
    $svc = Craft::$app->getVolumes();
    $v = $svc->getVolumeByHandle($handle) ?? new Volume();
    $v->handle = $handle;
    $v->name = $name;
    $v->setFsHandle($handle);
    // Dateien in allen Sprachen verfügbar machen; Titel & Alt-Text pro Sprache übersetzbar
    $v->propagationMethod = \craft\enums\PropagationMethod::All;
    $v->titleTranslationMethod = Field::TRANSLATION_METHOD_SITE;
    $v->altTranslationMethod = Field::TRANSLATION_METHOD_SITE;
    if (!$svc->saveVolume($v)) fail("Volume $handle", $v);
    return $svc->getVolumeByHandle($handle);
}

// ---------------------------------------------------------------------------
// Basis-Felder
// ---------------------------------------------------------------------------
$T = ['_translatable' => true];
$N = ['_translatable' => false];

$plain = fn(string $h, string $n, string $instr = '', bool $multi = false) =>
    field(PlainText::class, $h, $n, $T + ['instructions' => $instr, 'multiline' => $multi, 'initialRows' => $multi ? 3 : 4]);

$plain('eyebrow', 'Dachzeile', 'Kleine Zeile über der Überschrift, z. B. „KENYA / CLEAN ENERGY“.');
$plain('headline', 'Überschrift', 'Zeilenumbrüche werden übernommen.', true);
$plain('headline2', 'Überschrift (zweite Spalte)', '', true);
$plain('lead', 'Einleitung', 'Einleitender Text in der Serifenschrift.', true);
$plain('summary', 'Kurzbeschreibung', 'Wird für Teaser, Suchergebnisse, SEO-Beschreibung und JSON-LD verwendet.', true);
$plain('metaLabel', 'Meta-Label', 'Kleine Zusatzzeile, z. B. „IN FOCUS / KENYA“ oder eine Bildquelle.');
$plain('note', 'Hinweis / Fußnote', '', true);
$plain('quote', 'Zitat', '', true);
$plain('authorName', 'Autor:in');
$plain('period', 'Zeitraum', 'z. B. „2024–2027“');
$plain('anchorLabel', 'Sprungmarke', 'Wenn ausgefüllt, erscheint dieses Modul in der Seiten-Navigation (z. B. „Our approach“).');
$plain('caption', 'Bildunterschrift');
$plain('topbarText', 'Text in der oberen Leiste');
$plain('footerClaim', 'Claim im Footer', '', true);
$plain('contactEmail', 'Kontakt-E-Mail');

// Organisation (JSON-LD)
$plain('orgName', 'Name', 'Kurzname, z. B. „GfT“');
$plain('orgLegalName', 'Rechtlicher Name', 'z. B. „Gesellschaft für Testzwecke mbH“');
$plain('orgAlternateName', 'Alternativer Name');
$plain('orgDescription', 'Beschreibung', 'Ein bis drei Sätze: Wer ist die Organisation, was tut sie? Wird von Suchmaschinen und KI-Systemen gelesen.', true);
$plain('orgFoundingDate', 'Gründungsjahr', 'z. B. „1975“');
$plain('orgStreet', 'Straße & Hausnummer');
$plain('orgPostalCode', 'PLZ');
$plain('orgLocality', 'Ort');
$plain('orgCountry', 'Land (ISO-Code)', 'z. B. „DE“');
$plain('orgEmail', 'E-Mail');
$plain('orgPhone', 'Telefon');
$plain('orgVatId', 'USt-IdNr.');
$plain('orgRegister', 'Handelsregister');

field(Number::class, 'readTime', 'Lesezeit (Minuten)', $N + ['min' => 0]);
field(Number::class, 'teaserLimit', 'Anzahl', $N + ['min' => 0, 'instructions' => 'Wie viele Einträge automatisch gezeigt werden (0 = alle).']);

field(Ckeditor::class, 'text', 'Text', $T + [
    'toolbar' => ['heading', '|', 'bold', 'italic', 'link', '|', 'bulletedList', 'numberedList', 'blockQuote', '|', 'sourceEditing'],
    'headingLevels' => [2, 3, 4],
]);

field(Lightswitch::class, 'numbered', 'Nummeriert', $N + ['instructions' => 'Karten mit 01, 02, 03 … nummerieren.']);
field(Lightswitch::class, 'showAkzenteLogo', 'Akzente-Logo zeigen', $N);
field(Date::class, 'deadline', 'Abgabefrist', $N + ['showTime' => true, 'showDate' => true]);

$opts = function (array $pairs) {
    $o = [];
    $first = true;
    foreach ($pairs as $v => $l) {
        $o[] = ['label' => $l, 'value' => $v, 'default' => $first];
        $first = false;
    }
    return $o;
};
field(Dropdown::class, 'background', 'Hintergrund', $N + ['options' => $opts(['white' => 'Weiß', 'subtle' => 'Hellgrau', 'tinted' => 'Hellblau', 'dark' => 'Dunkelblau', 'blue' => 'Markenblau'])]);
field(Dropdown::class, 'teaserSource', 'Quelle der Einträge', $N + ['options' => $opts([
    'manual' => 'Manuell ausgewählt',
    'projects' => 'Neueste Projekte',
    'news' => 'Neueste News',
    'akzente' => 'Neueste Akzente-Geschichten',
    'services' => 'Alle Leistungen',
    'related' => 'Ähnliche Einträge (gleiches Land/Thema)',
])]);
field(Dropdown::class, 'teaserLayout', 'Darstellung', $N + ['options' => $opts([
    'cards' => 'Bild-Karten (3 Spalten)',
    'text' => 'Text-Karten (3 Spalten)',
    'media' => 'Bild links, Text rechts (2 Spalten)',
    'list' => 'Liste',
])]);
field(Dropdown::class, 'imagePosition', 'Bildposition', $N + ['options' => $opts(['left' => 'Links', 'right' => 'Rechts'])]);
field(Dropdown::class, 'imageWidth', 'Bildbreite', $N + ['options' => $opts(['wide' => 'Breit (Inhaltsbreite)', 'full' => 'Volle Breite', 'content' => 'Textspalte'])]);
field(Dropdown::class, 'projectStatus', 'Projektstatus', $N + ['options' => $opts(['ongoing' => 'Laufend', 'completed' => 'Abgeschlossen', 'planned' => 'Geplant'])]);
field(Dropdown::class, 'tenderType', 'Art der Ausschreibung', $N + ['options' => $opts(['services' => 'Dienstleistungen', 'supplies' => 'Lieferungen', 'works' => 'Bauleistungen'])]);
field(Dropdown::class, 'tenderStatus', 'Status', $N + ['options' => $opts(['open' => 'Offen', 'closed' => 'Geschlossen', 'awarded' => 'Vergeben'])]);
field(Dropdown::class, 'serviceIcon', 'Icon', $N + ['options' => $opts(['leaf' => 'Pflanze / Klima', 'handshake' => 'Handschlag / Wirtschaft', 'institution' => 'Institution / Governance', 'network' => 'Netzwerk / Digital', 'globe' => 'Globus', 'chart' => 'Diagramm'])]);
field(Dropdown::class, 'noticeStyle', 'Stil', $N + ['options' => $opts(['inline' => 'Kleine Zeile mit Markierung', 'box' => 'Hervorgehobener Kasten', 'end' => 'Abschluss-Hinweis (Artikelende)'])]);

$col = fn(string $heading, string $handle, string $type = 'singleline') => ['heading' => $heading, 'handle' => $handle, 'type' => $type];
$table = fn(string $h, string $n, array $cols, string $instr = '') => field(Table::class, $h, $n, $T + [
    'columns' => array_combine(array_map(fn($i) => "col$i", range(1, count($cols))), $cols),
    'instructions' => $instr,
    'defaults' => [],
]);
$table('metricItems', 'Kennzahlen', [$col('Wert', 'value'), $col('Bezeichnung', 'label'), $col('Erläuterung', 'note')]);
$table('cardItems', 'Karten', [$col('Titel', 'title'), $col('Text', 'text', 'multiline')]);
$table('factItems', 'Projektfakten', [$col('Bezeichnung', 'label'), $col('Wert', 'value'), $col('Erläuterung', 'note')]);
$table('audienceItems', 'Zielgruppen', [$col('Titel', 'title'), $col('Text', 'text'), $col('Link-Text', 'linkLabel'), $col('URL', 'url')], 'URL relativ (z. B. /kontakt) oder absolut. Externe Links bekommen automatisch einen Pfeil ↗.');
$table('popularSearches', 'Beliebte Suchbegriffe', [$col('Label', 'label'), $col('Suchbegriff', 'query')]);
$table('legalItems', 'Angaben', [$col('Bezeichnung', 'label'), $col('Inhalt', 'value', 'multiline')]);
$table('progressItems', 'Fortschrittsbalken', [$col('Bezeichnung', 'label'), $col('Wert', 'value', 'number'), $col('Maximum', 'max', 'number'), $col('Stil (actual/target)', 'style')]);
$table('allocationItems', 'Verteilung', [$col('Bezeichnung', 'label'), $col('Prozent', 'percent', 'number'), $col('Betrag', 'amount')]);
$table('faqItems', 'Fragen & Antworten', [$col('Frage', 'question'), $col('Antwort', 'answer', 'multiline')], 'Wird als FAQPage in JSON-LD ausgegeben.');
$table('groundingFacts', 'Fakten', [$col('Bezeichnung', 'label'), $col('Wert', 'value', 'multiline')], 'Kurze, eindeutige Fakten. Diese Seite ist die verlässliche Quelle für Suchmaschinen und KI-Systeme.');
$table('socialLinks', 'Social-Media-Links', [$col('Name', 'label'), $col('URL', 'url')]);
$table('orgSameAs', 'Profile (sameAs)', [$col('URL', 'url')], 'Offizielle Profile, z. B. LinkedIn, YouTube, Wikipedia, Wikidata.');
$table('orgKnowsAbout', 'Fachgebiete (knowsAbout)', [$col('Thema', 'topic')]);
$table('orgAreaServed', 'Tätigkeitsgebiete (areaServed)', [$col('Gebiet', 'area')]);
$table('contactPoints', 'Ansprechpunkte', [$col('Art', 'type'), $col('E-Mail', 'email'), $col('Telefon', 'phone'), $col('Sprachen', 'languages')]);

$linkCfg = $T + ['showLabelField' => true, 'types' => ['entry', 'url', 'email', 'asset'], 'typeSettings' => ['url' => ['allowRootRelativeUrls' => true, 'allowAnchors' => true]]];
field(Link::class, 'linkField', 'Link', $linkCfg);
field(Link::class, 'secondaryLink', 'Zweiter Link', $linkCfg);

// Volumes
$images = volume('images', 'Bilder', 'images');
$images->setFieldLayout(layout(Asset::class, ['Inhalt' => ['caption']], true, null, $images->getFieldLayout()));
$volSvc->saveVolume($images);
$documents = volume('documents', 'Dokumente', 'documents');
out('Volumes ok');

field(Assets::class, 'image', 'Bild', $N + ['sources' => ['volume:' . $images->uid], 'maxRelations' => 1, 'viewMode' => 'large', 'restrictFiles' => true, 'allowedKinds' => ['image'], 'defaultUploadLocationSource' => 'volume:' . $images->uid]);
field(Assets::class, 'images', 'Bilder', $N + ['sources' => ['volume:' . $images->uid], 'viewMode' => 'large', 'restrictFiles' => true, 'allowedKinds' => ['image'], 'defaultUploadLocationSource' => 'volume:' . $images->uid]);
field(Assets::class, 'downloads', 'Downloads', $N + ['sources' => ['volume:' . $documents->uid], 'viewMode' => 'list', 'defaultUploadLocationSource' => 'volume:' . $documents->uid]);

// Tags
$tagsSvc = Craft::$app->getTags();
$tg = $tagsSvc->getTagGroupByHandle('topics') ?? new TagGroup();
$tg->name = 'Themen';
$tg->handle = 'topics';
if (!$tg->id) {
    $tg->setFieldLayout(new FieldLayout(['type' => \craft\elements\Tag::class]));
}
$tagsSvc->saveTagGroup($tg);
field(Tags::class, 'topics', 'Schlagworte', $N + ['sources' => 'taggroup:' . $tg->uid, 'source' => 'taggroup:' . $tg->uid]);
out('Basisfelder ok');

// ---------------------------------------------------------------------------
// Sections (vorläufig ohne Felder, damit Relationsfelder sie referenzieren können)
// ---------------------------------------------------------------------------
$entriesSvc = Craft::$app->getEntries();

function section(string $handle, string $name, string $type, array $uris, ?string $template, array $entryTypes, array $extra = []): Section
{
    $svc = Craft::$app->getEntries();
    $s = $svc->getSectionByHandle($handle) ?? new Section();
    $s->handle = $handle;
    $s->name = $name;
    $s->type = $type;
    $s->propagationMethod = \craft\enums\PropagationMethod::All;
    foreach ($extra as $k => $v) $s->$k = $v;
    $settings = [];
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        $uri = $uris[$site->handle] ?? null;
        $settings[$site->id] = new Section_SiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => $uri !== null,
            'uriFormat' => $uri,
            'template' => $uri !== null ? $template : null,
        ]);
    }
    $s->setSiteSettings($settings);
    $s->setEntryTypes($entryTypes);
    if (!$svc->saveSection($s)) fail("Section $handle", $s);
    return $svc->getSectionByHandle($handle);
}

// Platzhalter nur beim ersten Lauf anlegen, sonst bestehendes Layout unangetastet lassen
$placeholder = fn(string $h, string $n) => Craft::$app->getEntries()->getEntryTypeByHandle($h) ?? entryType($h, $n, ['Inhalt' => []]);
$sec = [];
$sec['homepage'] = section('homepage', 'Startseite', Section::TYPE_SINGLE, ['de' => '__home__', 'en' => '__home__'], 'home/_entry', [$placeholder('home', 'Startseite')]);
$sec['pages'] = section('pages', 'Seiten', Section::TYPE_STRUCTURE, ['de' => '{parent.uri ?? null}{parent ? "/" : ""}{slug}', 'en' => '{parent.uri ?? null}{parent ? "/" : ""}{slug}'], 'pages/_entry', [$placeholder('page', 'Seite')], ['maxLevels' => 3]);
$sec['projects'] = section('projects', 'Projekte', Section::TYPE_CHANNEL, ['de' => 'projekte/{slug}', 'en' => 'projects/{slug}'], 'projects/_entry', [$placeholder('project', 'Projekt')]);
$sec['services'] = section('services', 'Leistungen', Section::TYPE_STRUCTURE, ['de' => 'leistungen/{slug}', 'en' => 'services/{slug}'], 'services/_entry', [$placeholder('service', 'Leistung')], ['maxLevels' => 2]);
$sec['worldwide'] = section('worldwide', 'Weltweit', Section::TYPE_STRUCTURE, ['de' => '{parent.uri ?? "weltweit"}/{slug}', 'en' => '{parent.uri ?? "worldwide"}/{slug}'], 'worldwide/_entry', [$placeholder('region', 'Region'), $placeholder('country', 'Land')], ['maxLevels' => 2]);
$sec['akzente'] = section('akzente', 'Akzente (Magazin)', Section::TYPE_CHANNEL, ['de' => 'akzente/{slug}', 'en' => 'akzente/{slug}'], 'akzente/_entry', [$placeholder('story', 'Geschichte')]);
$sec['news'] = section('news', 'News', Section::TYPE_CHANNEL, ['de' => 'aktuelles/{slug}', 'en' => 'news/{slug}'], 'news/_entry', [$placeholder('newsItem', 'Meldung')]);
$sec['tenders'] = section('tenders', 'Ausschreibungen', Section::TYPE_CHANNEL, ['de' => 'ausschreibungen/{slug}', 'en' => 'tenders/{slug}'], 'tenders/_entry', [$placeholder('tender', 'Ausschreibung')]);
$sec['teasers'] = section('teasers', 'Teaser', Section::TYPE_CHANNEL, [], null, [$placeholder('teaser', 'Teaser')]);
$sec['modules'] = section('modules', 'Wiederverwendbare Module', Section::TYPE_CHANNEL, [], null, [$placeholder('module', 'Modul')]);
$sec['grounding'] = section('grounding', 'Grounding (Fakten für KI)', Section::TYPE_SINGLE, ['de' => 'grounding', 'en' => 'grounding'], 'grounding/_entry', [$placeholder('grounding', 'Grounding')]);
out('Sections angelegt');

$src = fn(string ...$h) => array_map(fn($x) => 'section:' . $sec[$x]->uid, $h);
field(Entries::class, 'teaserEntries', 'Verknüpfte Einträge', $N + ['sources' => $src('projects', 'akzente', 'news', 'services', 'worldwide', 'pages', 'teasers', 'tenders'), 'viewMode' => 'cards', 'instructions' => 'Teaser werden automatisch aus Bild, Dachzeile, Titel und Kurzbeschreibung des Eintrags gebaut. Eigene Teaser legst du im Bereich „Teaser“ an und kannst sie überall wiederverwenden.']);
field(Entries::class, 'countries', 'Länder / Regionen', $N + ['sources' => $src('worldwide'), 'viewMode' => 'list']);
field(Entries::class, 'services', 'Themenfelder', $N + ['sources' => $src('services'), 'viewMode' => 'list']);
field(Entries::class, 'moduleEntry', 'Modul', $N + ['sources' => $src('modules'), 'maxRelations' => 1, 'viewMode' => 'cards', 'instructions' => 'Ein Modul aus „Wiederverwendbare Module“. Änderungen am Modul wirken überall, wo es eingebunden ist.']);
out('Relationsfelder ok');

// ---------------------------------------------------------------------------
// Navigation-Bausteine
// ---------------------------------------------------------------------------
$etNavLink = entryType('navLink', 'Link', ['Inhalt' => ['linkField']], ['hasTitleField' => false, 'uiLabelFormat' => '{{ object.linkField ? (object.linkField.label ?: object.linkField.url) : "" }}', 'icon' => 'link']);
$mat = fn(string $h, string $n, array $types, array $cfg = []) => field(Matrix::class, $h, $n, $N + ['entryTypes' => $types, 'viewMode' => 'blocks', 'propagationMethod' => \craft\enums\PropagationMethod::All] + $cfg);
$mat('navLinks', 'Links', [$etNavLink], ['createButtonLabel' => 'Link hinzufügen']);
$etNavItem = entryType('navItem', 'Menüpunkt', ['Inhalt' => ['linkField', ['navLinks', ['label' => 'Untermenü']]]], ['hasTitleField' => false, 'uiLabelFormat' => '{{ object.linkField ? object.linkField.label : "" }}', 'icon' => 'bars']);
$etFooterCol = entryType('footerColumn', 'Footer-Spalte', ['Inhalt' => ['headline', 'navLinks']], ['hasTitleField' => false, 'uiLabelFormat' => '{headline}', 'icon' => 'table-columns']);
$mat('mainNav', 'Hauptnavigation', [$etNavItem], ['createButtonLabel' => 'Menüpunkt hinzufügen']);
$mat('utilityNav', 'Servicenavigation (oben)', [$etNavLink], ['createButtonLabel' => 'Link hinzufügen']);
$mat('footerColumns', 'Footer-Spalten', [$etFooterCol], ['createButtonLabel' => 'Spalte hinzufügen']);
$mat('legalNav', 'Rechtliche Links (Footer unten)', [$etNavLink], ['createButtonLabel' => 'Link hinzufügen']);

// ---------------------------------------------------------------------------
// Module (Matrix-Blöcke) für den Seitenbaukasten
// ---------------------------------------------------------------------------
$noTitle = ['hasTitleField' => false];
$b = [];
$b['heroSlide'] = entryType('heroSlide', 'Slide', ['Inhalt' => ['image', 'eyebrow', 'headline', 'lead', 'linkField', ['metaLabel', ['label' => 'Fokus-Label']], ['secondaryLink', ['label' => 'Fokus-Link']]]], $noTitle + ['uiLabelFormat' => '{headline}', 'icon' => 'image']);
$mat('slides', 'Slides', [$b['heroSlide']], ['createButtonLabel' => 'Slide hinzufügen']);
$b['progressPanel'] = entryType('progressPanel', 'Fortschritt', ['Inhalt' => ['eyebrow', 'headline', 'lead', 'progressItems', 'note']], $noTitle + ['uiLabelFormat' => '{headline}', 'icon' => 'bars-progress']);
$b['allocationPanel'] = entryType('allocationPanel', 'Verteilung', ['Inhalt' => ['eyebrow', 'headline', 'allocationItems', 'note']], $noTitle + ['uiLabelFormat' => '{headline}', 'icon' => 'chart-pie']);
$mat('panels', 'Panels', [$b['progressPanel'], $b['allocationPanel']], ['createButtonLabel' => 'Panel hinzufügen']);

$mod = fn(string $h, string $n, array $fields, string $icon, string $desc = '') =>
    entryType($h, $n, ['Inhalt' => $fields], $noTitle + ['uiLabelFormat' => '{{ object.headline ?: object.eyebrow }}', 'icon' => $icon, 'description' => $desc]);

$b['heroStage'] = $mod('heroStage', 'Bühne (Hero-Slider)', ['slides'], 'images', 'Große Bühne mit einem oder mehreren Slides.');
$b['pageIntro'] = $mod('pageIntro', 'Seiteneinstieg', ['showAkzenteLogo', 'eyebrow', 'headline', 'lead', 'linkField', 'image'], 'heading', 'Große Überschrift mit Einleitung und optionalem Bild.');
$b['searchBar'] = $mod('searchBar', 'Suche', ['headline', 'lead', 'popularSearches'], 'magnifying-glass');
$b['metrics'] = $mod('metrics', 'Kennzahlen', ['eyebrow', 'headline', 'lead', 'text', 'linkField', 'metricItems', 'note', 'background', 'anchorLabel'], 'chart-simple');
$b['teaserGrid'] = $mod('teaserGrid', 'Teaser-Liste', ['eyebrow', 'headline', 'linkField', 'teaserSource', 'teaserEntries', 'teaserLimit', 'teaserLayout', 'note', 'background', 'anchorLabel'], 'grip', 'Zeigt Projekte, Geschichten, News oder eigene Teaser – manuell oder automatisch.');
$b['regionExplorer'] = $mod('regionExplorer', 'Regionen-Übersicht', ['eyebrow', 'headline', 'lead', 'linkField', 'metaLabel', 'note'], 'earth-europe', 'Listet automatisch alle Regionen aus „Weltweit“.');
$b['serviceGrid'] = $mod('serviceGrid', 'Leistungen', ['eyebrow', 'headline', 'lead', 'linkField', ['teaserEntries', ['label' => 'Leistungen (leer = alle)']]], 'briefcase');
$b['featureSplit'] = $mod('featureSplit', 'Bild & Text (geteilt)', ['showAkzenteLogo', 'image', 'metaLabel', 'eyebrow', 'headline', 'quote', 'lead', 'linkField', 'secondaryLink', ['teaserEntries', ['label' => 'Zusätzlicher Hinweis-Link']], 'note', 'background', 'imagePosition', 'anchorLabel'], 'table-columns', 'Bild auf einer Seite, farbiges Textfeld auf der anderen. Mit Zitat wird es zum Zitat-Panel.');
$b['tenderList'] = $mod('tenderList', 'Ausschreibungen', ['eyebrow', 'headline', 'lead', 'linkField', 'teaserLimit', 'note'], 'file-contract', 'Zeigt automatisch offene Ausschreibungen.');
$b['ctaAudience'] = $mod('ctaAudience', 'Call to Action mit Zielgruppen', ['eyebrow', 'headline', 'lead', 'linkField', 'audienceItems', 'background'], 'bullhorn');
$b['textSplit'] = $mod('textSplit', 'Text (zweispaltig)', ['eyebrow', 'headline', 'lead', 'text', 'linkField', 'secondaryLink', 'background', 'anchorLabel'], 'align-left', 'Überschrift links, Text rechts.');
$b['cardRow'] = $mod('cardRow', 'Karten / Prinzipien / Schritte', ['eyebrow', 'headline', 'lead', 'text', 'metaLabel', 'cardItems', 'numbered', 'note', 'background', 'anchorLabel'], 'grip-lines');
$b['articleText'] = $mod('articleText', 'Fließtext (Artikel)', ['eyebrow', 'headline', 'text', ['metaLabel', ['label' => 'Randnotiz: Titel']], ['note', ['label' => 'Randnotiz: Text']]], 'paragraph', 'Text in der schmalen Lesespalte.');
$b['pullQuote'] = $mod('pullQuote', 'Zitat', ['quote', 'note'], 'quote-left');
$b['imageBlock'] = $mod('imageBlock', 'Bild(er)', ['images', 'imageWidth'], 'image', 'Ein Bild oder zwei Bilder nebeneinander, mit Bildunterschrift.');
$b['gallery'] = $mod('gallery', 'Bildergalerie', ['eyebrow', 'headline', 'metaLabel', 'images', 'anchorLabel'], 'images');
$b['dataPanels'] = $mod('dataPanels', 'Daten-Panels', ['panels', 'note'], 'chart-column', 'Fortschrittsbalken und Budgetverteilung.');
$b['downloadsContact'] = $mod('downloadsContact', 'Downloads & Kontakt', ['eyebrow', 'headline', 'downloads', ['metaLabel', ['label' => 'Kontakt: Dachzeile']], ['headline2', ['label' => 'Kontakt: Überschrift']], ['lead', ['label' => 'Kontakt: Text']], 'linkField', 'note', 'anchorLabel'], 'download');
$b['contactForm'] = $mod('contactForm', 'Kontaktformular', ['eyebrow', 'headline', 'lead', 'contactEmail', 'linkField', 'secondaryLink', 'note'], 'envelope', 'Statisches Formular (Konzept) – Versand ist noch nicht angebunden.');
$b['newsletterCta'] = $mod('newsletterCta', 'Newsletter', ['showAkzenteLogo', 'headline', 'lead', 'linkField', 'note'], 'newspaper');
$b['legalDetails'] = $mod('legalDetails', 'Rechtliche Angaben', ['eyebrow', 'lead', 'legalItems', ['teaserEntries', ['label' => 'Auch auf dieser Website']], 'note'], 'scale-balanced');
$b['notice'] = $mod('notice', 'Hinweis', ['note', 'noticeStyle', 'headline'], 'circle-info');
$b['notice']->uiLabelFormat = '{{ object.headline ?: (object.note|slice(0, 70)) }}';
Craft::$app->getEntries()->saveEntryType($b['notice']);
$b['reusableModule'] = entryType('reusableModule', 'Wiederverwendbares Modul', ['Inhalt' => ['moduleEntry']], $noTitle + ['uiLabelFormat' => '{% set m = object.moduleEntry.one() %}{{ m ? m.title : "Modul" }}', 'icon' => 'recycle', 'description' => 'Bindet ein zentral gepflegtes Modul ein.']);

$builderTypes = array_values(array_diff_key($b, array_flip(['heroSlide', 'progressPanel', 'allocationPanel'])));
$mat('builder', 'Seiteninhalte', $builderTypes, ['createButtonLabel' => 'Modul hinzufügen', 'instructions' => 'Baue die Seite aus Modulen zusammen.']);
out('Module ok');

// ---------------------------------------------------------------------------
// Entry-Types der Sections mit echten Feld-Layouts
// ---------------------------------------------------------------------------
$seo = ['summary', ['image', ['label' => 'Teaser- & Social-Bild']]];
entryType('home', 'Startseite', ['Inhalt' => ['builder'], 'Teaser & SEO' => $seo], ['icon' => 'house']);
entryType('page', 'Seite', ['Inhalt' => ['builder'], 'Teaser & SEO' => array_merge(['eyebrow'], $seo)], ['icon' => 'file']);
entryType('project', 'Projekt', [
    'Kopf' => ['eyebrow', ['summary', ['label' => 'Einleitung / Kurzbeschreibung']], 'image', 'topics'],
    'Fakten' => ['countries', 'services', 'projectStatus', 'period', 'factItems'],
    'Inhalt' => ['builder'],
], ['icon' => 'diagram-project']);
entryType('service', 'Leistung', ['Kopf' => ['serviceIcon', 'eyebrow', 'summary', 'image'], 'Inhalt' => ['builder']], ['icon' => 'briefcase']);
entryType('region', 'Region', ['Kopf' => ['summary', 'image'], 'Inhalt' => ['builder']], ['icon' => 'earth-africa']);
entryType('country', 'Land', ['Kopf' => ['summary', 'image'], 'Inhalt' => ['builder']], ['icon' => 'flag']);
entryType('story', 'Geschichte', [
    'Kopf' => ['eyebrow', ['summary', ['label' => 'Vorspann']], 'authorName', 'readTime', 'image'],
    'Titelbild-Panel' => [['metaLabel', ['label' => 'Panel: Dachzeile']], ['quote', ['label' => 'Panel: Text']], ['note', ['label' => 'Panel: Untertext']], ['caption', ['label' => 'Bildunterschrift (überschreibt die des Bildes)']]],
    'Inhalt' => ['builder'],
    'Einordnung' => ['countries', 'services', 'topics'],
], ['icon' => 'book-open']);
entryType('newsItem', 'Meldung', ['Kopf' => ['eyebrow', 'summary', 'image'], 'Inhalt' => ['builder'], 'Einordnung' => ['countries', 'topics']], ['icon' => 'newspaper']);
entryType('tender', 'Ausschreibung', ['Inhalt' => ['tenderType', 'tenderStatus', 'deadline', 'countries', 'summary', ['linkField', ['label' => 'Link zum Vergabeportal']]]], ['icon' => 'file-contract']);
entryType('teaser', 'Teaser', ['Inhalt' => ['image', 'eyebrow', 'summary', ['linkField', ['label' => 'Ziel']]]], ['icon' => 'rectangle-list', 'description' => 'Freier Teaser, der in Teaser-Listen wiederverwendet werden kann.']);
entryType('module', 'Modul', ['Inhalt' => ['builder']], ['icon' => 'recycle', 'description' => 'Ein zentral gepflegter Inhaltsbaustein.']);
entryType('grounding', 'Grounding', ['Inhalt' => ['lead', 'groundingFacts', 'faqItems', 'text']], ['icon' => 'robot']);
out('Entry-Types ok');

// ---------------------------------------------------------------------------
// Globals
// ---------------------------------------------------------------------------
function globalSet(string $handle, string $name, array $tabs): void
{
    $svc = Craft::$app->getGlobals();
    $set = $svc->getSetByHandle($handle) ?? new GlobalSet();
    $set->handle = $handle;
    $set->name = $name;
    $layout = layout(GlobalSet::class, $tabs, false, null, $set->id ? $set->getFieldLayout() : null);
    $set->setFieldLayout($layout);
    if (!$svc->saveSet($set)) fail("Global $handle", $set);
}
globalSet('siteSettings', 'Website', ['Allgemein' => ['topbarText', 'footerClaim', 'contactEmail', 'socialLinks']]);
globalSet('navigation', 'Navigation', ['Header' => ['utilityNav', 'mainNav'], 'Footer' => ['footerColumns', 'legalNav']]);
globalSet('organization', 'Organisation (JSON-LD)', [
    'Organisation' => ['orgName', 'orgLegalName', 'orgAlternateName', 'orgDescription', ['image', ['label' => 'Logo']], 'orgFoundingDate'],
    'Kontakt & Adresse' => ['orgStreet', 'orgPostalCode', 'orgLocality', 'orgCountry', 'orgEmail', 'orgPhone', 'contactPoints'],
    'Rechtliches' => ['orgVatId', 'orgRegister'],
    'Profile & Themen' => ['orgSameAs', 'orgKnowsAbout', 'orgAreaServed'],
]);
out('Globals ok');
$pc = Craft::$app->getProjectConfig();
$pc->saveModifiedConfigData();
$pc->writeYamlFiles(true);
out('Fertig.');
