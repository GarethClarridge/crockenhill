<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 36 of the 64 runs held for transcript loss hold only records no check can re-test, so they park
 * at Tier C after re-transcription until a person looks. The report proposes, the operator
 * releases; nothing clears a hold by itself.
 */
class RetranscribedHoldsCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSCRIPT_PATH = 'service-transcripts/run.json';

    private const SPARSE_REASON = 'Saved sermon text lost a passage to a sparse thirty-second cadence';

    private MediaProcessingLog $run;

    private ServiceSection $sermon;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('media-processing.storage.transcript_disk', 'local');
        $this->run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_metadata' => ['service_transcript_path' => self::TRANSCRIPT_PATH],
        ]);
        $this->sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $this->run->id,
            'section_type' => ServiceSectionType::Sermon,
            'start_time' => 0.0,
            'end_time' => 200.0,
            'duration' => 200.0,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);
        $this->storeTranscript(gapFrom: null);
        app(HoldSectionForContentReview::class)($this->sermon, self::SPARSE_REASON, 'operator listening 2026-09-13', ContentHoldCheck::Judgement);
    }

    #[Test]
    public function a_held_passage_the_new_text_restores_is_proposed_for_release(): void
    {
        $this->heldOnEarlierText();
        $report = storage_path('framework/testing/held-report-'.getmypid().'.json');

        $this->artisan('historic-import:held-transcript-report', ['runs' => [$this->run->id], '--report' => $report])
            ->expectsOutputToContain('1 looks_restored')
            ->assertSuccessful();

        $row = json_decode((string) file_get_contents($report), true)['holds'][0];
        File::delete($report);
        $this->assertTrue($row['transcript_loss']);
        $this->assertTrue($row['text_changed_since_hold']);
        $this->assertSame(40, $row['measures']['cues']);
        $this->assertEquals(1.0, $row['measures']['longest_gap_seconds']);
        $this->assertTrue($this->sermon->refresh()->needs_manual_review);
    }

    #[Test]
    public function a_passage_still_missing_text_is_not_proposed(): void
    {
        $this->storeTranscript(gapFrom: 100.0);
        $this->heldOnEarlierText();

        $this->artisan('historic-import:held-transcript-report', ['runs' => [$this->run->id]])
            ->expectsOutputToContain('1 still_suspect')
            ->assertSuccessful();
    }

    /** A hold about the text the run still holds has nothing to report until Tier A reaches it. */
    #[Test]
    public function a_hold_on_the_current_text_is_listed_only_on_request(): void
    {
        $this->artisan('historic-import:held-transcript-report', ['runs' => [$this->run->id]])
            ->expectsOutputToContain('No live hold matches.')
            ->assertSuccessful();

        $this->artisan('historic-import:held-transcript-report', ['runs' => [$this->run->id], '--all' => true])
            ->expectsOutputToContain('1 text_unchanged')
            ->assertSuccessful();
    }

    #[Test]
    public function the_operator_releases_one_hold_with_a_reason_and_its_record_is_kept(): void
    {
        $this->artisan('service:release-content-hold', ['section' => $this->sermon->id])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();
        $this->artisan('service:release-content-hold', ['section' => $this->sermon->id, '--execute' => true])
            ->expectsOutputToContain('needs the reason')
            ->assertFailed();
        $this->assertTrue($this->sermon->refresh()->needs_manual_review);

        $this->artisan('service:release-content-hold', [
            'section' => $this->sermon->id,
            '--because' => 'Tier A text restores the passage; listened 2026-10-03',
            '--execute' => true,
        ])->expectsOutputToContain('Live holds left: 0. Needs review: no.')->assertSuccessful();

        $this->sermon->refresh();
        $record = HoldSectionForContentReview::holdsIn($this->sermon->metadata?->toArray() ?? [])[0];
        $this->assertSame('operator', $record['released_by']);
        $this->assertSame(self::SPARSE_REASON, $record['reason']);
        $this->assertNotContains(HoldSectionForContentReview::FLAG, $this->sermon->metadata->reviewFlags ?? []);
        $this->assertFalse($this->sermon->needs_manual_review);
    }

    /** The hold was raised on a transcript the re-transcription has since replaced. */
    private function heldOnEarlierText(): void
    {
        $metadata = $this->sermon->refresh()->metadata?->toArray() ?? [];
        $metadata[HoldSectionForContentReview::METADATA_KEY][0]['transcript_sha256'] = str_repeat('a', 64);
        $this->sermon->forceFill(['metadata' => $metadata])->save();
    }

    /** A cue every five seconds across the sermon, with an optional 20 s hole. */
    private function storeTranscript(?float $gapFrom): void
    {
        $words = ['grace', 'kingdom', 'shepherd', 'covenant', 'mercy', 'harvest', 'promise', 'river', 'mountain', 'servant', 'light', 'bread', 'vine', 'temple', 'journey', 'faith', 'hope', 'city', 'garden', 'morning', 'stone', 'water', 'fire', 'peace', 'word', 'door', 'seed', 'path', 'crown', 'lamp'];
        $cues = [];

        for ($index = 0; $index < 40; $index++) {
            $start = $index * 5.0;

            if ($gapFrom !== null && $start >= $gapFrom && $start < $gapFrom + 20.0) {
                continue;
            }

            // Ordinary preaching: varied words, no phrase said twice running.
            $text = implode(' ', array_map(static fn (int $k): string => $words[($index * 7 + $k * 11) % count($words)], range(0, 5)));
            $cues[] = ['start' => $start, 'end' => $start + 4.0, 'text' => ucfirst($text).'.'];
        }

        Storage::disk('local')->put(self::TRANSCRIPT_PATH, json_encode(['cues' => $cues], JSON_THROW_ON_ERROR));
    }
}
