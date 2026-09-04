<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\SpeakerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivateEmptySpeakerProfilesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_empty_profiles_without_changing_them(): void
    {
        $profile = $this->emptyProfile();

        $this->artisan('speaker-profiles:deactivate-empty')->assertSuccessful();

        $this->assertTrue($profile->fresh()?->is_active);
    }

    public function test_apply_deactivates_only_the_empty_profiles(): void
    {
        $empty = $this->emptyProfile();
        $real = $this->populatedProfile();

        $this->artisan('speaker-profiles:deactivate-empty --apply')->assertSuccessful();

        $this->assertFalse($empty->fresh()?->is_active);
        $this->assertTrue($real->fresh()?->is_active, 'A profile with a real centroid must be left alone.');
    }

    public function test_a_profile_that_is_already_inactive_is_not_reported(): void
    {
        $this->emptyProfile(['is_active' => false]);

        $this->artisan('speaker-profiles:deactivate-empty')
            ->expectsOutputToContain('No active profiles have an empty centroid.')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function emptyProfile(array $overrides = []): SpeakerProfile
    {
        return SpeakerProfile::factory()->create(array_merge([
            'preacher_id' => Preacher::factory()->create()->id,
            'centroid_embedding' => array_fill(0, 256, 0.0),
            'sample_count' => 0,
            'is_active' => true,
        ], $overrides));
    }

    private function populatedProfile(): SpeakerProfile
    {
        return SpeakerProfile::factory()->create([
            'preacher_id' => Preacher::factory()->create()->id,
            'centroid_embedding' => array_fill(0, 256, 0.0625),
            'sample_count' => 10,
            'is_active' => true,
        ]);
    }
}
