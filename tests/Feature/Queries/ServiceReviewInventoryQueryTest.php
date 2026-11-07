<?php

declare(strict_types=1);

namespace Tests\Feature\Queries;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Queries\ServiceReviewDashboardQuery;
use App\Queries\ServiceReviewInventoryQuery;
use App\Services\ChurchService\ReviewInventory\PendingApplication;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceReviewInventoryQueryTest extends TestCase
{
    use RefreshDatabase;

    private ServiceReviewInventoryQuery $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = app(ServiceReviewInventoryQuery::class);
    }

    #[Test]
    public function it_covers_exactly_the_sections_the_dashboard_counts(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $flagged = $this->section($run, ServiceSectionType::Sermon, ['needs_manual_review' => true, 'metadata' => ['review_flags' => ['sermon_text_predates_evidence']]]);
        $pending = $this->section($run, ServiceSectionType::BibleReading, ['publication_status' => ServiceSectionPublicationStatus::PendingApproval]);
        $talk = $this->section($run, ServiceSectionType::ShortTalk, ['metadata' => ['talk_type' => ['proposed' => 'testimony']]]);
        // The candidate query's legacy song clause: no catalogue song on the item, and no reason of its own.
        $legacySong = ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::Song,
            'church_service_item_id' => ChurchServiceItem::factory()->create(['song_id' => null])->id, 'start_time' => 900, 'end_time' => 1100]);
        $this->section($run, ServiceSectionType::Prayer);
        $superseded = MediaProcessingLog::factory()->livestream()->create(['superseded_at' => now()]);
        $this->section($superseded, ServiceSectionType::Sermon, ['needs_manual_review' => true]);

        $records = $this->inventory->records(PendingApplication::none())->all();
        $sectionIds = array_values(array_unique(array_column($records, 'section_id')));
        sort($sectionIds);

        $this->assertSame(app(ServiceReviewDashboardQuery::class)->reviewCandidateSectionCount(), count($sectionIds));
        $this->assertSame([$flagged->id, $pending->id, $talk->id, $legacySong->id], $sectionIds);
        $this->assertSame(ServiceReviewInventoryQuery::SONG_WITHOUT_CATALOGUE_ITEM, collect($records)->firstWhere('section_id', $legacySong->id)['reason']);
    }

    #[Test]
    public function a_manual_review_reason_is_split_into_its_flags_and_each_hold_into_its_check(): void
    {
        $section = $this->section(MediaProcessingLog::factory()->livestream()->create(), ServiceSectionType::Sermon, [
            'needs_manual_review' => true,
            'metadata' => [
                'review_flags' => ['transcript_repetition_suspect', 'content_defect_hold'],
                'content_holds' => [['found_by' => 'loop_screen'], ['found_by' => 'source_audio']],
            ],
        ]);

        $records = collect($this->inventory->recordsFor($section, PendingApplication::none()))->keyBy('detail');

        $this->assertSame(['transcript_repetition_suspect', 'content_hold:loop_screen', 'content_hold:source_audio'], $records->keys()->all());
        $this->assertSame(['automatic_repair', 'RA4'], [$records['transcript_repetition_suspect']['disposition'], $records['transcript_repetition_suspect']['stage']]);
        $this->assertSame(['automatic_repair', 'hold recheck'], [$records['content_hold:loop_screen']['disposition'], $records['content_hold:loop_screen']['stage']]);
        $this->assertSame('human_decision', $records['content_hold:source_audio']['disposition']);
        $this->assertTrue($records->every(fn (array $record): bool => $record['held'] === true));
    }

    #[Test]
    public function a_flag_the_review_policy_no_longer_forces_is_resolved_by_current_evidence(): void
    {
        $section = $this->section(MediaProcessingLog::factory()->livestream()->create(), ServiceSectionType::Sermon, [
            'needs_manual_review' => true,
            'metadata' => [
                'review_flags' => [ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION, ServiceStructureValidator::FLAG_MISSING_PREACHED_READING],
                'sermon_reference' => 'John 3:16',
            ],
        ]);

        $records = $this->inventory->recordsFor($section, PendingApplication::none());

        $this->assertSame(['resolved_by_current_evidence', 'resolved_by_current_evidence'], array_column($records, 'disposition'));
        $this->assertTrue(array_all($records, fn (array $record): bool => $record['clears_without_human_work']));
    }

    #[Test]
    public function a_flagless_manual_review_is_resolved_unless_the_song_publication_review_holds_it(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $unsupported = $this->section($run, ServiceSectionType::Sermon, ['needs_manual_review' => true]);
        $songHeld = $this->section($run, ServiceSectionType::Song, ['needs_manual_review' => true, 'metadata' => [
            'song_publication_review' => ['reasons' => [['kind' => 'short_song_clip', 'detail' => 'The clip is short.']]],
        ]]);

        $unsupportedRecord = $this->inventory->recordsFor($unsupported, PendingApplication::none())[0];
        $heldRecords = collect($this->inventory->recordsFor($songHeld, PendingApplication::none()))->keyBy('detail');

        $this->assertSame([null, 'resolved_by_current_evidence'], [$unsupportedRecord['detail'], $unsupportedRecord['disposition']]);
        $this->assertSame(['automatic_repair', 'RA3'], [$heldRecords[ServiceReviewInventoryQuery::SONG_PUBLICATION_HOLD]['disposition'], $heldRecords[ServiceReviewInventoryQuery::SONG_PUBLICATION_HOLD]['stage']]);
        $this->assertSame('song_publication_review', $heldRecords['short_song_clip']['reason']);
    }

    #[Test]
    public function reasons_a_ruling_reserves_stay_human_even_when_built_work_changes_the_section(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $talk = $this->section($run, ServiceSectionType::ShortTalk, [
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
            'metadata' => ['talk_type' => ['proposed' => 'testimony']],
        ]);
        $pending = new PendingApplication(sectionFixes: [$talk->id => PendingApplication::SECTION_RECUT]);

        $records = collect($this->inventory->recordsFor($talk, $pending))->keyBy('reason');

        $this->assertSame('human_decision', $records['talk_type_review']['disposition']);
        $this->assertStringStartsWith('T1/T2', $records['talk_type_review']['ruling']);
        $this->assertSame(['human_decision', 'publication approval'], [$records['pending_approval']['disposition'], $records['pending_approval']['stage']]);
    }

    #[Test]
    public function a_cut_dependent_reason_is_pending_application_where_the_replay_changes_the_section_and_unassessable_where_it_could_not_plan(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $changed = $this->song($run, 100, 300);
        $unplanned = $this->song($run, 400, 600);
        $untouched = $this->song($run, 700, 900);
        $pending = new PendingApplication(
            structureChanges: [$run->id => [[250.0, 320.0]]],
            unassessableSections: [$unplanned->id => true],
        );

        $route = fn (ServiceSection $section): array => $this->inventory->recordsFor($section, $pending)[0];

        $this->assertSame('resolved_by_pending_application', $route($changed)['disposition']);
        $this->assertSame(PendingApplication::RECOMPOSE, $route($changed)['fix']);
        $this->assertSame(['unassessable', PendingApplication::MISSING_EDGE_WORDS], [$route($unplanned)['disposition'], $route($unplanned)['missing_input']]);
        $this->assertSame('automatic_repair', $route($untouched)['disposition']);
    }

    #[Test]
    public function song_identity_reasons_are_not_taken_as_resolved_by_a_cut_change(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->section($run, ServiceSectionType::Song, ['needs_manual_review' => true, 'metadata' => ['review_flags' => ['unmatched_song_section']],
            'church_service_item_id' => ChurchServiceItem::factory()->create(['song_id' => Song::factory()->create()->id])->id]);
        $pending = new PendingApplication(sectionFixes: [$section->id => PendingApplication::SECTION_RECUT]);

        $record = $this->inventory->recordsFor($section, $pending)[0];

        $this->assertSame(['automatic_repair', 'RA4'], [$record['disposition'], $record['stage']]);
    }

    /**
     * 963 and 1066 (11-07): both analyses are current, and each contradiction is editorial
     * (an all-age Christmas talk; a sermon expounding 2 Chronicles 1 *and* Colossians 2).
     * Refreshing an analysis that is not stale settles nothing, so only an owed analysis
     * makes the reference an automatic RA2 refresh.
     */
    #[Test]
    public function a_contradicted_reference_is_refreshed_only_when_its_analysis_is_owed(): void
    {
        $flags = ['needs_manual_review' => true, 'metadata' => ['review_flags' => ['published_reference_contradicts_sermon']]];
        $current = $this->section(MediaProcessingLog::factory()->livestream()->create(['processing_metadata' => [
            'transcript_content' => ['hash' => 'a'], 'analysed_transcript' => ['hash' => 'a'],
        ]]), ServiceSectionType::Sermon, $flags);
        $owed = $this->section(MediaProcessingLog::factory()->livestream()->create(['processing_metadata' => [
            'transcript_content' => ['hash' => 'b'], 'analysed_transcript' => ['hash' => 'a'],
        ]]), ServiceSectionType::Sermon, $flags);

        $currentRecord = $this->inventory->recordsFor($current, PendingApplication::none())[0];
        $owedRecord = $this->inventory->recordsFor($owed, PendingApplication::none())[0];

        $this->assertSame(['human_decision', 'RA6'], [$currentRecord['disposition'], $currentRecord['stage']]);
        $this->assertStringContainsString('editorial', (string) $currentRecord['ruling']);
        $this->assertSame(['automatic_repair', 'RA2'], [$owedRecord['disposition'], $owedRecord['stage']]);
    }

    #[Test]
    public function an_unknown_flag_is_unassessable_until_it_has_a_route(): void
    {
        $section = $this->section(MediaProcessingLog::factory()->livestream()->create(), ServiceSectionType::Sermon, [
            'needs_manual_review' => true, 'metadata' => ['review_flags' => ['a_flag_nobody_routed']],
        ]);

        $record = $this->inventory->recordsFor($section, PendingApplication::none())[0];

        $this->assertSame(['unassessable', 'a route for this reason'], [$record['disposition'], $record['missing_input']]);
    }

    /** @param array<string, mixed> $attributes */
    private function section(MediaProcessingLog $run, ServiceSectionType $type, array $attributes = []): ServiceSection
    {
        static $start = 0;
        $start += 1000;

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => $type,
            'start_time' => $start,
            'end_time' => $start + 200,
            ...$attributes,
        ]);
    }

    private function song(MediaProcessingLog $run, float $start, float $end): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song,
            'church_service_item_id' => ChurchServiceItem::factory()->create(['song_id' => Song::factory()->create()->id])->id,
            'start_time' => $start,
            'end_time' => $end,
            'metadata' => ['song_publication_review' => ['reasons' => [['kind' => 'song_boundary_trailing_content', 'detail' => 'Trailing speech.']]]],
        ]);
    }
}
