<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FlagSermonAudioLoudnessMissed;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Video\ExtractedMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlagSermonAudioLoudnessMissedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function video_peak_misses_are_reported_without_holding_the_sermon(): void
    {
        [$run, $section] = $this->runWithSermon();
        $miss = 'part 1 video true peak 3.9 dBTP, limit -0.5';
        $media = $this->media([$miss]);

        $this->assertSame(['raised' => 0, 'withdrawn' => 0], (new FlagSermonAudioLoudnessMissed)($run, $media));
        $this->assertSame([$miss], $media->loudnessMisses());
        $this->assertFalse($section->fresh()->needs_manual_review);

        (new FlagSermonAudioLoudnessMissed)($run, $this->media(['part 1 public audio true peak 3.0 dBTP, limit 1.0']));
        $this->assertTrue($section->fresh()->needs_manual_review);

        $outcome = (new FlagSermonAudioLoudnessMissed)($run, $media);
        $this->assertSame(['raised' => 0, 'withdrawn' => 1], $outcome);
        $this->assertFalse($section->fresh()->needs_manual_review);
    }

    #[Test]
    public function a_video_peak_miss_does_not_mask_an_integrated_or_public_audio_miss(): void
    {
        foreach (['part 1 video integrated -18.4 LUFS, target -16.0 ±1.0', 'part 1 public audio true peak 3.0 dBTP, limit 1.0'] as $miss) {
            [$run, $section] = $this->runWithSermon();
            (new FlagSermonAudioLoudnessMissed)($run, $this->media(['part 1 video true peak 3.9 dBTP, limit -0.5', $miss]));
            $this->assertTrue($section->fresh()->needs_manual_review);
        }
    }

    #[Test]
    public function it_holds_the_sermon_when_a_treated_part_missed_its_target(): void
    {
        [$run, $section] = $this->runWithSermon();

        $outcome = (new FlagSermonAudioLoudnessMissed)($run, $this->media(['part 2 video integrated -18.4 LUFS, target -16.0 ±1.0']));

        $section->refresh();
        $this->assertSame(['raised' => 1, 'withdrawn' => 0], $outcome);
        $this->assertContains(FlagSermonAudioLoudnessMissed::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_withdraws_the_hold_once_every_part_meets_its_target(): void
    {
        [$run, $section] = $this->runWithSermon();
        (new FlagSermonAudioLoudnessMissed)($run, $this->media(['part 1 public audio true peak 0.2 dBTP, ceiling -1.5 +1.0']));

        $outcome = (new FlagSermonAudioLoudnessMissed)($run, $this->media([]));

        $section->refresh();
        $this->assertSame(['raised' => 0, 'withdrawn' => 1], $outcome);
        $this->assertNotContains(FlagSermonAudioLoudnessMissed::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    /** @param  list<string>  $misses */
    private function media(array $misses): ExtractedMedia
    {
        return new ExtractedMedia('temp/sermon.mp4', 'temp/sermon.mp3', ['loudness_misses' => $misses, 'parts' => []]);
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
