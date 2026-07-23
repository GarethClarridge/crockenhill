<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecomputeSectionReviewFlagsCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_is_a_dry_run_by_default(): void
    {
        [$service, $staleSection] = $this->serviceWithStaleFillerFlag();

        $this->artisan('services:recompute-section-review-flags')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertTrue($staleSection->fresh()->needs_manual_review);
        $this->assertTrue($service->fresh()->needs_review);
    }

    #[Test]
    public function it_clears_stale_flags_and_resyncs_the_service_on_execute(): void
    {
        [$service, $staleSection] = $this->serviceWithStaleFillerFlag();

        $this->artisan('services:recompute-section-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $this->assertFalse($staleSection->fresh()->needs_manual_review);
        $this->assertFalse($service->fresh()->needs_review);
    }

    #[Test]
    public function it_clears_a_pure_stale_boolean_with_no_review_flags(): void
    {
        $service = ChurchService::factory()->create(['needs_review' => true]);
        $run = $this->livestreamRun($service);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => []],
        ]);

        $this->artisan('services:recompute-section-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $this->assertFalse($section->fresh()->needs_manual_review);
        $this->assertFalse($service->fresh()->needs_review);
    }

    #[Test]
    public function it_confirms_an_inferred_song_match_above_the_writeback_threshold(): void
    {
        $service = ChurchService::factory()->create(['needs_review' => false]);
        $run = $this->livestreamRun($service);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Inferred,
            'needs_manual_review' => false,
            'metadata' => [
                'review_flags' => [],
                'transcript_song_match' => ['confidence' => 0.95],
            ],
        ]);

        $this->artisan('services:recompute-section-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $this->assertSame(ServiceSectionSongMatchType::Confirmed, $section->fresh()->song_match_type);
    }

    #[Test]
    public function it_strips_a_stale_service_level_structure_trigger_when_no_section_needs_review(): void
    {
        $service = ChurchService::factory()->create([
            'needs_review' => true,
            'import_metadata' => ['review_triggers' => ['oos_structure_mismatch']],
        ]);
        $run = $this->livestreamRun($service);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Notices,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => ['oos_structure_mismatch']],
        ]);

        $this->artisan('services:recompute-section-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $this->assertFalse($section->fresh()->needs_manual_review);
        $this->assertFalse($service->fresh()->needs_review);
        $this->assertEmpty($service->fresh()->import_metadata?->reviewTriggers ?? []);
    }

    #[Test]
    public function it_leaves_a_genuinely_actionable_section_flagged(): void
    {
        $service = ChurchService::factory()->create(['needs_review' => true]);
        $run = $this->livestreamRun($service);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => ['structure_low_confidence']],
        ]);

        $this->artisan('services:recompute-section-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $this->assertTrue($section->fresh()->needs_manual_review);
        $this->assertTrue($service->fresh()->needs_review);
    }

    #[Test]
    public function it_can_be_scoped_to_specific_services(): void
    {
        [$target, $targetSection] = $this->serviceWithStaleFillerFlag();
        [$other, $otherSection] = $this->serviceWithStaleFillerFlag();

        $this->artisan('services:recompute-section-review-flags', [
            '--execute' => true,
            '--service' => [$target->id],
        ])->assertSuccessful();

        $this->assertFalse($targetSection->fresh()->needs_manual_review);
        $this->assertTrue($otherSection->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_reclassifies_a_speech_detected_unmatched_song_as_other(): void
    {
        $service = ChurchService::factory()->create(['needs_review' => true]);
        $run = $this->livestreamRun($service);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched,
            'needs_manual_review' => true,
            'metadata' => [
                'detected_segment_class' => 'speech',
                'review_flags' => ['unmatched_song_section'],
                'review_reason' => 'unmatched_song_section',
            ],
        ]);

        $this->artisan('services:recompute-section-review-flags', ['--execute' => true])
            ->assertSuccessful();

        $fresh = $section->fresh();
        $this->assertSame(ServiceSectionType::Other, $fresh->section_type);
        $this->assertNull($fresh->song_match_type);
        $this->assertFalse($fresh->needs_manual_review);
        $this->assertNotContains('unmatched_song_section', $fresh->metadata?->toArray()['review_flags'] ?? []);
        $this->assertFalse($service->fresh()->needs_review);
    }

    /**
     * @return array{0: ChurchService, 1: ServiceSection}
     */
    private function serviceWithStaleFillerFlag(): array
    {
        $service = ChurchService::factory()->create(['needs_review' => true]);
        $run = $this->livestreamRun($service);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Notices,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => ['oos_structure_mismatch']],
        ]);

        return [$service, $section];
    }

    private function livestreamRun(ChurchService $service): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
        ]);
    }
}
