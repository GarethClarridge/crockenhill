<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AddIsAdminToUsersTableIfMissingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/2026_02_26_214602_add_is_admin_to_users_table_if_missing.php');

        return $migration;
    }

    #[Test]
    public function it_adds_is_admin_when_missing(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_admin');
        });

        $this->assertFalse(Schema::hasColumn('users', 'is_admin'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('users', 'is_admin'));
    }

    #[Test]
    public function it_is_safe_to_run_when_column_already_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'is_admin'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('users', 'is_admin'));
    }
}
