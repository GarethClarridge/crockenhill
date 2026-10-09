<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FlagSermonAudioPartUntreated;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\Audio\PartLoudness;
use App\Services\Media\Video\ExtractedMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlagSermonAudioPartUntreatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_holds_the_sermon_when_a_part_was_left_at_its_recorded_loudness(): void
    {
        [$run, $section] = $this->runWithSermon();

        $outcome = (new FlagSermonAudioPartUntreated)($run, $this->media([null, PartLoudness::Silent]));

        $section->refresh();
        $this->assertSame(['raised' => 1, 'withdrawn' => 0], $outcome);
        $this->assertContains(FlagSermonAudioPartUntreated::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_withdraws_the_hold_once_every_part_is_treated(): void
    {
        [$run, $section] = $this->runWithSermon();
        (new FlagSermonAudioPartUntreated)($run, $this->media([PartLoudness::TooQuiet]));

        $outcome = (new FlagSermonAudioPartUntreated)($run, $this->media([null, null]));

        $section->refresh();
        $this->assertSame(['raised' => 0, 'withdrawn' => 1], $outcome);
        $this->assertNotContains(FlagSermonAudioPartUntreated::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    /** @param  list<string|null>  $untreatedReasons  one per part; null = treated */
    private function media(array $untreatedReasons): ExtractedMedia
    {
        return new ExtractedMedia('temp/sermon.mp4', 'temp/sermon.mp3', [
            'parts' => array_map(static fn (?string $reason): array => ['untreated_reason' => $reason], $untreatedReasons),
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
