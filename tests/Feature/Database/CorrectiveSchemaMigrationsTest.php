<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CorrectiveSchemaMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function meetingsLocationMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_02_26_221409_make_meetings_location_nullable_if_required.php');

        return $migration;
    }

    private function passwordResetMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_02_26_221409_normalize_password_reset_tokens_email_key.php');

        return $migration;
    }

    private function isMySql(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    private function isColumnNullable(string $table, string $column): bool
    {
        $nullable = DB::table('information_schema.columns')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('is_nullable');

        return $nullable === 'YES';
    }

    private function hasPrimaryKeyOnEmail(): bool
    {
        return DB::table('information_schema.key_column_usage')
            ->whereRaw('table_schema = database()')
            ->where('table_name', 'password_reset_tokens')
            ->where('column_name', 'email')
            ->where('constraint_name', 'PRIMARY')
            ->exists();
    }

    #[Test]
    public function it_makes_meetings_location_nullable_when_schema_has_drifted(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('This drift simulation requires MySQL.');
        }

        DB::statement('ALTER TABLE meetings MODIFY location VARCHAR(75) NOT NULL');
        $this->assertFalse($this->isColumnNullable('meetings', 'location'));

        $this->meetingsLocationMigration()->up();

        $this->assertTrue($this->isColumnNullable('meetings', 'location'));
    }

    #[Test]
    public function it_normalizes_password_reset_tokens_email_key_when_schema_has_drifted(): void
    {
        if (! $this->isMySql()) {
            $this->markTestSkipped('This drift simulation requires MySQL.');
        }

        Schema::drop('password_reset_tokens');
        DB::statement('CREATE TABLE password_reset_tokens (email VARCHAR(255) NOT NULL, token VARCHAR(255) NOT NULL, created_at TIMESTAMP NULL DEFAULT NULL, PRIMARY KEY (email))');

        $this->assertTrue($this->hasPrimaryKeyOnEmail());
        $this->assertFalse(Schema::hasIndex('password_reset_tokens', 'password_reset_tokens_email_index'));

        $this->passwordResetMigration()->up();

        $this->assertFalse($this->hasPrimaryKeyOnEmail());
        $this->assertTrue(Schema::hasIndex('password_reset_tokens', 'password_reset_tokens_email_index'));
    }
}
