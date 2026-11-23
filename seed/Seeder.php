<?php
/**
 * Legt die Dummy-Inhalte (DE/EN) aus seed/content.php an.
 *
 * Wird von der Content-Migration migrations/m261123_150000_seed_content.php aufgerufen.
 * Bereits vorhandene Einträge (gleiche Section + gleicher deutscher Slug) werden übersprungen,
 * damit redaktionelle Änderungen nie überschrieben werden.
 */

namespace seed;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\fields\Matrix;
use craft\fields\Table;
use craft\helpers\FileHelper;
use RuntimeException;

class Seeder
{
    private array $sites = [];
    private array $refs = [];
    private array $assets = [];
    private array $tags = [];
    /** @var callable */
    private $log;

    public function __construct(?callable $log = null)
    {
        $this->log = $log ?? fn(string $m) => null;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $this->sites[$site->handle] = $site->id;
        }
    }

    public function run(): void
    {
        $data = require __DIR__ . '/content.php';

        foreach ($data['assets'] as $key => $def) {
            $this->asset($key, $def);
        }
        ($this->log)('Bilder & Dokumente ok');

        // Zuerst alle Einträge ohne Inhalte anlegen, damit Verweise aufgelöst werden können
        foreach ($data['entries'] as $key => $def) {
            $this->createShell($key, $def);
        }
        ($this->log)('Einträge angelegt');

        foreach ($data['entries'] as $key => $def) {
            if (!($this->refs[$key]['new'] ?? false)) {
                continue;
            }
            foreach (['de', 'en'] as $site) {
                $this->fillEntry($key, $def, $site);
            }
        }
        ($this->log)('Inhalte gefüllt');

        foreach ($data['globals'] as $handle => $fields) {
            foreach (['de', 'en'] as $site) {
                $set = GlobalSet::find()->handle($handle)->siteId($this->sites[$site])->one();
                if (!$set) {
                    throw new RuntimeException("Global-Set $handle fehlt");
                }
                if ($this->globalHasContent($set, $fields) && $site === 'de') {
                    ($this->log)("Global $handle hat schon Inhalte – übersprungen");
                    continue 2;
                }
                $this->applyFields($set, $fields, $site);
                if (!Craft::$app->getElements()->saveElement($set)) {
                    throw new RuntimeException("Global $handle: " . json_encode($set->getErrors()));
                }
            }
        }
        ($this->log)('Globals ok');
    }

    private function globalHasContent(GlobalSet $set, array $fields): bool
    {
        foreach (array_keys($fields) as $handle) {
            $v = $set->getFieldValue($handle);
            if ($v instanceof \craft\elements\db\ElementQueryInterface) {
                if ((clone $v)->status(null)->exists()) return true;
            } elseif (is_array($v)) {
                // Tabellen liefern eine leere Standardzeile – nur echte Werte zählen
                $flat = [];
                array_walk_recursive($v, function ($x) use (&$flat) { if ($x !== null && $x !== '') $flat[] = $x; });
                if ($flat) return true;
            } elseif ((string)$v !== '') {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------

    public static function isT(mixed $v): bool
    {
        return is_array($v) && count($v) === 2 && array_key_exists('de', $v) && array_key_exists('en', $v);
    }

    private function t(mixed $v, string $site): mixed
    {
        return self::isT($v) ? $v[$site] : $v;
    }

    private function asset(string $key, array $def): void
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($def['volume']);
        $filename = basename($def['file']);
        $existing = Asset::find()->volumeId($volume->id)->filename($filename)->one();
        if ($existing) {
            if (!Asset::find()->id($existing->id)->siteId($this->sites['en'])->status(null)->exists()) {
                $this->translateAsset($existing->id, $def);
            }
            $this->assets[$key] = $existing->id;
            return;
        }
        $src = dirname(__DIR__) . '/' . $def['file'];
        $tmp = Craft::$app->getPath()->getTempPath() . '/' . uniqid() . '-' . $filename;
        copy($src, $tmp);
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
        $asset = new Asset();
        $asset->tempFilePath = $tmp;
        $asset->filename = $filename;
        $asset->newFolderId = $folder->id;
        $asset->volumeId = $volume->id;
        $asset->avoidFilenameConflicts = true;
        $asset->title = $this->t($def['title'], 'de');
        $asset->alt = $this->t($def['alt'] ?? null, 'de');
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $asset->siteId = $this->sites['de'];
        if (isset($def['caption'])) {
            $asset->setFieldValue('caption', $this->t($def['caption'], 'de'));
        }
        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new RuntimeException("Asset $key: " . json_encode($asset->getErrors()));
        }
        $this->translateAsset($asset->id, $def);
        $this->assets[$key] = $asset->id;
    }

    /** Stellt sicher, dass das Asset in allen Sites existiert, und setzt die englischen Texte. */
    public function translateAsset(int $id, array $def): void
    {
        $en = Asset::find()->id($id)->siteId($this->sites['en'])->status(null)->one();
        if (!$en) {
            $de = Asset::find()->id($id)->siteId($this->sites['de'])->status(null)->one();
            Craft::$app->getElements()->propagateElement($de, $this->sites['en']);
            $en = Asset::find()->id($id)->siteId($this->sites['en'])->status(null)->one();
        }
        if ($en) {
            $en->title = $this->t($def['title'], 'en');
            $en->alt = $this->t($def['alt'] ?? null, 'en');
            if (isset($def['caption'])) {
                $en->setFieldValue('caption', $this->t($def['caption'], 'en'));
            }
            Craft::$app->getElements()->saveElement($en);
        }
    }

    private function createShell(string $key, array $def): void
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($def['section']);
        $slugDe = $this->t($def['slug'] ?? null, 'de');
        if ($section->type === 'single') {
            $entry = Entry::find()->sectionId($section->id)->siteId($this->sites['de'])->status(null)->one();
            // Singles existieren immer; „neu“ heißt hier: noch nie befüllt
            $isNew = $entry && $this->singleIsEmpty($entry);
        } else {
            $entry = $slugDe ? Entry::find()->sectionId($section->id)->slug($slugDe)->siteId($this->sites['de'])->status(null)->one() : null;
            $isNew = !$entry;
        }
        if (!$entry) {
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->siteId = $this->sites['de'];
            $types = $section->getEntryTypes();
            $type = $types[0];
            foreach ($types as $et) {
                if ($et->handle === ($def['type'] ?? null)) $type = $et;
            }
            $entry->typeId = $type->id;
            $entry->title = $this->t($def['title'], 'de');
            if ($slugDe) $entry->slug = $slugDe;
            if (isset($def['postDate'])) $entry->postDate = new \DateTime($def['postDate']);
            if (isset($def['parent'])) {
                $entry->setParentId($this->refs[$def['parent']]['id'] ?? null);
            }
            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new RuntimeException("Eintrag $key: " . json_encode($entry->getErrors()));
            }
        }
        $this->refs[$key] = ['id' => $entry->id, 'new' => $isNew];
    }

    private function singleIsEmpty(Entry $entry): bool
    {
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $f) {
            $v = $entry->getFieldValue($f->handle);
            if ($v instanceof \craft\elements\db\ElementQueryInterface) {
                if ($v->status(null)->exists()) return false;
            } elseif (!empty($v)) {
                return false;
            }
        }
        return true;
    }

    private function fillEntry(string $key, array $def, string $site): void
    {
        $entry = Entry::find()->id($this->refs[$key]['id'])->siteId($this->sites[$site])->status(null)->one();
        if (!$entry) {
            throw new RuntimeException("Eintrag $key fehlt in Site $site");
        }
        $entry->title = $this->t($def['title'], $site);
        if (isset($def['slug'])) $entry->slug = $this->t($def['slug'], $site);
        $this->applyFields($entry, $def['fields'] ?? [], $site);
        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException("Eintrag $key ($site): " . json_encode($entry->getErrors()));
        }
    }

    private function applyFields($element, array $fields, string $site): void
    {
        foreach ($fields as $handle => $value) {
            $field = $element->getFieldLayout()?->getFieldByHandle($handle);
            if (!$field) {
                throw new RuntimeException("Feld $handle ist nicht im Layout von " . get_class($element));
            }
            $element->setFieldValue($handle, $this->value($field, $value, $site, $element->id));
        }
    }

    private function value($field, mixed $value, string $site, ?int $ownerId): mixed
    {
        if ($field instanceof Matrix) {
            return $this->matrix($field, $value, $site, $ownerId);
        }
        if ($field instanceof Table) {
            $map = [];
            foreach ($field->columns as $colId => $col) $map[$col['handle']] = $colId;
            $rows = [];
            foreach ($this->t($value, $site) as $row) {
                $r = [];
                foreach ($row as $h => $cell) {
                    $r[$map[$h] ?? $h] = $this->t($cell, $site);
                }
                $rows[] = $r;
            }
            return $rows;
        }
        if ($field instanceof \craft\fields\Link) {
            if (!$value) return null;
            $label = $this->t($value['label'] ?? null, $site);
            if (isset($value['entry'])) {
                $id = $this->refs[$value['entry']]['id'] ?? throw new RuntimeException("Unbekannter Verweis {$value['entry']}");
                return ['type' => 'entry', 'value' => sprintf('{entry:%s@%s:url}', $id, $this->sites[$site]), 'label' => $label];
            }
            if (isset($value['asset'])) {
                return ['type' => 'asset', 'value' => sprintf('{asset:%s@%s:url}', $this->assets[$value['asset']], $this->sites[$site]), 'label' => $label];
            }
            if (isset($value['email'])) {
                return ['type' => 'email', 'value' => $value['email'], 'label' => $label];
            }
            return ['type' => 'url', 'value' => $this->t($value['url'], $site), 'label' => $label];
        }
        if ($field instanceof \craft\fields\Assets) {
            return array_map(fn($k) => $this->assets[$k] ?? throw new RuntimeException("Unbekanntes Bild $k"), (array)$value);
        }
        if ($field instanceof \craft\fields\Tags) {
            return array_map(fn($t) => $this->tag($t), $value);
        }
        if ($field instanceof \craft\fields\Entries) {
            return array_map(fn($k) => $this->refs[$k]['id'] ?? throw new RuntimeException("Unbekannter Verweis $k"), (array)$value);
        }
        if ($field instanceof \craft\fields\Date) {
            return $value ? new \DateTime($value) : null;
        }
        return $this->t($value, $site);
    }

    private function tag(array|string $name): int
    {
        $de = $this->t($name, 'de');
        if (isset($this->tags[$de])) return $this->tags[$de];
        $group = Craft::$app->getTags()->getTagGroupByHandle('topics');
        $tag = Tag::find()->groupId($group->id)->title($de)->siteId($this->sites['de'])->one();
        if (!$tag) {
            $tag = new Tag(['groupId' => $group->id, 'title' => $de, 'siteId' => $this->sites['de']]);
            Craft::$app->getElements()->saveElement($tag);
            $en = Tag::find()->id($tag->id)->siteId($this->sites['en'])->one();
            if ($en) {
                $en->title = $this->t($name, 'en');
                Craft::$app->getElements()->saveElement($en);
            }
        }
        return $this->tags[$de] = $tag->id;
    }

    /**
     * Matrix-Wert im Formular-Format. Für die zweite Sprache werden die bereits
     * vorhandenen (propagierten) Blöcke per ID adressiert und nur übersetzt.
     */
    private function matrix(Matrix $field, array $blocks, string $site, ?int $ownerId): array
    {
        $existing = [];
        if ($ownerId) {
            $existing = Entry::find()
                ->ownerId($ownerId)
                ->fieldId($field->id)
                ->siteId($this->sites[$site])
                ->status(null)
                ->orderBy(['elements_owners.sortOrder' => SORT_ASC])
                ->all();
        }
        $types = [];
        foreach ($field->getEntryTypes() as $et) $types[$et->handle] = $et;

        $entries = [];
        $order = [];
        foreach (array_values($blocks) as $i => $block) {
            $typeHandle = $block['type'];
            $type = $types[$typeHandle] ?? throw new RuntimeException("Block-Typ $typeHandle gibt es im Feld {$field->handle} nicht");
            $existingBlock = $existing[$i] ?? null;
            $id = $existingBlock ? (string)$existingBlock->id : 'new' . ($i + 1);
            $layout = $type->getFieldLayout();
            $fields = [];
            foreach ($block as $h => $v) {
                if ($h === 'type') continue;
                $f = $layout->getFieldByHandle($h) ?? throw new RuntimeException("Feld $h fehlt im Block-Typ $typeHandle");
                $fields[$h] = $this->value($f, $v, $site, $existingBlock?->id);
            }
            $entries[$id] = ['type' => $typeHandle, 'enabled' => true, 'fields' => $fields];
            $order[] = $id;
        }
        return ['entries' => $entries, 'sortOrder' => $order];
    }
}
