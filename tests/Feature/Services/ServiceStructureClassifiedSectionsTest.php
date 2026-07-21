<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\ServiceSectionConfidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 3 mapper contract: ServiceStructure::toClassifiedSections() emits
 * exactly the shape ServiceSectionSyncService::sync() accepts, and the
 * persisted rows drive SermonExtractionPlanResolver the same way the
 * heuristic path does today.
 */
class ServiceStructureClassifiedSectionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function mapped_sections_satisfy_the_service_section_validation_rules(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->coveringSegments($log);

        $classified = $this->structure()->toClassifiedSections($log, $this->transcript());

        $this->assertCount(4, $classified);

        foreach ($classified as $payload) {
            $validator = Validator::make(
                array_merge($payload, [
                    'media_processing_log_id' => $log->id,
                    'publication_status' => 'not_applicable',
                ]),
                ServiceSection::validationRules()
            );

            $this->assertFalse(
                $validator->fails(),
                'Mapped payload failed validation: '.json_encode($validator->errors()->toArray())
            );
        }
    }

    #[Test]
    public function source_segment_ids_are_resolved_by_time_overlap(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        [$first, $second, $third] = $this->coveringSegments($log);

        $classified = $this->structure()->toClassifiedSections($log);

        // Welcome (0–120 s) overlaps only the first segment (0–430 s)…
        $this->assertSame([$first->id], $classified[0]['source_segment_ids']);
        // …while the sermon (600–2200 s) spans the second and third.
        $this->assertSame([$second->id, $third->id], $classified[2]['source_segment_ids']);
    }

    #[Test]
    public function section_summaries_are_mapped_and_persisted(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->coveringSegments($log);

        $summary = 'The sermon explains God’s faithfulness from Joshua chapter one.';
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 600.0, 2200.0, summary: $summary),
        ], model: 'gpt-5');

        $classified = $structure->toClassifiedSections($log);

        $this->assertSame($summary, $classified[0]['summary']);
        $this->assertSame($summary, $classified[0]['metadata']['summary']);

        app(ServiceSectionSyncService::class)->sync($log, $classified);

        $section = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->firstOrFail();

        $this->assertSame($summary, $section->summary);
        $this->assertSame($summary, $section->metadata['summary']);
    }

    #[Test]
    public function a_section_with_no_overlapping_segment_gets_a_synthesised_one(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $classified = $this->structure()->toClassifiedSections($log);

        $this->assertNotSame([], $classified[0]['source_segment_ids']);
        $this->assertTrue($classified[0]['metadata']['synthesised_source_segment']);

        $segment = LivestreamSegment::query()->find($classified[0]['source_segment_ids'][0]);
        $this->assertInstanceOf(LivestreamSegment::class, $segment);
        $this->assertSame((float) $segment->start_time, $classified[0]['start_time']);
        $this->assertTrue((bool) ($segment->metadata['synthesised_from_structure'] ?? false));
    }

    #[Test]
    public function sync_persists_the_mapped_structure_and_the_resolver_extracts_the_sermon(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->coveringSegments($log);
        $items = $this->oosItems($log);

        $structure = $this->structure(
            sermonItemId: $items['sermon']->id,
            readingItemId: $items['reading']->id,
        );

        app(ServiceSectionSyncService::class)->sync($log, $structure->toClassifiedSections($log, $this->transcript()));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(4, $sections);
        $this->assertSame(
            ['welcome', 'bible_reading', 'sermon', 'song'],
            $sections->pluck('section_type')->map(fn ($type) => $type->value)->all()
        );

        $sermon = $sections->firstWhere('section_type', ServiceSectionType::Sermon);
        $this->assertSame($items['sermon']->id, $sermon->church_service_item_id);
        $this->assertSame('llm_structure', $sermon->metadata['classification_mode']);
        $this->assertFalse($sermon->needs_manual_review);
        $this->assertGreaterThanOrEqual(ServiceSectionConfidence::HIGH_THRESHOLD, (float) $sermon->confidence);

        $reading = $sections->firstWhere('section_type', ServiceSectionType::BibleReading);
        $this->assertSame('Joshua 1:1-9', $reading->metadata['reading_reference']);
        $this->assertSame('llm_structure', $reading->metadata['reading_reference_source']);

        $this->assertSame('Joshua 1:5-9', $sermon->metadata['sermon_reference']);
        $this->assertSame('llm_structure', $sermon->metadata['sermon_reference_source']);

        $song = $sections->firstWhere('section_type', ServiceSectionType::Song);
        $this->assertSame('Praise My Soul the King of Heaven', $song->metadata['song_title']);
        $this->assertSame(
            'Praise My Soul the King of Heaven',
            $song->metadata['song_title_hint'],
            'The LLM title feeds MatchSongsFromTranscript as its first-choice hint.'
        );

        // Golden path: the resolver prefers the high-confidence sermon section
        // and pairs the preceding reading, exactly as the heuristic path does.
        $plan = app(SermonExtractionPlanResolver::class)->resolve($log);

        $this->assertSame('service_sections', $plan['source']);
        $this->assertSame(420.0, $plan['segments'][0]['start_time'], 'The paired reading opens the extraction.');
        $this->assertSame(2200.0, $plan['segments'][array_key_last($plan['segments'])]['end_time']);
    }

    #[Test]
    public function a_low_confidence_sermon_falls_back_to_the_baseline_gate(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 600.0,
            'sermon_end_time' => 2200.0,
        ]);
        $this->coveringSegments($log);

        $structure = $this->structure(sermonConfidence: 0.6); // below HIGH_THRESHOLD, flagged for review

        app(ServiceSectionSyncService::class)->sync($log, $structure->toClassifiedSections($log));

        $plan = app(SermonExtractionPlanResolver::class)->resolve($log);

        $this->assertSame('baseline', $plan['mode']);
        $this->assertSame('processing_log', $plan['source']);
    }

    #[Test]
    public function filler_review_flags_remain_metadata_without_requiring_manual_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->coveringSegments($log);

        $flagged = $this->section('welcome', 0.0, 120.0)->withReviewFlags(['structure_low_confidence']);
        $structure = ServiceStructure::fromSections([
            $flagged,
            $this->section('sermon', 600.0, 2200.0),
        ], model: 'gpt-5');

        $classified = $structure->toClassifiedSections($log);

        $this->assertFalse($classified[0]['needs_manual_review']);
        $this->assertSame(['structure_low_confidence'], $classified[0]['metadata']['review_flags']);
        $this->assertSame('structure_low_confidence', $classified[0]['metadata']['review_reason']);

        $this->assertFalse($classified[1]['needs_manual_review']);
        $this->assertSame([], $classified[1]['metadata']['review_flags']);
        $this->assertArrayNotHasKey('review_reason', $classified[1]['metadata']);
    }

    #[Test]
    #[DataProvider('structuralReviewFlagMatrix')]
    public function structural_review_flags_only_require_action_when_their_consequences_are_actionable(
        string $sectionType,
        string $reviewFlag,
        bool $expectsManualReview,
    ): void {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $section = $this->section($sectionType, 0.0, 120.0)->withReviewFlags([$reviewFlag]);

        $classified = ServiceStructure::fromSections([$section])->toClassifiedSections($log);

        $this->assertSame($expectsManualReview, $classified[0]['needs_manual_review']);
        $this->assertSame([$reviewFlag], $classified[0]['metadata']['review_flags']);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function structuralReviewFlagMatrix(): array
    {
        $actionableTypes = ['childrens_talk', 'song', 'sermon', 'bible_reading'];
        $fillerTypes = ['other', 'notices', 'prayer', 'welcome'];
        $cases = [];

        foreach (['structure_low_confidence', 'structure_micro_section'] as $flag) {
            foreach ($actionableTypes as $type) {
                $cases["{$type} {$flag}"] = [$type, $flag, true];
            }

            foreach ($fillerTypes as $type) {
                $cases["{$type} {$flag}"] = [$type, $flag, false];
            }
        }

        foreach (array_merge($actionableTypes, $fillerTypes) as $type) {
            $cases["{$type} inversion"] = [$type, 'structure_oos_cross_type_inversion', false];
        }

        return $cases;
    }

    #[Test]
    public function inversion_plus_low_confidence_on_a_song_still_requires_manual_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $section = $this->section('song', 0.0, 120.0)->withReviewFlags([
            'structure_oos_cross_type_inversion',
            'structure_low_confidence',
        ]);

        $classified = ServiceStructure::fromSections([$section])->toClassifiedSections($log);

        $this->assertTrue($classified[0]['needs_manual_review']);
    }

    #[Test]
    public function resync_clears_detector_references_that_are_no_longer_present(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->coveringSegments($log);

        $initial = ServiceStructure::fromSections([
            $this->section('sermon', 600.0, 2200.0, sermonReference: 'Joshua 1:5-9'),
        ], model: 'gpt-5');
        $withoutReference = ServiceStructure::fromSections([
            $this->section('sermon', 600.0, 2200.0),
        ], model: 'gpt-5');

        $syncService = app(ServiceSectionSyncService::class);
        $syncService->sync($log, $initial->toClassifiedSections($log));
        $syncService->sync($log, $withoutReference->toClassifiedSections($log));

        $sermon = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->firstOrFail();

        $this->assertNull($sermon->metadata['sermon_reference'] ?? null);
        $this->assertNull($sermon->metadata['sermon_reference_source'] ?? null);
    }

    #[Test]
    public function snap_deltas_and_transcript_excerpts_ride_in_metadata(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->coveringSegments($log);

        $section = $this->section('sermon', 600.0, 2200.0)->withSnapDeltas(-2.5, 1.0);
        $structure = ServiceStructure::fromSections([$section], model: 'gpt-5');

        $classified = $structure->toClassifiedSections($log, $this->transcript());

        $this->assertSame(['start' => -2.5, 'end' => 1.0], $classified[0]['metadata']['snap_deltas']);
        $this->assertSame('section_excerpt', $classified[0]['metadata']['transcript_scope']);
        $this->assertStringContainsString('turn with me', $classified[0]['metadata']['transcript']);
        $this->assertSame('gpt-5', $classified[0]['metadata']['model']);
    }

    private function transcript(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 30.0, 'text' => 'Good morning everyone and a very warm welcome.'],
            ['start' => 420.0, 'end' => 590.0, 'text' => 'Our reading is from Joshua chapter one, verses one to nine.'],
            ['start' => 600.0, 'end' => 2200.0, 'text' => 'Please turn with me to the passage we have just read.'],
            ['start' => 2210.0, 'end' => 2400.0, 'text' => 'Praise my soul the King of heaven.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK);
    }

    /**
     * Three segments covering the timeline: 0–430 s, 430–1500 s, 1500–2430 s.
     *
     * @return array{0: LivestreamSegment, 1: LivestreamSegment, 2: LivestreamSegment}
     */
    private function coveringSegments(MediaProcessingLog $log): array
    {
        $bounds = [[0.0, 430.0], [430.0, 1500.0], [1500.0, 2430.0]];
        $segments = [];

        foreach ($bounds as $index => [$start, $end]) {
            $segments[] = LivestreamSegment::factory()->create([
                'media_processing_log_id' => $log->id,
                'segment_index' => $index,
                'segment_order' => $index,
                'start_time' => $start,
                'end_time' => $end,
                'duration' => $end - $start,
                'classification' => 'speech',
            ]);
        }

        return $segments;
    }

    /**
     * @return array{sermon: ChurchServiceItem, reading: ChurchServiceItem}
     */
    private function oosItems(MediaProcessingLog $log): array
    {
        $churchService = ChurchService::factory()->create();
        $log->forceFill(['church_service_id' => $churchService->id])->save();

        return [
            'reading' => ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'type' => 'bibles',
                'title' => 'Joshua 1:1-9',
                'position' => 2,
            ]),
            'sermon' => ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'type' => 'custom',
                'section_type' => ServiceSectionType::Sermon,
                'title' => 'The faithfulness of God',
                'position' => 3,
            ]),
        ];
    }

    private function structure(
        ?int $sermonItemId = null,
        ?int $readingItemId = null,
        float $sermonConfidence = 0.95,
    ): ServiceStructure {
        return ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0, oosItemId: $readingItemId, readingReference: 'Joshua 1:1-9'),
            $this->section('sermon', 600.0, 2200.0, oosItemId: $sermonItemId, confidence: $sermonConfidence, sermonReference: 'Joshua 1:5-9'),
            $this->section('song', 2210.0, 2400.0, songTitle: 'Praise My Soul the King of Heaven'),
        ], ['Detected cleanly.'], 'gpt-5');
    }

    private function section(
        string $type,
        float $start,
        float $end,
        ?int $oosItemId = null,
        float $confidence = 0.95,
        ?string $readingReference = null,
        ?string $songTitle = null,
        ?string $sermonReference = null,
        ?string $summary = null,
    ): ServiceStructureSection {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => $confidence,
            'oos_item_id' => $oosItemId,
            'reading_reference' => $readingReference,
            'song_title' => $songTitle,
            'sermon_reference' => $sermonReference,
            'summary' => $summary,
        ]);

        assert($section instanceof ServiceStructureSection);

        if ($confidence < ServiceSectionConfidence::HIGH_THRESHOLD) {
            return $section->withReviewFlags(['structure_low_confidence']);
        }

        return $section;
    }
}
