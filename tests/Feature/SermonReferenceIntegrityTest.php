<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonReferenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_trims_reference_in_model_setter(): void
    {
        $sermon = new Sermon(['reference' => '  John 3:16  ']);
        $this->assertEquals('John 3:16', $sermon->reference);
    }

    #[Test]
    public function it_nullifies_empty_reference_in_model_setter(): void
    {
        $sermon = new Sermon(['reference' => '   ']);
        $this->assertNull($sermon->reference);
    }

    #[Test]
    public function it_rejects_untrimmed_reference_at_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints are specifically tested on MySQL.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermons_reference_format_check');

        DB::table('sermons')->insert([
            'date' => now()->toDateString(),
            'title' => 'Test Sermon',
            'slug' => 'test-sermon-'.uniqid(),
            'preacher' => 'Test Preacher',
            'reference' => '  Invalid  ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_rejects_empty_string_reference_at_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints are specifically tested on MySQL.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermons_reference_format_check');

        DB::table('sermons')->insert([
            'date' => now()->toDateString(),
            'title' => 'Test Sermon',
            'slug' => 'test-sermon-'.uniqid(),
            'preacher' => 'Test Preacher',
            'reference' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_allows_null_reference_at_database_level(): void
    {
        $id = DB::table('sermons')->insertGetId([
            'date' => now()->toDateString(),
            'title' => 'Test Sermon',
            'slug' => 'test-sermon-'.uniqid(),
            'preacher' => 'Test Preacher',
            'reference' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('sermons', [
            'id' => $id,
            'reference' => null,
        ]);
    }

    #[Test]
    public function it_fails_validation_for_empty_string_reference(): void
    {
        $rules = Sermon::validationRules();
        $validator = Validator::make(
            ['reference' => ''],
            ['reference' => $rules['reference']]
        );

        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function it_fails_validation_for_whitespace_only_reference(): void
    {
        $rules = Sermon::validationRules();
        $validator = Validator::make(
            ['reference' => '   '],
            ['reference' => $rules['reference']]
        );

        $this->assertTrue($validator->fails());
    }

    #[Test]
    public function it_passes_validation_for_null_reference(): void
    {
        $rules = Sermon::validationRules();
        $validator = Validator::make(
            ['reference' => null],
            ['reference' => $rules['reference']]
        );

        $this->assertTrue($validator->passes());
    }
}
