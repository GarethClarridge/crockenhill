<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RederiveStructureReviewFlagsCommandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The class the marker-mismatch flag was mostly holding: a correctly-detected song
     * inside a chapter marker that labels the slot rather than naming the song.
     */
    #[Test]
    public function it_withdraws_a_flag_the_current_rules_would_not_raise(): void
    {
        [$service, $section] = $this->runWithMarkerMismatch('Only A Holy God', 'Opening worship');

        $this->artisan('services:rederive-structure-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $section->refresh();

        $this->assertSame([], $section->metadata?->reviewFlags);
        $this->assertFalse($section->needs_manual_review);
        $this->assertFalse($service->fresh()->needs_review);
    }

    #[Test]
    public function it_is_a_dry_run_by_default(): void
    {
        [, $section] = $this->runWithMarkerMismatch('Only A Holy God', 'Opening worship');

        $this->artisan('services:rederive-structure-review-flags')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertTrue($section->fresh()->needs_manual_review);
    }

    /**
     * A real disagreement — two catalogued hymns — survives the pass untouched.
     */
    #[Test]
    public function it_keeps_a_flag_the_current_rules_still_raise(): void
    {
        Song::factory()->create(['title' => 'What A Friend We Have In Jesus', 'canonical_key' => 'what a friend we have in jesus']);
        Song::factory()->create(['title' => 'Who Can Cheer The Heart Like Jesus', 'canonical_key' => 'who can cheer the heart like jesus']);

        [, $section] = $this->runWithMarkerMismatch(
            'What a Friend We Have in Jesus',
            'Who Can Cheer the Heart Like Jesus?',
        );

        $this->artisan('services:rederive-structure-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $section->refresh();

        $this->assertSame(
            [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
            $section->metadata?->reviewFlags,
        );
        $this->assertTrue($section->needs_manual_review);
    }

    /**
     * The pass re-derives only what the banked structure can answer for. A flag another
     * stage owns is carried across untouched even when it is the only thing holding the
     * section — withdrawing it would be asserting an answer this pass has no evidence for.
     */
    #[Test]
    public function it_leaves_flags_it_does_not_own_alone(): void
    {
        [, $section] = $this->runWithMarkerMismatch(
            'Only A Holy God',
            'Opening worship',
            extraFlags: ['childrens_talk_speaker_review'],
        );

        $this->artisan('services:rederive-structure-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $section->refresh();

        $this->assertSame(['childrens_talk_speaker_review'], $section->metadata?->reviewFlags);
        $this->assertTrue($section->needs_manual_review);
    }

    /**
     * The banked structure describes the rows it wrote. When it no longer does, something
     * else has edited the run and the pass must say so rather than re-derive against a
     * structure that is not this run's reading any more.
     */
    #[Test]
    public function it_skips_a_run_whose_sections_no_longer_match_its_structure(): void
    {
        [, $section] = $this->runWithMarkerMismatch('Only A Holy God', 'Opening worship');

        $section->forceFill(['start_time' => 900.0, 'end_time' => 1200.0, 'duration' => 300.0])->saveQuietly();

        $this->artisan('services:rederive-structure-review-flags', ['--execute' => true])
            ->expectsOutputToContain('drifted from the banked structure')
            ->assertSuccessful();

        $this->assertTrue($section->fresh()->needs_manual_review);
    }

    /**
     * Superseded runs are invisible to the operator, so re-deriving them would report work
     * nobody can see — the same filter the review dashboard already applies.
     */
    #[Test]
    public function it_ignores_superseded_runs(): void
    {
        [, $section] = $this->runWithMarkerMismatch('Only A Holy God', 'Opening worship');

        $section->processingLog->forceFill(['superseded_at' => now()])->saveQuietly();

        $this->artisan('services:rederive-structure-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $this->assertTrue($section->fresh()->needs_manual_review);
    }

    /**
     * A flag whose raise site has been deleted is withdrawn wherever it sits — including on
     * a heuristic-era run that banked no structure at all, which is where all three live
     * `reading_reference_conflict` sections were found.
     */
    #[Test]
    public function it_withdraws_a_retired_flag_from_a_run_with_no_banked_structure(): void
    {
        $service = ChurchService::factory()->create(['needs_review' => true]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'processing_metadata' => [],
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::BibleReading,
            'section_order' => 1,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => ['reading_reference_conflict']],
        ]);

        $this->artisan('services:rederive-structure-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $section->refresh();

        $this->assertSame([], $section->metadata?->reviewFlags);
        $this->assertFalse($section->needs_manual_review);
        $this->assertFalse($service->fresh()->needs_review);
    }

    /**
     * A retired flag alongside a live one withdraws only itself: the live flag is still a
     * question somebody has to answer.
     */
    #[Test]
    public function a_retired_flag_alongside_a_live_one_does_not_release_the_section(): void
    {
        $service = ChurchService::factory()->create(['needs_review' => true]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'processing_metadata' => [],
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'section_order' => 1,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => ['heuristic_demotion', 'oos_structure_mismatch']],
        ]);

        $this->artisan('services:rederive-structure-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $section->refresh();

        $this->assertSame(['oos_structure_mismatch'], $section->metadata?->reviewFlags);
        $this->assertTrue($section->needs_manual_review);
    }

    /**
     * @param  list<string>  $extraFlags
     * @return array{0: ChurchService, 1: ServiceSection}
     */
    private function runWithMarkerMismatch(
        string $songTitle,
        string $markerTitle,
        array $extraFlags = [],
    ): array {
        $service = ChurchService::factory()->create(['needs_review' => true]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'duration' => 3600.0,
            'processing_metadata' => [
                'service_structure' => [
                    'sections' => [[
                        'type' => 'song',
                        'start_time' => 120.0,
                        'end_time' => 420.0,
                        'confidence' => 0.98,
                        'song_title' => $songTitle,
                        'review_flags' => [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
                    ]],
                    'chapter_markers' => [[
                        'title' => $markerTitle,
                        'start_time' => 120.0,
                        'end_time' => 420.0,
                    ]],
                ],
            ],
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'start_time' => 120.0,
            'end_time' => 420.0,
            'duration' => 300.0,
            'confidence' => 0.98,
            'needs_manual_review' => true,
            'metadata' => [
                'song_title' => $songTitle,
                'review_reason' => ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH,
                'review_flags' => [
                    ...$extraFlags,
                    ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH,
                ],
            ],
        ]);

        return [$service, $section];
    }
}
