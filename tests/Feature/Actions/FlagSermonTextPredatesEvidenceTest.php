<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FlagSermonTextPredatesEvidence;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlagSermonTextPredatesEvidenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_holds_a_sermon_whose_text_predates_a_recovered_transcript(): void
    {
        [$log, $section] = $this->historicRun();
        $log->recordServiceTranscriptContent('the recovered transcript');

        $outcome = app(FlagSermonTextPredatesEvidence::class)($log->refresh());

        self::assertSame(['raised' => 1, 'withdrawn' => 0], $outcome);
        $section->refresh();
        self::assertContains(FlagSermonTextPredatesEvidence::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        self::assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_withdraws_the_hold_once_the_text_is_re_derived(): void
    {
        [$log, $section] = $this->historicRun([FlagSermonTextPredatesEvidence::FLAG]);
        $section->forceFill(['needs_manual_review' => true])->save();

        $log->recordServiceTranscriptContent('the recovered transcript');
        $log->recordSermonDerivedFrom('the recovered transcript');

        $outcome = app(FlagSermonTextPredatesEvidence::class)($log->refresh());

        self::assertSame(['raised' => 0, 'withdrawn' => 1], $outcome);
        self::assertNotContains(
            FlagSermonTextPredatesEvidence::FLAG,
            $section->refresh()->metadata?->toArray()['review_flags'] ?? [],
        );
        self::assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function it_leaves_a_run_that_was_never_recovered_alone(): void
    {
        // Every run banked before the stamps existed is silent on both sides.
        // Reading that as owed would re-derive the whole corpus on a guess.
        [$log, $section] = $this->historicRun();

        self::assertSame(['raised' => 0, 'withdrawn' => 0], app(FlagSermonTextPredatesEvidence::class)($log));
        self::assertSame([], $section->refresh()->metadata?->toArray()['review_flags'] ?? []);
    }

    #[Test]
    public function it_holds_a_childrens_talk_too(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();
        $talk = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'start_time' => 367.0,
            'end_time' => 762.99,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);
        $log->recordServiceTranscriptContent('the recovered transcript');

        app(FlagSermonTextPredatesEvidence::class)($log->refresh());

        self::assertContains(
            FlagSermonTextPredatesEvidence::FLAG,
            $talk->refresh()->metadata?->toArray()['review_flags'] ?? [],
        );
    }

    /**
     * @param  list<string>  $flags
     * @return array{0: MediaProcessingLog, 1: ServiceSection}
     */
    private function historicRun(array $flags = []): array
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon,
            'start_time' => 0.0,
            'end_time' => 1000.0,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => $flags],
        ]);

        return [$log, $section];
    }
}
