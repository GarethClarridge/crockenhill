<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\ContentHoldRechecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Operator ruling 2026-09-23: after a repair, a hold whose check exists in code is
 * re-run, and clears — with the reason recorded — if that check now passes.
 */
class ContentHoldRecheckerTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSCRIPT_PATH = 'service-transcripts/run.json';

    private const LOOP = 'and he said unto them go ye into all the world and preach';

    private const PRAISE_LYRICS = 'Praise the Lord ye heavens adore him praise him angels in the height sun and moon rejoice before him praise him all ye stars of light';

    private const THRONE_LYRICS = 'There is a higher throne than all this world has known where faithful ones from every tongue will one day come before the Son';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('media-processing.storage.transcript_disk', 'local');
    }

    #[Test]
    public function a_loop_hold_clears_when_the_rewritten_transcript_no_longer_loops(): void
    {
        $run = $this->runWithTranscript($this->loopingCues());
        $sermon = $this->section($run, ServiceSectionType::Sermon, 0.0, 200.0);
        $this->hold($sermon, ContentHoldCheck::LoopScreen);

        $this->rewriteTranscript($run, $this->cleanCues());

        $outcome = app(ContentHoldRechecker::class)->recheck($run->refresh());

        self::assertSame(['cleared' => 1, 'kept' => 0], $outcome);
        $sermon->refresh();
        self::assertFalse($sermon->needs_manual_review);
        self::assertNotContains(HoldSectionForContentReview::FLAG, $sermon->metadata?->toArray()['review_flags'] ?? []);

        $record = $this->records($sermon)[0];
        self::assertSame('loop_screen', $record['cleared_by'] ?? null);
        self::assertNotEmpty($record['cleared_at'] ?? null);
        self::assertIsString($record['cleared_reason'] ?? null);
    }

    #[Test]
    public function a_loop_hold_stays_when_the_rewritten_transcript_still_loops(): void
    {
        $run = $this->runWithTranscript($this->loopingCues());
        $sermon = $this->section($run, ServiceSectionType::Sermon, 0.0, 200.0);
        $this->hold($sermon, ContentHoldCheck::LoopScreen);

        $this->rewriteTranscript($run, [...$this->loopingCues(), ['start' => 190.0, 'end' => 195.0, 'text' => 'amen']]);

        $outcome = app(ContentHoldRechecker::class)->recheck($run->refresh());

        self::assertSame(['cleared' => 0, 'kept' => 1], $outcome);
        self::assertTrue($sermon->refresh()->needs_manual_review);

        $record = $this->records($sermon)[0];
        self::assertArrayNotHasKey('cleared_at', $record);
        self::assertNotEmpty($record['rechecked_at'] ?? null);
        self::assertSame($run->refresh()->serviceTranscriptSha256(), $record['transcript_sha256'] ?? null, 'A failed re-check is not repeated until the next repair.');
    }

    #[Test]
    public function nothing_is_rechecked_until_a_repair_rewrites_the_transcript(): void
    {
        $run = $this->runWithTranscript($this->cleanCues());
        $sermon = $this->section($run, ServiceSectionType::Sermon, 0.0, 200.0);
        // Held by a census that saw what the code screen cannot: no repair has happened since.
        $this->hold($sermon, ContentHoldCheck::LoopScreen);

        $outcome = app(ContentHoldRechecker::class)->recheck($run->refresh());

        self::assertSame(['cleared' => 0, 'kept' => 0], $outcome);
        self::assertTrue($sermon->refresh()->needs_manual_review);
        self::assertArrayNotHasKey('rechecked_at', $this->records($sermon)[0]);
    }

    /**
     * No fingerprint means the transcript the hold was found on could not be read,
     * so whether a repair has happened since is unknown — not that one has.
     */
    #[Test]
    public function a_hold_with_no_fingerprint_is_not_rechecked(): void
    {
        $run = $this->runWithTranscript($this->cleanCues());
        $sermon = $this->section($run, ServiceSectionType::Sermon, 0.0, 200.0);
        $this->hold($sermon, ContentHoldCheck::LoopScreen);

        $metadata = $sermon->refresh()->metadata?->toArray() ?? [];
        $metadata[HoldSectionForContentReview::METADATA_KEY][0]['transcript_sha256'] = null;
        $sermon->forceFill(['metadata' => $metadata])->save();

        self::assertSame(['cleared' => 0, 'kept' => 0], app(ContentHoldRechecker::class)->recheck($run->refresh()));
        self::assertTrue($sermon->refresh()->needs_manual_review);
    }

    #[Test]
    public function decisions_and_judgements_are_never_cleared_by_a_recheck(): void
    {
        $run = $this->runWithTranscript($this->loopingCues());
        $sermon = $this->section($run, ServiceSectionType::Sermon, 0.0, 200.0);
        $this->hold($sermon, ContentHoldCheck::LoopScreen);
        app(HoldSectionForContentReview::class)($sermon->refresh(), 'Duplicate-performance identity unresolved', 'plan §4.4', ContentHoldCheck::Decision);

        $this->rewriteTranscript($run, $this->cleanCues());

        $outcome = app(ContentHoldRechecker::class)->recheck($run->refresh());

        self::assertSame(['cleared' => 1, 'kept' => 0], $outcome, 'A decision is not re-checked, so it is neither cleared nor counted.');
        $sermon->refresh();
        self::assertTrue($sermon->needs_manual_review, 'The decision still holds the sermon.');
        self::assertContains(HoldSectionForContentReview::FLAG, $sermon->metadata?->toArray()['review_flags'] ?? []);

        [$loop, $decision] = $this->records($sermon);
        self::assertSame('loop_screen', $loop['cleared_by'] ?? null);
        self::assertArrayNotHasKey('cleared_at', $decision);
    }

    #[Test]
    public function a_lyric_hold_clears_when_the_section_now_sings_its_bound_song(): void
    {
        $bound = $this->song('There Is A Higher Throne', self::THRONE_LYRICS);
        $this->song('Praise The Lord Ye Heavens', self::PRAISE_LYRICS);

        $run = $this->runWithTranscript([['start' => 10.0, 'end' => 60.0, 'text' => self::PRAISE_LYRICS]]);
        $song = $this->section($run, ServiceSectionType::Song, 0.0, 100.0, $bound);
        $this->hold($song, ContentHoldCheck::LyricComparison);

        $this->rewriteTranscript($run, [['start' => 10.0, 'end' => 60.0, 'text' => self::THRONE_LYRICS]]);

        $outcome = app(ContentHoldRechecker::class)->recheck($run->refresh());

        self::assertSame(['cleared' => 1, 'kept' => 0], $outcome);
        self::assertFalse($song->refresh()->needs_manual_review);
        self::assertSame('lyric_comparison', $this->records($song)[0]['cleared_by'] ?? null);
    }

    #[Test]
    public function a_lyric_hold_stays_when_the_section_still_sings_another_song(): void
    {
        $bound = $this->song('There Is A Higher Throne', self::THRONE_LYRICS);
        $this->song('Praise The Lord Ye Heavens', self::PRAISE_LYRICS);

        $run = $this->runWithTranscript([['start' => 10.0, 'end' => 60.0, 'text' => self::PRAISE_LYRICS]]);
        $song = $this->section($run, ServiceSectionType::Song, 0.0, 100.0, $bound);
        $this->hold($song, ContentHoldCheck::LyricComparison);

        $this->rewriteTranscript($run, [['start' => 12.0, 'end' => 62.0, 'text' => self::PRAISE_LYRICS]]);

        $outcome = app(ContentHoldRechecker::class)->recheck($run->refresh());

        self::assertSame(['cleared' => 0, 'kept' => 1], $outcome);
        self::assertTrue($song->refresh()->needs_manual_review);
    }

    #[Test]
    public function an_unreadable_transcript_keeps_every_hold(): void
    {
        $run = $this->runWithTranscript($this->loopingCues());
        $sermon = $this->section($run, ServiceSectionType::Sermon, 0.0, 200.0);
        $this->hold($sermon, ContentHoldCheck::LoopScreen);

        Storage::disk('local')->delete(self::TRANSCRIPT_PATH);

        $outcome = app(ContentHoldRechecker::class)->recheck($run->refresh());

        self::assertSame(['cleared' => 0, 'kept' => 0], $outcome);
        self::assertTrue($sermon->refresh()->needs_manual_review);
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     */
    private function runWithTranscript(array $cues): MediaProcessingLog
    {
        $this->writeTranscript($cues);

        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_metadata' => ['service_transcript_path' => self::TRANSCRIPT_PATH],
        ]);
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     */
    private function rewriteTranscript(MediaProcessingLog $run, array $cues): void
    {
        $this->writeTranscript($cues);
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     */
    private function writeTranscript(array $cues): void
    {
        Storage::disk('local')->put(self::TRANSCRIPT_PATH, json_encode([
            'cues' => $cues,
            'duration' => 3600.0,
            'source' => 'whisper',
            'unobservable_windows' => [],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{start: float, end: float, text: string}>
     */
    private function loopingCues(): array
    {
        $cues = [['start' => 0.0, 'end' => 10.0, 'text' => 'Let us turn to the gospel of Mark this morning.']];

        for ($i = 0; $i < 8; $i++) {
            $cues[] = ['start' => 10.0 + $i * 5, 'end' => 15.0 + $i * 5, 'text' => self::LOOP];
        }

        return $cues;
    }

    /**
     * @return list<array{start: float, end: float, text: string}>
     */
    private function cleanCues(): array
    {
        return [
            ['start' => 0.0, 'end' => 10.0, 'text' => 'Let us turn to the gospel of Mark this morning.'],
            ['start' => 10.0, 'end' => 20.0, 'text' => 'And he said unto them, go ye into all the world and preach the gospel.'],
            ['start' => 20.0, 'end' => 30.0, 'text' => 'That commission was given to a handful of frightened disciples.'],
            ['start' => 30.0, 'end' => 40.0, 'text' => 'They had no buildings, no money and no influence.'],
            ['start' => 40.0, 'end' => 50.0, 'text' => 'Yet within a generation the message had reached Rome itself.'],
        ];
    }

    private function section(MediaProcessingLog $run, ServiceSectionType $type, float $start, float $end, ?Song $song = null): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $song === null ? null : ChurchServiceItem::factory()->create(['song_id' => $song->id])->id,
            'section_type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);
    }

    private function song(string $title, string $lyrics): Song
    {
        return Song::factory()->create(['title' => $title, 'canonical_key' => Song::canonicalizeKey($title), 'lyrics_plain' => $lyrics]);
    }

    private function hold(ServiceSection $section, ContentHoldCheck $foundBy): void
    {
        app(HoldSectionForContentReview::class)($section->refresh(), 'Found by '.$foundBy->value, 'census register', $foundBy);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(ServiceSection $section): array
    {
        return $section->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? [];
    }
}
