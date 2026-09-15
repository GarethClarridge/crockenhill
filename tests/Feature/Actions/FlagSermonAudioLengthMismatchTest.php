<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FlagSermonAudioLengthMismatch;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlagSermonAudioLengthMismatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_holds_the_sermon_when_the_mp3_runs_short_of_its_video(): void
    {
        [$run, $section] = $this->runWithSermon();

        $outcome = (new FlagSermonAudioLengthMismatch)($run, 1560.0, 1548.0);

        $section->refresh();
        $this->assertSame(['raised' => 1, 'withdrawn' => 0], $outcome);
        $this->assertContains(FlagSermonAudioLengthMismatch::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_withdraws_the_hold_once_the_mp3_matches_its_video(): void
    {
        [$run, $section] = $this->runWithSermon();
        (new FlagSermonAudioLengthMismatch)($run, 1560.0, 1548.0);

        $outcome = (new FlagSermonAudioLengthMismatch)($run, 1560.0, 1559.6);

        $section->refresh();
        $this->assertSame(['raised' => 0, 'withdrawn' => 1], $outcome);
        $this->assertNotContains(FlagSermonAudioLengthMismatch::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function an_unmeasured_mp3_neither_raises_nor_withdraws_the_hold(): void
    {
        [$run, $section] = $this->runWithSermon();
        (new FlagSermonAudioLengthMismatch)($run, 1560.0, 1548.0);

        $outcome = (new FlagSermonAudioLengthMismatch)($run, 1560.0, null);

        $section->refresh();
        $this->assertSame(['raised' => 0, 'withdrawn' => 0], $outcome);
        $this->assertContains(FlagSermonAudioLengthMismatch::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
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
