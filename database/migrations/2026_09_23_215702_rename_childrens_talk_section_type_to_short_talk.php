<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Detection stops judging audience: the `childrens_talk` section type becomes
 * `short_talk`, and what kind of talk it is becomes a proposal on the section
 * (see the following migration). Talks plan §4.5 step 2.
 */
return new class extends Migration
{
    private const TYPES_BEFORE = "'welcome','prayer','notices','song','childrens_talk','bible_reading','sermon','other'";

    private const TYPES_WIDE = "'welcome','prayer','notices','song','childrens_talk','short_talk','bible_reading','sermon','other'";

    private const TYPES_AFTER = "'welcome','prayer','notices','song','short_talk','bible_reading','sermon','other'";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->modifyColumns(self::TYPES_WIDE);
        }

        $this->retype('childrens_talk', 'short_talk');

        if (DB::getDriverName() === 'mysql') {
            $this->modifyColumns(self::TYPES_AFTER);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->modifyColumns(self::TYPES_WIDE);
        }

        $this->retype('short_talk', 'childrens_talk');

        if (DB::getDriverName() === 'mysql') {
            $this->modifyColumns(self::TYPES_BEFORE);
        }
    }

    private function retype(string $from, string $to): void
    {
        DB::table('service_sections')->where('section_type', $from)->update(['section_type' => $to]);
        DB::table('church_service_items')->where('section_type', $from)->update(['section_type' => $to]);
        DB::table('church_service_item_assertions')->where('section_type', $from)->update(['section_type' => $to]);
    }

    private function modifyColumns(string $types): void
    {
        DB::statement("ALTER TABLE service_sections MODIFY COLUMN section_type ENUM({$types}) NOT NULL");
        DB::statement("ALTER TABLE church_service_items MODIFY COLUMN section_type ENUM({$types}) NULL DEFAULT NULL");
    }
};
