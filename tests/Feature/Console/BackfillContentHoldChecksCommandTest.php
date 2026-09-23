<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ContentHoldRechecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillContentHoldChecksCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSCRIPT_PATH = 'service-transcripts/run.json';

    private const TRANSCRIPT = '{"cues":[]}';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('media-processing.storage.transcript_disk', 'local');
        Storage::disk('local')->put(self::TRANSCRIPT_PATH, self::TRANSCRIPT);
    }

    #[Test]
    public function it_reports_without_writing_by_default(): void
    {
        $section = $this->heldSection($this->processingRun(), ['reason' => 'short_transcript_loop_source_mismatch']);

        $this->artisan('service:backfill-content-hold-checks')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        self::assertArrayNotHasKey('found_by', $this->records($section)[0]);
    }

    #[Test]
    public function it_records_which_check_found_each_hold_from_its_reason(): void
    {
        $run = $this->processingRun();
        $sections = [
            'loop_screen' => $this->heldSection($run, ['reason' => 'short_transcript_loop_source_mismatch']),
            'lyric_comparison' => $this->heldSection($run, ['reason' => 'Wrong song: fresh audio inside the section sings Praise the Lord You Heavens (#797)']),
            'decision' => $this->heldSection($run, ['reason' => 'Duplicate-performance identity unresolved: a paired run claims the same sermon']),
            'judgement' => $this->heldSection($run, ['reason' => 'held_sample_semantic_substitution_and_short_loop']),
            'media_measurement' => $this->heldSection($run, ['reason' => 'Sermon MP3 ends 3-9 s before its video and loses the closing words']),
            'source_audio' => $this->heldSection($run, ['reason' => 'reserved_fragmentation_source_mismatch']),
            'boundary' => $this->heldSection($run, ['reason' => 'Song clip leaves out verses of its own song']),
        ];

        $this->artisan('service:backfill-content-hold-checks', ['--execute' => true])->assertSuccessful();

        foreach ($sections as $check => $section) {
            $record = $this->records($section)[0];

            self::assertSame($check, $record['found_by'] ?? null, "Section {$section->id}");
            self::assertEquals((float) $section->start_time, $record['start_time'] ?? null);
        }
    }

    #[Test]
    public function a_hold_found_before_a_retranscription_is_left_open_to_its_recheck(): void
    {
        $repaired = $this->processingRun([
            ['kind' => 'raw', 'path' => 'raw.json', 'recorded_at' => '2026-09-23T18:00:49+00:00'],
        ]);
        $untouched = $this->processingRun([
            ['kind' => 'raw', 'path' => 'raw.json', 'recorded_at' => '2026-09-01T10:00:00+00:00'],
        ]);
        $held = ['reason' => 'short_transcript_loop_source_mismatch', 'held_at' => '2026-09-14T15:07:41+00:00'];
        $repairedSection = $this->heldSection($repaired, $held);
        $untouchedSection = $this->heldSection($untouched, $held);

        $this->artisan('service:backfill-content-hold-checks', ['--execute' => true])->assertSuccessful();

        self::assertSame(ContentHoldRechecker::SUPERSEDED, $this->records($repairedSection)[0]['transcript_sha256'] ?? null, 'Repaired since the hold: the next re-check runs.');
        self::assertSame(hash('sha256', self::TRANSCRIPT), $this->records($untouchedSection)[0]['transcript_sha256'] ?? null, 'Not repaired: waits for a repair.');
    }

    #[Test]
    public function it_removes_a_record_left_behind_on_a_row_that_never_carried_its_content(): void
    {
        $run = $this->processingRun();
        $record = ['reason' => 'Wrong song: sings #797 not #934', 'evidence' => 'songloop register', 'held_at' => '2026-09-14T15:07:41+00:00'];
        $song = $this->heldSection($run, $record);
        $reading = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::BibleReading,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => [], HoldSectionForContentReview::METADATA_KEY => [$record]],
        ]);

        $this->artisan('service:backfill-content-hold-checks', ['--execute' => true])->assertSuccessful();

        self::assertArrayNotHasKey(HoldSectionForContentReview::METADATA_KEY, $reading->refresh()->metadata?->toArray() ?? []);
        self::assertCount(1, $this->records($song));
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     */
    private function processingRun(array $artifacts = []): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_metadata' => ['service_transcript_path' => self::TRANSCRIPT_PATH, 'service_artifacts' => $artifacts],
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function heldSection(MediaProcessingLog $run, array $record): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'needs_manual_review' => true,
            'metadata' => [
                'review_flags' => [HoldSectionForContentReview::FLAG],
                HoldSectionForContentReview::METADATA_KEY => [[
                    'evidence' => 'register',
                    'held_at' => '2026-09-14T15:07:41+00:00',
                    ...$record,
                ]],
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(ServiceSection $section): array
    {
        return $section->refresh()->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? [];
    }
}
