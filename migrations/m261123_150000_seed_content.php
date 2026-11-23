<?php

namespace craft\contentmigrations;

use craft\db\Migration;

/**
 * Legt die Dummy-Inhalte (DE/EN) an. Läuft bei `craft up` automatisch einmal.
 * Vorhandene Einträge werden nicht überschrieben.
 */
class m261123_150000_seed_content extends Migration
{
    public function safeUp(): bool
    {
        require_once dirname(__DIR__) . '/seed/Seeder.php';
        (new \seed\Seeder(fn(string $m) => print("    > $m\n")))->run();
        return true;
    }

    public function safeDown(): bool
    {
        echo "m261123_150000_seed_content kann nicht rückgängig gemacht werden.\n";
        return false;
    }
}
