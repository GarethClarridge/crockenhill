<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'sermons_preacher_id_foreign';

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // The formalize_sermon_identity_authority migration used MODIFY COLUMN which
        // silently dropped the original ON DELETE SET NULL foreign key. Restore it.
        DB::statement(sprintf(
            'ALTER TABLE sermons ADD CONSTRAINT %s FOREIGN KEY (preacher_id) REFERENCES preachers (id) ON DELETE SET NULL',
            self::CONSTRAINT
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf('ALTER TABLE sermons DROP FOREIGN KEY %s', self::CONSTRAINT));
    }
};
