<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\StructuralSectionAligner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructuralSectionAlignerTest extends TestCase
{
    use RefreshDatabase;

    private StructuralSectionAligner $aligner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aligner = app(StructuralSectionAligner::class);
    }

    private function makeService(): ChurchService
    {
        return ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning->value,
        ]);
    }

    // ── Happy path: matching types ────────────────────────────────────────────

    #[Test]
    public function it_returns_zero_mismatches_when_all_structural_sections_align(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // OoS item
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Welcome',
            'metadata' => ['section_type' => 'welcome'],
        ]);

        // Detected section with matching type
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::WELCOME->value,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        $this->assertSame(0, $mismatches);
    }

    // ── Mismatch detection ────────────────────────────────────────────────────

    #[Test]
    public function it_counts_one_mismatch_when_section_and_item_types_differ(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // OoS item is a prayer
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
            'metadata' => ['section_type' => 'prayer'],
        ]);

        // Detected section is a notices — type clash, no lookahead match
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::NOTICES->value,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        $this->assertGreaterThan(0, $mismatches);
    }

    #[Test]
    public function it_counts_a_mismatch_when_there_is_an_extra_detected_section_with_no_oos_item(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // No OoS items, but one detected structural section
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::NOTICES->value,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        $this->assertSame(1, $mismatches);
    }

    // ── Song section filtering ────────────────────────────────────────────────

    #[Test]
    public function it_excludes_song_sections_from_structural_alignment(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // OoS song item
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
        ]);

        // Detected song section — should be excluded from structural alignment
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::SONG->value,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        // Both section and item are songs; neither participates in structural alignment
        $this->assertSame(0, $mismatches);
    }

    // ── OoS reclassification ──────────────────────────────────────────────────

    #[Test]
    public function it_reclassifies_an_other_section_to_match_the_oos_item_type(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // OoS item is a bible reading
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'bibles',
            'title' => 'John 3:16',
        ]);

        // Detected section classified as OTHER (audio-only fallback)
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::OTHER->value,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'medium', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        // align() mutates in-memory; check the object directly
        $this->aligner->align($sections, $items);

        // The section instance in the collection should be reclassified to BIBLE_READING
        $mutatedSection = $sections->first(fn (ServiceSection $s) => $s->id === $section->id);
        $this->assertSame(ServiceSectionType::BIBLE_READING, $mutatedSection->section_type);
    }

    // ── Empty inputs ──────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_zero_mismatches_when_both_collections_are_empty(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::whereNull('church_service_id')->get();

        $mismatches = $this->aligner->align($sections, $items);

        $this->assertSame(0, $mismatches);
    }
}
