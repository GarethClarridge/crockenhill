<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScrubLegacyOosAlignmentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_fossils_without_writing(): void
    {
        $section = $this->fossilSection();
        $expectedItemId = $section->expected_item_id;

        $this->artisan('service:scrub-legacy-alignment')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('No changes written')
            ->assertSuccessful();

        $section->refresh();
        $this->assertSame($expectedItemId, $section->expected_item_id);
        $this->assertNotNull($section->metadata?->oosAlignment);
    }

    public function test_apply_clears_fossil_fields_and_preserves_other_metadata(): void
    {
        $section = $this->fossilSection();

        $this->artisan('service:scrub-legacy-alignment --apply')
            ->expectsOutputToContain('APPLYING')
            ->assertSuccessful();

        $section->refresh();

        $this->assertNull($section->expected_item_id);
        $this->assertNull($section->matched_item_id);
        $this->assertNull($section->metadata?->oosAlignment);

        // Unrelated metadata keys survive the scrub.
        $this->assertSame('high', $section->metadata?->confidenceLevel);
        $this->assertSame('A warm welcome', $section->metadata?->summary);
    }

    public function test_superseded_runs_are_skipped_by_default(): void
    {
        $section = $this->fossilSection(superseded: true);
        $expectedItemId = $section->expected_item_id;

        $this->artisan('service:scrub-legacy-alignment --apply')
            ->expectsOutputToContain('No legacy alignment fossils found')
            ->assertSuccessful();

        $section->refresh();
        $this->assertSame($expectedItemId, $section->expected_item_id);
    }

    public function test_superseded_runs_included_with_flag(): void
    {
        $section = $this->fossilSection(superseded: true);

        $this->artisan('service:scrub-legacy-alignment --apply --include-superseded')
            ->assertSuccessful();

        $section->refresh();
        $this->assertNull($section->expected_item_id);
    }

    public function test_service_filter_limits_scope(): void
    {
        $target = $this->fossilSection();
        $other = $this->fossilSection();
        $otherExpectedItemId = $other->expected_item_id;

        $targetServiceId = $target->processingLog->church_service_id;

        $this->artisan('service:scrub-legacy-alignment --apply --service='.$targetServiceId)
            ->assertSuccessful();

        $this->assertNull($target->refresh()->expected_item_id);
        $this->assertSame($otherExpectedItemId, $other->refresh()->expected_item_id);
    }

    private function fossilSection(bool $superseded = false): ServiceSection
    {
        $service = ChurchService::factory()->create();
        $item = ChurchServiceItem::factory()->create(['church_service_id' => $service->id]);
        $pptxItem = ChurchServiceItem::factory()->create(['church_service_id' => $service->id]);

        $run = MediaProcessingLog::factory()->livestream()->failed()->create([
            'church_service_id' => $service->id,
            'superseded_at' => $superseded ? now() : null,
        ]);

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'expected_item_id' => $pptxItem->id,
            'matched_item_id' => null,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
                'summary' => 'A warm welcome',
                'oos_alignment' => [
                    'mismatch_reason' => 'oos_type_mismatch',
                    'expected_item_title' => 'Heidelberg-week16.pptx',
                    'expected_section_type' => 'other',
                ],
            ],
        ]);
    }
}
