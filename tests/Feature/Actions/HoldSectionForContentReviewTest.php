<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ServiceReview\ConfirmServiceSection;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Models\User;
use App\Services\ChurchService\SectionReviewFlagRecalculator;
use App\Services\Import\HistoricReleaseReviewHolds;
use App\Services\Song\UnmatchedSongReviewApplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HoldSectionForContentReviewTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Saved text repeats a sentence the audio does not';

    private const EVIDENCE = 'storage/scratch/correctness-20260911-followup-register.json';

    #[Test]
    public function it_holds_a_sermon_section_and_records_why(): void
    {
        $section = $this->section(ServiceSectionType::Sermon);

        self::assertTrue(app(HoldSectionForContentReview::class)($section, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement));

        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];

        self::assertTrue($section->needs_manual_review);
        self::assertContains(HoldSectionForContentReview::FLAG, $metadata['review_flags'] ?? []);
        self::assertSame(self::REASON, $metadata[HoldSectionForContentReview::METADATA_KEY][0]['reason'] ?? null);
        self::assertSame(self::EVIDENCE, $metadata[HoldSectionForContentReview::METADATA_KEY][0]['evidence'] ?? null);
    }

    #[Test]
    public function it_records_the_check_that_found_the_hold_and_the_content_it_was_found_on(): void
    {
        Storage::fake('local');
        config()->set('media-processing.storage.transcript_disk', 'local');
        Storage::disk('local')->put('service-transcripts/run.json', '{"cues":[]}');
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_metadata' => ['service_transcript_path' => 'service-transcripts/run.json'],
        ]);
        $section = $this->section(ServiceSectionType::Sermon, run: $run);
        $section->forceFill(['start_time' => 600.0, 'end_time' => 1800.0, 'duration' => 1200.0])->save();

        app(HoldSectionForContentReview::class)($section, self::REASON, self::EVIDENCE, ContentHoldCheck::LoopScreen);

        $record = $section->refresh()->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY][0] ?? [];

        self::assertSame('loop_screen', $record['found_by'] ?? null);
        self::assertEquals(600.0, $record['start_time'] ?? null);
        self::assertEquals(1800.0, $record['end_time'] ?? null);
        self::assertArrayHasKey('church_service_item_id', $record);
        self::assertSame(hash('sha256', '{"cues":[]}'), $record['transcript_sha256'] ?? null);
    }

    #[Test]
    public function it_holds_songs_and_childrens_talks_too(): void
    {
        foreach ([ServiceSectionType::Song, ServiceSectionType::ShortTalk] as $type) {
            $section = $this->section($type);

            app(HoldSectionForContentReview::class)($section, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);

            self::assertTrue($section->refresh()->needs_manual_review, "A {$type->value} is held.");
        }
    }

    #[Test]
    public function it_keeps_existing_flags_and_does_not_rewrite_an_identical_hold(): void
    {
        $section = $this->section(ServiceSectionType::Sermon, ['structure_missing_preached_reading']);
        $hold = app(HoldSectionForContentReview::class);

        $hold($section, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);

        self::assertFalse($hold($section->refresh(), self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement));

        $metadata = $section->refresh()->metadata?->toArray() ?? [];

        self::assertSame(['structure_missing_preached_reading', HoldSectionForContentReview::FLAG], $metadata['review_flags']);
        self::assertCount(1, $metadata[HoldSectionForContentReview::METADATA_KEY]);
    }

    #[Test]
    public function it_records_a_second_distinct_reason(): void
    {
        $section = $this->section(ServiceSectionType::Song);
        $hold = app(HoldSectionForContentReview::class);

        $hold($section, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);

        self::assertTrue($hold($section->refresh(), 'Clip opens on a prayer', 'plan §3.2', ContentHoldCheck::Judgement));
        self::assertCount(2, $section->refresh()->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? []);
    }

    /**
     * A hold on a reading refuses nothing at release unless it questions the sermon
     * span, so accepting one would record containment that does not exist.
     */
    #[Test]
    public function it_refuses_a_section_the_release_gate_would_not_refuse_on(): void
    {
        $section = $this->section(ServiceSectionType::BibleReading);

        try {
            app(HoldSectionForContentReview::class)($section, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);
            self::fail('A bible reading was held.');
        } catch (InvalidArgumentException) {
            self::assertFalse($section->refresh()->needs_manual_review);
        }
    }

    #[Test]
    public function it_refuses_a_hold_nobody_can_explain(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(HoldSectionForContentReview::class)($this->section(ServiceSectionType::Sermon), self::REASON, '  ', ContentHoldCheck::Judgement);
    }

    #[Test]
    public function the_flag_recompute_keeps_the_hold(): void
    {
        $section = $this->section(ServiceSectionType::Song);
        app(HoldSectionForContentReview::class)($section, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);

        self::assertSame([], app(SectionReviewFlagRecalculator::class)->updatesFor($section->refresh()));
    }

    /**
     * The retype settles whether a section is a song; it used to force the review
     * column false, which would have withdrawn an unrelated content hold.
     */
    #[Test]
    public function the_spoken_announcement_retypes_keep_the_hold(): void
    {
        $recomputed = $this->spokenUnmatchedSong();
        app(HoldSectionForContentReview::class)($recomputed, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);

        $updates = app(SectionReviewFlagRecalculator::class)->updatesFor($recomputed->refresh());

        self::assertSame(ServiceSectionType::Other, $updates['section_type']);
        self::assertTrue($updates['needs_manual_review']);

        $matched = $this->spokenUnmatchedSong();
        app(HoldSectionForContentReview::class)($matched, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);

        $sections = ServiceSection::query()->whereKey($matched->id)->get();
        app(UnmatchedSongReviewApplicator::class)->apply($sections, []);

        self::assertSame(ServiceSectionType::Other, $sections->first()->section_type);
        self::assertTrue($sections->first()->needs_manual_review);
    }

    #[Test]
    public function operator_confirmation_releases_the_hold_and_keeps_its_history(): void
    {
        $section = $this->section(ServiceSectionType::Sermon);
        app(HoldSectionForContentReview::class)($section, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);

        app(ConfirmServiceSection::class)->execute($section->refresh(), User::factory()->create()->id);

        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];

        self::assertFalse($section->needs_manual_review);
        self::assertNotContains(HoldSectionForContentReview::FLAG, $metadata['review_flags'] ?? []);
        self::assertCount(1, $metadata[HoldSectionForContentReview::METADATA_KEY] ?? []);
        self::assertSame([], app(SectionReviewFlagRecalculator::class)->updatesFor($section));
    }

    #[Test]
    public function the_release_gate_refuses_the_held_sermon_and_song_video(): void
    {
        $sermon = Sermon::factory()->create();
        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['sermon_id' => $sermon->id]);
        $sermonSection = $this->section(ServiceSectionType::Sermon, run: $run);
        $songSection = $this->section(ServiceSectionType::Song, run: $run);
        $songVideo = SongVideo::factory()->quarantined()->create(['service_section_id' => $songSection->id]);

        $gate = app(HistoricReleaseReviewHolds::class);

        self::assertSame([], $gate->assess([$sermon], [$songVideo]));

        app(HoldSectionForContentReview::class)($sermonSection, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);
        app(HoldSectionForContentReview::class)($songSection, self::REASON, self::EVIDENCE, ContentHoldCheck::Judgement);

        $refusals = implode("\n", $gate->assess([$sermon], [$songVideo]));

        self::assertStringContainsString("Sermon {$sermon->id} is held for review", $refusals);
        self::assertStringContainsString("Song video {$songVideo->id} is held for review", $refusals);
    }

    /**
     * @param  list<string>  $flags
     */
    private function section(
        ServiceSectionType $type,
        array $flags = [],
        ?MediaProcessingLog $run = null,
    ): ServiceSection {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => ($run ?? MediaProcessingLog::factory()->livestream()->completed()->create())->id,
            'section_type' => $type,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => $flags],
        ]);
    }

    private function spokenUnmatchedSong(): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream()->create()->id,
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched,
            'needs_manual_review' => true,
            'metadata' => [
                'detected_segment_class' => 'speech',
                'review_flags' => ['unmatched_song_section'],
                'review_reason' => 'unmatched_song_section',
            ],
        ]);
    }
}
