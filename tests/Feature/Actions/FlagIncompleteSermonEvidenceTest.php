<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FlagIncompleteSermonEvidence;
use App\Data\ChurchServiceTranscript;
use App\Data\SermonEvidenceCoverage;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlagIncompleteSermonEvidenceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{start: float, end: float}> */
    private const SPANS = [['start' => 0.0, 'end' => 1000.0]];

    #[Test]
    public function it_flags_a_materially_blind_sermon_span(): void
    {
        [$log, $section] = $this->historicRun();

        $changed = app(FlagIncompleteSermonEvidence::class)($log, $this->coverage(400.0));

        self::assertTrue($changed);
        $section->refresh();
        self::assertContains(FlagIncompleteSermonEvidence::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        self::assertSame(0.4, $section->metadata?->toArray()['sermon_evidence_unobservable_fraction']);
        self::assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_withdraws_the_flag_once_the_evidence_is_recovered(): void
    {
        [$log, $section] = $this->historicRun([FlagIncompleteSermonEvidence::FLAG]);
        $section->forceFill(['needs_manual_review' => true])->save();

        // Recovery is exactly the event that makes a standing flag wrong; a
        // flag that only ever accumulates stops meaning "look at this".
        $changed = app(FlagIncompleteSermonEvidence::class)($log, $this->coverage(10.0));

        self::assertTrue($changed);
        $section->refresh();
        self::assertNotContains(FlagIncompleteSermonEvidence::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        self::assertArrayNotHasKey('sermon_evidence_unobservable_fraction', $section->metadata?->toArray() ?? []);
        self::assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function it_keeps_every_other_review_flag(): void
    {
        [$log, $section] = $this->historicRun(['song_title_marker_mismatch']);

        app(FlagIncompleteSermonEvidence::class)($log, $this->coverage(10.0));

        $section->refresh();
        self::assertSame(['song_title_marker_mismatch'], $section->metadata?->toArray()['review_flags'] ?? []);
    }

    #[Test]
    public function it_reports_no_change_when_the_verdict_is_unchanged(): void
    {
        [$log] = $this->historicRun();

        self::assertFalse(app(FlagIncompleteSermonEvidence::class)($log, $this->coverage(10.0)));
    }

    #[Test]
    public function it_reports_no_change_when_the_flag_is_already_recorded(): void
    {
        [$log] = $this->historicRun();
        $flagger = app(FlagIncompleteSermonEvidence::class);

        self::assertTrue($flagger($log, $this->coverage(400.0)));

        // The stored metadata has been through JSON, so a rounded 400.0 comes
        // back as the integer 400. Comparing the arrays strictly would report a
        // change on every pass while writing nothing new.
        self::assertFalse($flagger($log, $this->coverage(400.0)));
    }

    private function coverage(float $blindSeconds): SermonEvidenceCoverage
    {
        return SermonEvidenceCoverage::measure(
            self::SPANS,
            ChurchServiceTranscript::fromCues(
                [['start' => 0.0, 'end' => 1000.0, 'text' => 'The sermon.']],
                1000.0,
                ChurchServiceTranscript::SOURCE_MOCK,
                [['start' => 0.0, 'end' => $blindSeconds, 'reason' => 'retranscription_failed']],
            ),
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
