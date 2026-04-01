<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\Preacher;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpeakerIntegrityTest extends TestCase
{
    use DatabaseTransactions;

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

        $this->assertTrue(Validator::make(['quality_score' => 0.5], $rules)->passes());
        $this->assertTrue(Validator::make(['quality_score' => null], $rules)->passes());
        $this->assertFalse(Validator::make(['quality_score' => 1.1], $rules)->passes());
        $this->assertFalse(Validator::make(['accept_threshold' => -0.1], $rules)->passes());
        $this->assertFalse(Validator::make(['margin_threshold' => 1.01], $rules)->passes());
    }

    #[Test]
    public function it_validates_sample_rules_at_application_level(): void
    {
        $rules = SpeakerSample::validationRules();

        $this->assertTrue(Validator::make(['duration_seconds' => 60, 'quality_score' => 0.9], $rules)->passes());
        $this->assertFalse(Validator::make(['duration_seconds' => -1], $rules)->passes());
        $this->assertFalse(Validator::make(['quality_score' => 1.5], $rules)->passes());
    }

    #[Test]
    public function edit_preacher_component_validates_profile_before_recomputing(): void
    {
        $admin = \App\Models\User::factory()->admin()->create();
        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\Preachers\EditPreacher::class, ['preacher' => $preacher])
            ->call('recomputeProfile', $profile->id)
            ->assertHasNoErrors();
    }

    #[Test]
    public function migration_successfully_cleans_up_invalid_data_and_adds_constraints(): void
    {
        // 1. Determine how many steps to roll back to reach the target migration
        $targetMigration = '2026_03_31_051644_add_integrity_checks_to_speaker_tables';
        $allMigrations = DB::table('migrations')->orderByDesc('id')->pluck('migration')->toArray();
        $targetIndex = array_search($targetMigration, $allMigrations);

        if ($targetIndex === false) {
            $this->fail("Target migration {$targetMigration} not found in migrations table. Migration history may be corrupted or migration was never run.");
        }

        $steps = $targetIndex + 1;

        // 2. Rollback
        $this->artisan('migrate:rollback', ['--step' => $steps, '--force' => true]);

        // 3. Insert invalid data while constraints are gone
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

        // 4. Re-run migrations
        $this->artisan('migrate', ['--force' => true]);

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
    }
}
