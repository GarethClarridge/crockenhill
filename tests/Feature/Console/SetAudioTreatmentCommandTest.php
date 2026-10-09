<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AudioProfile;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioTreatmentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SetAudioTreatmentCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_turns_on_hum_removal_and_de_essing_for_one_recording(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create(['processing_metadata' => ['trim' => ['kept' => true]]]);

        $this->artisan('media:audio-treatment', ['run' => $run->id, 'profile' => 'speech', '--set' => ['hum_hz=50', 'de_ess=i=0.5:m=0.5:f=0.5']])
            ->assertSuccessful();

        $settings = AudioTreatmentSettings::for(AudioProfile::Speech, AudioTreatmentSettings::overridesFor($run->refresh(), AudioProfile::Speech));
        $this->assertSame(50.0, $settings->humHz);
        $this->assertSame('i=0.5:m=0.5:f=0.5', $settings->deEss);
        $this->assertNull(AudioTreatmentSettings::for(AudioProfile::Music, AudioTreatmentSettings::overridesFor($run, AudioProfile::Music))->humHz);
        $this->assertTrue($run->processing_metadata->toArray()['trim']['kept']);
    }

    #[Test]
    public function unset_and_clear_return_the_recording_to_the_profile_defaults(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => ['audio_treatment_overrides' => ['speech' => ['hum_hz' => 50, 'high_pass_hz' => 80]]],
        ]);

        $this->artisan('media:audio-treatment', ['run' => $run->id, 'profile' => 'speech', '--unset' => ['hum_hz']])->assertSuccessful();
        $this->assertSame(['high_pass_hz' => 80], AudioTreatmentSettings::overridesFor($run->refresh(), AudioProfile::Speech));

        $this->artisan('media:audio-treatment', ['run' => $run->id, 'profile' => 'speech', '--clear' => true])->assertSuccessful();
        $this->assertArrayNotHasKey('audio_treatment_overrides', $run->refresh()->processing_metadata->toArray());
    }

    #[Test]
    public function it_refuses_a_setting_the_profile_does_not_have(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $this->artisan('media:audio-treatment', ['run' => $run->id, 'profile' => 'speech', '--set' => ['volume=11']])->assertFailed();
        $this->artisan('media:audio-treatment', ['run' => $run->id, 'profile' => 'podcast'])->assertFailed();

        $this->assertSame([], AudioTreatmentSettings::overridesFor($run->refresh(), AudioProfile::Speech));
    }
}
