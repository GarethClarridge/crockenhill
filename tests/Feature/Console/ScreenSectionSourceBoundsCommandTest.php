<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\FlagSectionTruncatedBySource;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\SectionSourceBoundsScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScreenSectionSourceBoundsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_an_impossible_end_without_writing_anything_by_default(): void
    {
        [, $closing] = $this->runWithSectionPastSourceEnd();

        $this->artisan('historic-import:screen-section-bounds')
            ->expectsOutputToContain('past_source_end')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(2033.0, $closing->fresh()->end_time);
    }

    #[Test]
    public function it_clamps_the_end_to_the_measured_duration_and_holds_the_song(): void
    {
        [, $closing] = $this->runWithSectionPastSourceEnd();

        $this->artisan('historic-import:screen-section-bounds', ['--execute' => true])
            ->expectsOutputToContain('Clamped 1')
            ->assertSuccessful();

        $closing = $closing->fresh();

        $this->assertSame(2004.45, $closing->end_time);
        $this->assertSame(23.46, round($closing->duration, 2), 'The duration follows the clamped end.');
        $this->assertContains(FlagSectionTruncatedBySource::FLAG, $closing->metadata->reviewFlags);
        $this->assertTrue((bool) $closing->needs_manual_review);
    }

    #[Test]
    public function it_preserves_the_claimed_end_so_the_hold_survives_a_second_pass(): void
    {
        // The trap this exists for: after a clamp the section ends exactly at the
        // measured duration, so re-deriving the overrun from `end_time` reports
        // none and withdraws the hold the clamp just earned.
        [, $closing] = $this->runWithSectionPastSourceEnd();

        $this->artisan('historic-import:screen-section-bounds', ['--execute' => true])->assertSuccessful();

        $this->artisan('historic-import:screen-section-bounds', ['--execute' => true])
            ->expectsOutputToContain('Clamped 0, restored 0, held 0, released 0, unchanged 1')
            ->assertSuccessful();

        $closing = $closing->fresh();

        $this->assertSame(2004.45, $closing->end_time);
        $this->assertContains(FlagSectionTruncatedBySource::FLAG, $closing->metadata->reviewFlags);
        // JSON gives a whole float back as an int, which is why the screen reads
        // this through is_numeric and a cast rather than a float type check.
        $this->assertEquals(2033.0, $closing->metadata->raw['source_bounds']['recorded_end']);
    }

    #[Test]
    public function it_restores_the_claimed_end_and_releases_the_hold_when_the_source_grows(): void
    {
        [$run, $closing] = $this->runWithSectionPastSourceEnd();

        $this->artisan('historic-import:screen-section-bounds', ['--execute' => true])->assertSuccessful();

        // What a restage of a complete recording looks like from here.
        $run->forceFill(['duration' => 2100.0])->save();

        $this->artisan('historic-import:screen-section-bounds', ['--execute' => true])
            ->expectsOutputToContain('restored 1')
            ->assertSuccessful();

        $closing = $closing->fresh();

        $this->assertSame(2033.0, $closing->end_time);
        $this->assertArrayNotHasKey('source_bounds', $closing->metadata->raw);
        $this->assertNotContains(FlagSectionTruncatedBySource::FLAG, $closing->metadata->reviewFlags);
        $this->assertFalse((bool) $closing->needs_manual_review);
    }

    #[Test]
    public function it_does_not_hold_a_closing_filler_section_it_clamps(): void
    {
        // Fifteen of the corpus's sixteen overruns are a closing `other` at
        // not_applicable. The invented tail goes; there is no decision to queue.
        $run = MediaProcessingLog::factory()->livestream()->create(['duration' => 2370.34]);

        $closing = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 2370.0,
            'end_time' => 2400.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $this->artisan('historic-import:screen-section-bounds', ['--execute' => true])
            ->expectsOutputToContain('held 0')
            ->assertSuccessful();

        $closing = $closing->fresh();

        $this->assertSame(2370.34, $closing->end_time);
        $this->assertNotContains(FlagSectionTruncatedBySource::FLAG, $closing->metadata->reviewFlags);
        $this->assertFalse((bool) $closing->needs_manual_review);
    }

    #[Test]
    public function it_reports_a_run_with_no_measured_duration_as_unmeasurable_rather_than_passing_it(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create(['duration' => null]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 400.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $this->artisan('historic-import:screen-section-bounds', ['--execute' => true])
            ->expectsOutputToContain(SectionSourceBoundsScreen::DispositionUnmeasurable)
            ->expectsOutputToContain('backfill-source-durations')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_to_clamp_a_section_that_starts_past_the_end_of_its_media(): void
    {
        // No clamp can express this: the whole section is outside the recording,
        // so narrowing it would write an end before its own start.
        $run = MediaProcessingLog::factory()->livestream()->create(['duration' => 900.0]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 950.0,
            'end_time' => 1000.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $this->artisan('historic-import:screen-section-bounds', ['--execute' => true])
            ->expectsOutputToContain(SectionSourceBoundsScreen::DispositionBeyondSourceStart)
            ->expectsOutputToContain('1 section(s) start past the end of their own media')
            ->assertSuccessful();

        $this->assertSame(1000.0, ServiceSection::query()->sole()->end_time, 'It is left exactly as it was.');
    }

    #[Test]
    public function it_leaves_a_section_inside_its_media_untouched(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create(['duration' => 3600.0]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 400.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $this->artisan('historic-import:screen-section-bounds', ['--execute' => true])
            ->expectsOutputToContain('Clamped 0')
            ->assertSuccessful();

        $this->assertSame(400.0, $section->fresh()->end_time);
        $this->assertArrayNotHasKey('source_bounds', $section->fresh()->metadata->raw);
    }

    /**
     * Run 1068's shape: "O Church Arise" ends 28.55s past a 2004.45s recording.
     *
     * @return array{0: MediaProcessingLog, 1: ServiceSection}
     */
    private function runWithSectionPastSourceEnd(): array
    {
        $run = MediaProcessingLog::factory()->livestream()->create(['duration' => 2004.45]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 1979.99,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $closing = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'title' => 'O Church Arise',
            'start_time' => 1980.99,
            'end_time' => 2033.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        return [$run, $closing];
    }
}
