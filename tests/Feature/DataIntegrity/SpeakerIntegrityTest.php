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
        $preacher = Preacher::factory()->create();
        $baseData = [
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'sample_count' => 10,
            'is_active' => true,
        ];

        $this->assertTrue(Validator::make([...$baseData, 'quality_score' => 0.5], $rules)->passes());
        $this->assertTrue(Validator::make([...$baseData, 'quality_score' => null], $rules)->passes());
        $this->assertFalse(Validator::make([...$baseData, 'quality_score' => 1.1], $rules)->passes());
        $this->assertFalse(Validator::make([...$baseData, 'accept_threshold' => -0.1], $rules)->passes());
        $this->assertFalse(Validator::make([...$baseData, 'margin_threshold' => 1.01], $rules)->passes());
    }

    #[Test]
    public function it_validates_sample_rules_at_application_level(): void
    {
        $rules = SpeakerSample::validationRules();
        $profile = SpeakerProfile::factory()->create();
        $baseData = [
            'speaker_profile_id' => $profile->id,
            'duration_seconds' => 60,
            'source' => 'upload_auto',
            'approved' => true,
        ];

        $this->assertTrue(Validator::make([...$baseData, 'quality_score' => 0.9], $rules)->passes());
        $this->assertFalse(Validator::make([...$baseData, 'duration_seconds' => -1], $rules)->passes());
        $this->assertFalse(Validator::make([...$baseData, 'quality_score' => 1.5], $rules)->passes());
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
}
