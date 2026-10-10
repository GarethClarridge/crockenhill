<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FlagSermonAudioSpeechNearNoise;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Video\ExtractedMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Canary 15, run 1138: a reading off the microphone sat 12.9 dB above the room, and no treatment
 * could make it listenable. 1292's reading, as quiet but 26.8 dB clear of its room, was fine.
 */
class FlagSermonAudioSpeechNearNoiseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_holds_the_sermon_when_a_parts_speech_is_close_to_the_room_noise(): void
    {
        [$run, $section] = $this->runWithSermon();

        $outcome = (new FlagSermonAudioSpeechNearNoise)($run, $this->media([-12.9, -37.0]));

        $section->refresh();
        $this->assertSame(['raised' => 1, 'withdrawn' => 0], $outcome);
        $this->assertContains(FlagSermonAudioSpeechNearNoise::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function a_quiet_part_well_clear_of_its_room_is_not_held(): void
    {
        [$run, $section] = $this->runWithSermon();

        $outcome = (new FlagSermonAudioSpeechNearNoise)($run, $this->media([-26.8, -38.2, null]));

        $section->refresh();
        $this->assertSame(['raised' => 0, 'withdrawn' => 0], $outcome);
        $this->assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function it_withdraws_the_hold_when_a_new_cut_is_clear_of_the_noise(): void
    {
        [$run, $section] = $this->runWithSermon();
        (new FlagSermonAudioSpeechNearNoise)($run, $this->media([-12.9]));

        $outcome = (new FlagSermonAudioSpeechNearNoise)($run, $this->media([-37.0]));

        $section->refresh();
        $this->assertSame(['raised' => 0, 'withdrawn' => 1], $outcome);
        $this->assertNotContains(FlagSermonAudioSpeechNearNoise::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    /** @param  list<float|null>  $pauses  each part's pause level relative to its speech */
    private function media(array $pauses): ExtractedMedia
    {
        return new ExtractedMedia('temp/sermon.mp4', 'temp/sermon.mp3', [
            'loudness_misses' => [],
            'parts' => array_map(static fn (?float $pause): array => ['untreated_reason' => null, 'pause_relative' => $pause], $pauses),
        ]);
    }

    /**
     * @return array{0: MediaProcessingLog, 1: ServiceSection}
     */
    private function runWithSermon(): array
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        return [$run, $section];
    }
}
