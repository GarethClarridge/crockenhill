<?php

declare(strict_types=1);

namespace Tests\Feature\DetectorEvaluation;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\DetectorEvaluation\SoundStageFlagWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoundStageFlagWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dry_run_writes_nothing_but_reports_what_it_would_do(): void
    {
        $section = $this->section(ServiceSectionType::Other, held: false);

        $report = $this->writer()->apply($this->finding($section), execute: false);

        $this->assertFalse($report['executed']);
        $this->assertSame(1, $report['sections_written']);
        $this->assertSame(1, $report['sections_newly_held']);

        $section->refresh();
        $this->assertFalse((bool) $section->needs_manual_review);
        $this->assertSame([], $section->metadata?->toArray()['review_flags'] ?? []);
    }

    public function test_apply_adds_the_flag_and_raises_the_hold(): void
    {
        $section = $this->section(ServiceSectionType::Other, held: false);

        $report = $this->writer()->apply($this->finding($section), execute: true);

        $this->assertTrue($report['executed']);
        $this->assertSame(1, $report['sections_written']);

        $section->refresh();
        $this->assertTrue((bool) $section->needs_manual_review);
        $this->assertContains(
            ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG,
            $section->metadata?->toArray()['review_flags'] ?? [],
        );
    }

    /**
     * Adding coverage must not remove containment.
     *
     * A section can be held for a cause that is not expressed as a review flag,
     * and recomputing `needs_manual_review` from the flags alone would withdraw
     * that hold silently. The writer raises or leaves alone, never lowers.
     */
    public function test_it_never_withdraws_an_existing_hold(): void
    {
        // A prayer with no flags: the policy alone would not hold it.
        $section = $this->section(ServiceSectionType::Prayer, held: true);

        $this->writer()->apply($this->finding($section), execute: true);

        $section->refresh();
        $this->assertTrue(
            (bool) $section->needs_manual_review,
            'The hold predates this pass and must survive it.'
        );
    }

    public function test_it_preserves_flags_already_on_the_section(): void
    {
        $section = $this->section(ServiceSectionType::Other, held: false, flags: ['content_defect_hold']);

        $this->writer()->apply($this->finding($section), execute: true);

        $section->refresh();
        $flags = $section->metadata?->toArray()['review_flags'] ?? [];

        $this->assertContains('content_defect_hold', $flags);
        $this->assertContains(ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG, $flags);
    }

    public function test_a_section_that_already_carries_the_flag_is_not_rewritten(): void
    {
        $section = $this->section(
            ServiceSectionType::Other,
            held: true,
            flags: [ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG],
        );

        $report = $this->writer()->apply($this->finding($section), execute: true);

        $this->assertSame(0, $report['sections_written']);
        $this->assertSame(1, $report['sections_already_correct']);
    }

    /**
     * The six findings that have no stored row are proposed sections, and
     * inserting one shifts every later section into a different signature
     * comparison, which deletes its extracted media. They are reported, never
     * written.
     */
    public function test_a_finding_with_no_stored_section_is_deferred(): void
    {
        $run = MediaProcessingLog::factory()->create();

        $report = $this->writer()->apply([[
            'run' => (int) $run->id,
            'index' => 7,
            'flags' => [ServiceStructureValidator::FLAG_UNIDENTIFIED_SINGING],
            'start' => 10.0,
            'end' => 20.0,
        ]], execute: true);

        $this->assertSame(0, $report['sections_written']);
        $this->assertCount(1, $report['deferred_inserts']);
    }

    /**
     * Position alone is not identity. If the stored section at that position
     * sits somewhere else entirely, writing would flag the wrong section.
     */
    public function test_it_refuses_a_section_whose_bounds_disagree(): void
    {
        $section = $this->section(ServiceSectionType::Other, held: false);

        $report = $this->writer()->apply([[
            'run' => (int) $section->media_processing_log_id,
            'index' => 0,
            'flags' => [ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG],
            'start' => (float) $section->start_time + 500.0,
            'end' => (float) $section->end_time + 500.0,
        ]], execute: true);

        $this->assertSame(0, $report['sections_written']);
        $this->assertCount(1, $report['bounds_mismatch']);

        $section->refresh();
        $this->assertSame([], $section->metadata?->toArray()['review_flags'] ?? []);
    }

    /**
     * @return list<array{run: int, index: int, flags: list<string>, start: float, end: float}>
     */
    private function finding(ServiceSection $section): array
    {
        return [[
            'run' => (int) $section->media_processing_log_id,
            'index' => 0,
            'flags' => [ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG],
            'start' => (float) $section->start_time,
            'end' => (float) $section->end_time,
        ]];
    }

    /**
     * @param  list<string>  $flags
     */
    private function section(ServiceSectionType $type, bool $held, array $flags = []): ServiceSection
    {
        $run = MediaProcessingLog::factory()->create();

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => $type,
            'section_order' => 0,
            'needs_manual_review' => $held,
            'metadata' => ['review_flags' => $flags],
        ]);
    }

    private function writer(): SoundStageFlagWriter
    {
        return app(SoundStageFlagWriter::class);
    }
}
