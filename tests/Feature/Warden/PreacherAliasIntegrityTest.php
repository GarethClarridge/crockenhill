<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherAliasIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_normalizes_alias_on_save(): void
    {
        $preacher = Preacher::factory()->create();

        $alias = PreacherAlias::create([
            'preacher_id' => $preacher->id,
            'alias' => '  Mark Drury  ',
        ]);

        $this->assertEquals('mark drury', $alias->alias);
        $this->assertDatabaseHas('preacher_aliases', [
            'id' => $alias->id,
            'alias' => 'mark drury',
        ]);

        $alias2 = PreacherAlias::create([
            'preacher_id' => $preacher->id,
            'alias' => 'SPURGEON',
        ]);

        $this->assertEquals('spurgeon', $alias2->alias);
        $this->assertDatabaseHas('preacher_aliases', [
            'id' => $alias2->id,
            'alias' => 'spurgeon',
        ]);
    }

    #[Test]
    public function it_enforces_database_check_constraint_for_empty_alias(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database CHECK constraints are only verified for MySQL.');
        }

        $preacher = Preacher::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('preacher_aliases_alias_format_check');

        // We use raw DB insert to bypass model mutators if we want to test the constraint directly
        // But here we test that even if the model tries to save an empty string, the DB rejects it.
        DB::table('preacher_aliases')->insert([
            'preacher_id' => $preacher->id,
            'alias' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_database_check_constraint_for_untrimmed_alias(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database CHECK constraints are only verified for MySQL.');
        }

        $preacher = Preacher::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('preacher_aliases_alias_format_check');

        // Using raw DB to bypass mutator
        DB::table('preacher_aliases')->insert([
            'preacher_id' => $preacher->id,
            'alias' => ' untrimmed ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
