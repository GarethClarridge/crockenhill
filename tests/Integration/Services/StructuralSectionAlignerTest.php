<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SectionAlignmentBaselineRestorer;
use App\Services\ChurchService\StructuralSectionAligner;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * @param  list<array{position: int, type: string, title: string, section_type: ServiceSectionType|null}>  $rows
     */
    private function createOosItems(ChurchService $churchService, array $rows): void
    {
        foreach ($rows as $row) {
            ChurchServiceItem::factory()->create(array_merge($row, ['church_service_id' => $churchService->id]));
        }
    }

    /**
     * @param  list<array{order: int, type: ServiceSectionType}>  $rows
     */
    private function createDetectedSections(MediaProcessingLog $log, array $rows): void
    {
        foreach ($rows as $row) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => $row['type']->value,
                'section_order' => $row['order'],
                'church_service_item_id' => null,
                'needs_manual_review' => false,
                'confidence' => 0.7,
                'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'ai_transcript'],
            ]);
        }
    }

    /**
     * A service whose OoS lists a generic "Andrew Talk.pptx" slide that never content-anchors.
     * The matched welcome pair strands the slide as an `item_gap` and the detected children's
     * talk as a `section_gap` at opposite ends of the monotonic alignment — exactly the shape
     * the positional fallback is meant to reconcile.
     *
     * @return array{0: Collection<int, ServiceSection>, 1: Collection<int, ChurchServiceItem>, 2: int, 3: int}
     */
    private function buildPositionalFallbackScenario(): array
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // The song establishes the first-song position so the later slide is suspected (weak)
        // to be a children's talk rather than notices.
        $this->createOosItems($churchService, [
            ['position' => 1, 'type' => 'songs', 'title' => 'Opening Song', 'section_type' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Welcome', 'section_type' => ServiceSectionType::Welcome],
            ['position' => 3, 'type' => 'presentations', 'title' => 'Andrew Talk.pptx', 'section_type' => null],
        ]);

        $this->createDetectedSections($log, [
            ['order' => 1, 'type' => ServiceSectionType::Song],
            ['order' => 2, 'type' => ServiceSectionType::ChildrensTalk],
            ['order' => 3, 'type' => ServiceSectionType::Welcome],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->orderBy('section_order')->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->orderBy('position')->get();

        $slideId = $items->firstOrFail(fn (ChurchServiceItem $i): bool => $i->title === 'Andrew Talk.pptx')->id;
        $talkId = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->section_type === ServiceSectionType::ChildrensTalk)->id;

        return [$sections, $items, $slideId, $talkId];
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
            'section_type' => ServiceSectionType::Welcome,
            'title' => 'Welcome',
            'metadata' => null,
        ]);

        // Detected section with matching type
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Welcome->value,
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
            'section_type' => ServiceSectionType::Prayer,
            'title' => 'Opening Prayer',
            'metadata' => null,
        ]);

        // Detected section is a notices — type clash, no lookahead match
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Notices->value,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        $this->assertGreaterThan(0, $mismatches);
    }

    #[Test]
    public function it_treats_an_extra_detected_section_with_no_oos_item_as_a_free_gap(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // No OoS items, but one detected structural section. The sparse slide-deck OoS legitimately
        // omits items (prayers, etc.), so an unlisted detected section is a free section gap rather
        // than an `unexpected_detected_section` mismatch.
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Notices->value,
            'church_service_item_id' => null,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        $mutated = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $section->id);

        $this->assertSame(0, $mismatches);
        $this->assertFalse($mutated->needs_manual_review);
        $this->assertNotContains('oos_structure_mismatch', $mutated->metadata['review_flags'] ?? []);
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
            'section_type' => ServiceSectionType::Song->value,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        // Both section and item are songs; neither participates in structural alignment
        $this->assertSame(0, $mismatches);
    }

    // ── Sermon / children's-talk filtering ───────────────────────────────────

    #[Test]
    public function it_does_not_mismatch_flag_or_penalise_a_sermon_section_with_no_oos_counterpart(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // A normal item/section pair that aligns cleanly.
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'section_type' => ServiceSectionType::Welcome,
            'title' => 'Welcome',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        // A confidently-detected sermon section the OoS has no line for.
        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 2,
            'church_service_item_id' => null,
            'needs_manual_review' => false,
            'confidence' => 0.9,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'ai_transcript'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->orderBy('section_order')->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        $mutatedSermon = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $sermon->id);

        $this->assertSame(0, $mismatches);
        $this->assertSame(ServiceSectionType::Sermon, $mutatedSermon->section_type);
        $this->assertFalse($mutatedSermon->needs_manual_review);
        $this->assertEqualsWithDelta(0.9, (float) $mutatedSermon->confidence, 0.001);
        $this->assertNotContains('oos_structure_mismatch', $mutatedSermon->metadata['review_flags'] ?? []);
    }

    #[Test]
    public function it_does_not_mismatch_flag_or_penalise_a_childrens_talk_section_with_no_oos_counterpart(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'section_type' => ServiceSectionType::Welcome,
            'title' => 'Welcome',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        $talk = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'section_order' => 2,
            'church_service_item_id' => null,
            'needs_manual_review' => false,
            'confidence' => 0.9,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'ai_transcript'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->orderBy('section_order')->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        $mutatedTalk = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $talk->id);

        $this->assertSame(0, $mismatches);
        $this->assertSame(ServiceSectionType::ChildrensTalk, $mutatedTalk->section_type);
        $this->assertFalse($mutatedTalk->needs_manual_review);
        $this->assertEqualsWithDelta(0.9, (float) $mutatedTalk->confidence, 0.001);
        $this->assertNotContains('oos_structure_mismatch', $mutatedTalk->metadata['review_flags'] ?? []);
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
            'section_type' => ServiceSectionType::Other->value,
            'church_service_item_id' => null,
            'metadata' => ['confidence_level' => 'medium', 'classification_mode' => 'audio_only'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        // align() mutates in-memory; check the object directly
        $this->aligner->align($sections, $items);

        // The section instance in the collection should be reclassified to BIBLE_READING
        $mutatedSection = $sections->first(fn (ServiceSection $s) => $s->id === $section->id);
        $this->assertSame(ServiceSectionType::BibleReading, $mutatedSection->section_type);
    }

    // ── Content-aware alignment: sparse OoS with unlisted prayers ─────────────

    #[Test]
    public function it_leaves_unlisted_prayers_as_free_gaps_against_a_sparse_oos(): void
    {
        // Modelled on service 790: the slide-deck OoS lists welcome, songs, a reading and
        // notices, but none of the four spoken prayers. The detected sections include those
        // prayers, which must become free gaps rather than desyncing the whole alignment.
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        $oos = [
            ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'section_type' => ServiceSectionType::Welcome],
            ['position' => 2, 'type' => 'songs', 'title' => 'Praise My Soul', 'section_type' => null],
            ['position' => 3, 'type' => 'bibles', 'title' => 'Psalm 148', 'section_type' => null],
            ['position' => 4, 'type' => 'custom', 'title' => 'Notices', 'section_type' => ServiceSectionType::Notices],
            ['position' => 5, 'type' => 'songs', 'title' => 'In Christ Alone', 'section_type' => null],
        ];
        foreach ($oos as $row) {
            ChurchServiceItem::factory()->create(array_merge($row, ['church_service_id' => $churchService->id]));
        }

        $detected = [
            ['order' => 1, 'type' => ServiceSectionType::Welcome],
            ['order' => 2, 'type' => ServiceSectionType::Prayer],
            ['order' => 3, 'type' => ServiceSectionType::Song],
            ['order' => 4, 'type' => ServiceSectionType::Prayer],
            ['order' => 5, 'type' => ServiceSectionType::BibleReading],
            ['order' => 6, 'type' => ServiceSectionType::Prayer],
            ['order' => 7, 'type' => ServiceSectionType::Notices],
            ['order' => 8, 'type' => ServiceSectionType::Prayer],
            ['order' => 9, 'type' => ServiceSectionType::Song],
        ];
        foreach ($detected as $row) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $log->id,
                'section_type' => $row['type']->value,
                'section_order' => $row['order'],
                'church_service_item_id' => null,
                'needs_manual_review' => false,
                'confidence' => 0.8,
                'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'ai_transcript'],
            ]);
        }

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->orderBy('section_order')->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->orderBy('position')->get();

        $mismatches = $this->aligner->align($sections, $items);

        // Only genuine type conflicts count — the unlisted prayers are free, so the total is zero.
        $this->assertSame(0, $mismatches);

        $prayers = $sections->filter(fn (ServiceSection $s): bool => $s->section_type === ServiceSectionType::Prayer);
        $this->assertCount(4, $prayers);
        foreach ($prayers as $prayer) {
            $this->assertFalse($prayer->needs_manual_review, 'Unlisted prayers must not be flagged.');
            $this->assertNotContains('oos_structure_mismatch', $prayer->metadata['review_flags'] ?? []);
            $this->assertNull($prayer->matched_item_id);
        }

        // The listed structural sections anchored to their items.
        $welcome = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->section_type === ServiceSectionType::Welcome);
        $reading = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->section_type === ServiceSectionType::BibleReading);
        $notices = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->section_type === ServiceSectionType::Notices);
        $this->assertNotNull($welcome->matched_item_id);
        $this->assertNotNull($reading->matched_item_id);
        $this->assertSame('Psalm 148', $reading->metadata['reading_reference'] ?? null);
        $this->assertNotNull($notices->matched_item_id);
    }

    #[Test]
    public function it_uses_content_similarity_to_disambiguate_two_same_type_items(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Opening Prayer',
        ]);
        $closingPrayer = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 2,
            'type' => 'custom',
            'title' => 'Closing Prayer',
        ]);

        // One detected prayer section whose transcript clearly matches the closing prayer.
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 1,
            'title' => null,
            'church_service_item_id' => null,
            'confidence' => 0.8,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'ai_transcript',
                'transcript' => 'As we close, let us pray our closing prayer together.',
            ],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->orderBy('position')->get();

        $this->aligner->align($sections, $items);

        $mutated = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $section->id);
        $this->assertSame($closingPrayer->id, $mutated->matched_item_id);
    }

    // ── Media interludes ──────────────────────────────────────────────────────

    #[Test]
    public function it_tags_a_block_at_an_oos_media_item_as_a_media_interlude(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // OoS lists a media item ("Bibles.mp4"); the projector video is not in the feed, so the
        // detected block was mis-classified as prayer with the speech-over-video transcript.
        $mediaItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'media',
            'section_type' => null,
            'title' => 'Bibles.mp4',
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 1,
            'church_service_item_id' => null,
            'needs_manual_review' => true,
            'confidence' => 0.5,
            'metadata' => [
                'classification_mode' => 'ai_transcript',
                'transcript' => "We're going to watch a video in just a moment about the work overseas.",
            ],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        $mutated = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $section->id);

        $this->assertSame(0, $mismatches);
        $this->assertSame(ServiceSectionType::Other, $mutated->section_type);
        $this->assertTrue(($mutated->metadata['media_interlude'] ?? false) === true);
        $this->assertSame('Bibles.mp4', $mutated->metadata['media_title'] ?? null);
        $this->assertSame($mediaItem->id, $mutated->matched_item_id);
        $this->assertFalse($mutated->needs_manual_review);
    }

    #[Test]
    public function it_does_not_tag_a_media_interlude_when_the_oos_has_no_media_item(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'custom',
            'section_type' => ServiceSectionType::Notices,
            'title' => 'Notices',
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Notices->value,
            'section_order' => 1,
            'church_service_item_id' => null,
            'metadata' => [
                'classification_mode' => 'ai_transcript',
                'transcript' => 'A few notices for the week ahead.',
            ],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $this->aligner->align($sections, $items);

        $mutated = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $section->id);

        $this->assertSame(ServiceSectionType::Notices, $mutated->section_type);
        $this->assertArrayNotHasKey('media_interlude', $mutated->metadata?->toArray() ?? []);
    }

    #[Test]
    public function it_does_not_tag_a_media_interlude_when_the_feature_is_disabled(): void
    {
        config()->set('media-processing.media_interludes.enabled', false);

        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'media',
            'section_type' => null,
            'title' => 'Bibles.mp4',
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 1,
            'church_service_item_id' => null,
            'metadata' => [
                'classification_mode' => 'ai_transcript',
                'transcript' => "We're going to watch a video in just a moment.",
            ],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $this->aligner->align($sections, $items);

        $mutated = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $section->id);

        $this->assertArrayNotHasKey('media_interlude', $mutated->metadata?->toArray() ?? []);
    }

    // ── Transition exclusion ──────────────────────────────────────────────────

    #[Test]
    public function it_excludes_tagged_transition_sections_from_the_walk(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // Without the is_transition exclusion this OTHER section would be reclassified to
        // BIBLE_READING and matched; tagging it a transition keeps it untouched.
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'bibles',
            'title' => 'Psalm 23',
        ]);

        $transition = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'church_service_item_id' => null,
            'matched_item_id' => null,
            'metadata' => ['classification_mode' => 'audio_only', 'is_transition' => true, 'transition_kind' => 'inter_song'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $mismatches = $this->aligner->align($sections, $items);

        $mutated = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $transition->id);

        $this->assertSame(0, $mismatches);
        $this->assertSame(ServiceSectionType::Other, $mutated->section_type);
        $this->assertNull($mutated->matched_item_id);
        $this->assertTrue(($mutated->metadata['is_transition'] ?? false) === true);
    }

    // ── F15: positional fallback for stranded presentation slides ─────────────

    #[Test]
    public function it_positionally_links_a_stranded_presentation_slide_to_a_single_unaligned_section(): void
    {
        [$sections, $items, $itemId, $sectionId] = $this->buildPositionalFallbackScenario();

        $mismatches = $this->aligner->align($sections, $items);

        $talk = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $sectionId);

        // Traceability only: the link must never count as a structural mismatch.
        $this->assertSame(0, $mismatches);
        $this->assertSame($itemId, $talk->matched_item_id);
        $this->assertSame($itemId, $talk->church_service_item_id);
        $this->assertTrue(($talk->metadata['oos_alignment']['positional_fallback'] ?? false) === true);
        $this->assertContains('presentation_positional_fallback', $talk->metadata['review_flags'] ?? []);
        $this->assertTrue($talk->needs_manual_review);

        // It must not change the type or boost confidence.
        $this->assertSame(ServiceSectionType::ChildrensTalk, $talk->section_type);
        $this->assertEqualsWithDelta(0.7, (float) $talk->confidence, 0.001);
    }

    #[Test]
    public function it_does_not_positionally_link_when_the_presentation_slide_already_aligned_in_the_dp(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        // A clearly-named slide content-anchors in the DP (strong → ChildrensTalk), so it is
        // never a remainder and the additive fallback has nothing to do.
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'presentations',
            'section_type' => null,
            'title' => "Children's Talk.pptx",
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'section_order' => 1,
            'church_service_item_id' => null,
            'confidence' => 0.7,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'ai_transcript'],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->get();

        $this->aligner->align($sections, $items);

        $mutated = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $section->id);

        // It matched through the normal DP path, not the fallback.
        $this->assertSame($item->id, $mutated->matched_item_id);
        $this->assertArrayNotHasKey('positional_fallback', $mutated->metadata['oos_alignment'] ?? []);
        $this->assertNotContains('presentation_positional_fallback', $mutated->metadata['review_flags'] ?? []);
    }

    #[Test]
    public function it_does_not_positionally_link_when_two_compatible_sections_are_unaligned(): void
    {
        $churchService = $this->makeService();
        $log = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $churchService->id]);

        $this->createOosItems($churchService, [
            ['position' => 1, 'type' => 'songs', 'title' => 'Opening Song', 'section_type' => null],
            ['position' => 2, 'type' => 'custom', 'title' => 'Welcome', 'section_type' => ServiceSectionType::Welcome],
            ['position' => 3, 'type' => 'presentations', 'title' => 'Andrew Talk.pptx', 'section_type' => null],
        ]);

        // Two children's-talk sections both fall out as section gaps → ambiguous, so no guess.
        $this->createDetectedSections($log, [
            ['order' => 1, 'type' => ServiceSectionType::Song],
            ['order' => 2, 'type' => ServiceSectionType::ChildrensTalk],
            ['order' => 3, 'type' => ServiceSectionType::ChildrensTalk],
            ['order' => 4, 'type' => ServiceSectionType::Welcome],
        ]);

        $sections = ServiceSection::where('media_processing_log_id', $log->id)->orderBy('section_order')->get();
        $items = ChurchServiceItem::where('church_service_id', $churchService->id)->orderBy('position')->get();
        $slideId = $items->firstOrFail(fn (ChurchServiceItem $i): bool => $i->title === 'Andrew Talk.pptx')->id;

        $this->aligner->align($sections, $items);

        $talks = $sections->filter(fn (ServiceSection $s): bool => $s->section_type === ServiceSectionType::ChildrensTalk);
        $this->assertCount(2, $talks);
        foreach ($talks as $talk) {
            $this->assertNotSame($slideId, $talk->matched_item_id);
            $this->assertNotContains('presentation_positional_fallback', $talk->metadata['review_flags'] ?? []);
        }
    }

    #[Test]
    public function it_clears_the_positional_fallback_flag_on_a_second_alignment_pass(): void
    {
        [$sections, $items, $itemId, $sectionId] = $this->buildPositionalFallbackScenario();

        $restorer = app(SectionAlignmentBaselineRestorer::class);

        $this->aligner->align($sections, $items);

        // Mimic the orchestrator's pre-pass baseline restore, then realign.
        foreach ($sections as $section) {
            $restorer->prepare($section);
        }

        $restored = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $sectionId);
        $this->assertNotContains('presentation_positional_fallback', $restored->metadata['review_flags'] ?? []);
        $this->assertNull($restored->matched_item_id);

        $this->aligner->align($sections, $items);

        $relinked = $sections->firstOrFail(fn (ServiceSection $s): bool => $s->id === $sectionId);
        $flags = $relinked->metadata['review_flags'] ?? [];
        $this->assertSame($itemId, $relinked->matched_item_id);
        $this->assertSame(
            1,
            count(array_filter($flags, fn (string $flag): bool => $flag === 'presentation_positional_fallback')),
            'The fallback flag must not accumulate across reruns.'
        );
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
