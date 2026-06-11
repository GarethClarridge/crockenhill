<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Livewire\Admin\Preachers\EditPreacher;
use App\Models\Preacher;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpeakerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only implemented for MySQL in this project.');
        }
    }

    #[Test]
    public function it_rejects_invalid_profile_quality_score_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('speaker_profiles_quality_score_check');

        DB::table('speaker_profiles')->insert([
            'preacher_id' => Preacher::factory()->create()->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'centroid_embedding' => json_encode(array_fill(0, 256, 0.5)),
            'quality_score' => 1.1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_rejects_invalid_profile_accept_threshold_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('speaker_profiles_accept_threshold_check');

        DB::table('speaker_profiles')->insert([
            'preacher_id' => Preacher::factory()->create()->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'centroid_embedding' => json_encode(array_fill(0, 256, 0.5)),
            'accept_threshold' => -0.1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_rejects_invalid_profile_margin_threshold_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('speaker_profiles_margin_threshold_check');

        DB::table('speaker_profiles')->insert([
            'preacher_id' => Preacher::factory()->create()->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'centroid_embedding' => json_encode(array_fill(0, 256, 0.5)),
            'margin_threshold' => 1.01,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_rejects_invalid_sample_quality_score_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('speaker_samples_quality_score_check');

        $profile = SpeakerProfile::factory()->create();

        DB::table('speaker_samples')->insert([
            'speaker_profile_id' => $profile->id,
            'embedding' => json_encode(array_fill(0, 256, 0.5)),
            'duration_seconds' => 60.0,
            'quality_score' => -0.01,
            'source' => 'upload_auto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_rejects_negative_sample_duration_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('speaker_samples_duration_check');

        $profile = SpeakerProfile::factory()->create();

        DB::table('speaker_samples')->insert([
            'speaker_profile_id' => $profile->id,
            'embedding' => json_encode(array_fill(0, 256, 0.5)),
            'duration_seconds' => -1.0,
            'source' => 'upload_auto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_allows_valid_data_in_speaker_profiles(): void
    {
        $profile = SpeakerProfile::factory()->create([
            'quality_score' => 0.85,
            'accept_threshold' => 0.75,
            'margin_threshold' => 0.10,
        ]);

        $this->assertDatabaseHas('speaker_profiles', [
            'id' => $profile->id,
            'quality_score' => 0.85,
            'accept_threshold' => 0.75,
            'margin_threshold' => 0.10,
        ]);
    }

    #[Test]
    public function it_allows_valid_data_in_speaker_samples(): void
    {
        $sample = SpeakerSample::factory()->create([
            'quality_score' => 0.92,
            'duration_seconds' => 120.5,
        ]);

        $this->assertDatabaseHas('speaker_samples', [
            'id' => $sample->id,
            'quality_score' => 0.92,
            'duration_seconds' => 120.5,
        ]);
    }

    #[Test]
    public function it_validates_profile_rules_at_application_level(): void
    {
        $rules = SpeakerProfile::validationRules();

        $this->assertTrue(Validator::make(['quality_score' => 0.5], ['quality_score' => $rules['quality_score']])->passes());
        $this->assertTrue(Validator::make(['quality_score' => null], ['quality_score' => $rules['quality_score']])->passes());
        $this->assertFalse(Validator::make(['quality_score' => 1.1], ['quality_score' => $rules['quality_score']])->passes());
        $this->assertFalse(Validator::make(['accept_threshold' => -0.1], ['accept_threshold' => $rules['accept_threshold']])->passes());
        $this->assertFalse(Validator::make(['margin_threshold' => 1.01], ['margin_threshold' => $rules['margin_threshold']])->passes());
    }

    #[Test]
    public function it_validates_sample_rules_at_application_level(): void
    {
        $rules = SpeakerSample::validationRules();

        $this->assertTrue(Validator::make(['duration_seconds' => 60], ['duration_seconds' => $rules['duration_seconds']])->passes());
        $this->assertTrue(Validator::make(['quality_score' => 0.9], ['quality_score' => $rules['quality_score']])->passes());
        $this->assertFalse(Validator::make(['duration_seconds' => -1], ['duration_seconds' => $rules['duration_seconds']])->passes());
        $this->assertFalse(Validator::make(['quality_score' => 1.5], ['quality_score' => $rules['quality_score']])->passes());
    }

    #[Test]
    public function check_constraints_exist_on_speaker_profiles(): void
    {
        $constraints = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'speaker_profiles' AND CONSTRAINT_TYPE = 'CHECK'",
            [DB::getDatabaseName()]
        );

        $names = collect($constraints)->pluck('CONSTRAINT_NAME');

        $this->assertTrue($names->contains('speaker_profiles_quality_score_check'), 'Missing quality_score CHECK constraint');
        $this->assertTrue($names->contains('speaker_profiles_accept_threshold_check'), 'Missing accept_threshold CHECK constraint');
        $this->assertTrue($names->contains('speaker_profiles_margin_threshold_check'), 'Missing margin_threshold CHECK constraint');
    }

    #[Test]
    public function check_constraints_exist_on_speaker_samples(): void
    {
        $constraints = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'speaker_samples' AND CONSTRAINT_TYPE = 'CHECK'",
            [DB::getDatabaseName()]
        );

        $names = collect($constraints)->pluck('CONSTRAINT_NAME');

        $this->assertTrue($names->contains('speaker_samples_quality_score_check'), 'Missing quality_score CHECK constraint');
        $this->assertTrue($names->contains('speaker_samples_duration_check'), 'Missing duration_seconds CHECK constraint');
    }

    #[Test]
    public function edit_preacher_component_validates_profile_before_recomputing(): void
    {
        $admin = User::factory()->admin()->create();
        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);

        Livewire::actingAs($admin)
            ->test(EditPreacher::class, ['preacher' => $preacher])
            ->call('recomputeProfile', $profile->id)
            ->assertHasNoErrors();
    }

    #[Test]
    public function migration_successfully_cleans_up_invalid_data_and_adds_constraints(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Speaker integrity checks are MySQL-specific.');
        }

        $constraints = [
            'speaker_profiles_quality_score_check',
            'speaker_profiles_accept_threshold_check',
            'speaker_profiles_margin_threshold_check',
            'speaker_samples_quality_score_check',
            'speaker_samples_duration_check',
        ];

        // 1. Drop constraints directly so we can insert invalid data
        foreach ($constraints as $name) {
            $table = str_starts_with($name, 'speaker_profiles') ? 'speaker_profiles' : 'speaker_samples';
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }

        try {
            // 2. Insert invalid data while constraints are gone
            $preacherId = Preacher::factory()->create()->id;
            $profileId = DB::table('speaker_profiles')->insertGetId([
                'preacher_id' => $preacherId,
                'provider' => 'resemblyzer',
                'model_version' => 'v1.0-temporary-test-'.Str::random(8),
                'centroid_embedding' => json_encode(array_fill(0, 256, 0.5)),
                'quality_score' => 1.5, // Invalid (>1)
                'accept_threshold' => -0.5, // Invalid (<0)
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sampleId = DB::table('speaker_samples')->insertGetId([
                'speaker_profile_id' => $profileId,
                'embedding' => json_encode(array_fill(0, 256, 0.5)),
                'duration_seconds' => -10.0, // Invalid (<0)
                'quality_score' => 0.9,
                'source' => 'upload_auto',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Re-apply migration's up() logic directly
            $migration = require database_path('migrations/2026_03_31_051644_add_integrity_checks_to_speaker_tables.php');
            $migration->up();

            // 4. Verify data is cleaned
            $profile = DB::table('speaker_profiles')->where('id', $profileId)->first();
            $this->assertEquals(1.0, $profile->quality_score);
            $this->assertEquals(0.0, $profile->accept_threshold);

            $sample = DB::table('speaker_samples')->where('id', $sampleId)->first();
            $this->assertEquals(0.0, $sample->duration_seconds);

            // 5. Verify constraints are active by trying to insert invalid data again
            $this->expectException(QueryException::class);
            DB::table('speaker_samples')->insert([
                'speaker_profile_id' => $profileId,
                'embedding' => json_encode(array_fill(0, 256, 0.5)),
                'duration_seconds' => -1.0,
                'source' => 'upload_auto',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            // Restore constraints regardless of test outcome
            foreach ($constraints as $name) {
                $table = str_starts_with($name, 'speaker_profiles') ? 'speaker_profiles' : 'speaker_samples';
                try {
                    DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
                } catch (\Throwable) {
                }
            }
            $migration = require database_path('migrations/2026_03_31_051644_add_integrity_checks_to_speaker_tables.php');
            $migration->up();
        }
    }
}
