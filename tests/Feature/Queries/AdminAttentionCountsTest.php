<?php

declare(strict_types=1);

namespace Tests\Feature\Queries;

use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Queries\AdminAttentionCounts;
use App\Support\ServiceSectionConfidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminAttentionCountsTest extends TestCase
{
    use RefreshDatabase;

    private AdminAttentionCounts $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = app(AdminAttentionCounts::class);
    }

    #[Test]
    public function it_returns_all_zeros_when_nothing_needs_attention(): void
    {
        $counts = $this->query->counts();

        $this->assertSame([
            'pending_emails' => 0,
            'awaiting_segment_runs' => 0,
            'flagged_sections' => 0,
            'pending_merges' => 0,
            'services_needing_review' => 0,
        ], $counts);
        $this->assertSame(0, $this->query->total($counts));
    }

    #[Test]
    public function it_counts_pending_and_failed_inbound_emails_only(): void
    {
        InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending->value]);
        InboundEmail::factory()->create(['status' => InboundEmailStatus::Failed->value]);
        InboundEmail::factory()->create(['status' => InboundEmailStatus::Processed->value]);
        InboundEmail::factory()->create(['status' => InboundEmailStatus::Rejected->value]);

        $this->assertSame(2, $this->query->counts()['pending_emails']);
    }

    #[Test]
    public function it_counts_runs_awaiting_manual_sermon_review(): void
    {
        MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create();
        MediaProcessingLog::factory()->livestream()->completed()->create();

        $this->assertSame(1, $this->query->counts()['awaiting_segment_runs']);
    }

    #[Test]
    public function it_counts_sections_flagged_for_low_confidence_only_not_just_pending_approval(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        // A review candidate for low confidence alone — a raw pending_approval
        // count would miss this section entirely (contract C1).
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'needs_manual_review' => false,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'confidence' => ServiceSectionConfidence::HIGH_THRESHOLD - 0.1,
        ]);

        $this->assertSame(1, $this->query->counts()['flagged_sections']);
    }

    #[Test]
    public function it_counts_pending_merges_and_services_needing_review(): void
    {
        ChurchService::factory()->create([
            'date' => '2026-05-31',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);
        ChurchService::factory()->create([
            'date' => '2026-05-31',
            'service' => SermonService::Evening,
            'needs_review' => false,
            'pending_structure_merge_source' => 'openlp',
        ]);

        $counts = $this->query->counts();

        $this->assertSame(1, $counts['pending_merges']);
        $this->assertSame(1, $counts['services_needing_review']);
    }

    #[Test]
    public function flagged_sections_count_is_zero_when_section_publishing_is_disabled(): void
    {
        config(['media-processing.section_publishing.enabled' => false]);

        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        $this->assertSame(0, $this->query->counts()['flagged_sections']);
    }

    #[Test]
    public function all_counts_are_zero_when_service_tracking_is_disabled(): void
    {
        config(['service-tracking.enabled' => false]);

        InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending->value]);
        MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create();
        ChurchService::factory()->create([
            'needs_review' => true,
            'pending_structure_merge_source' => 'openlp',
        ]);

        $counts = $this->query->counts();

        $this->assertSame(0, $this->query->total($counts));
        $this->assertSame(0, $counts['pending_emails']);
        $this->assertSame(0, $counts['awaiting_segment_runs']);
    }

    #[Test]
    public function total_sums_every_count(): void
    {
        InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending->value]);
        ChurchService::factory()->create(['needs_review' => true]);

        $this->assertSame(2, $this->query->total());
    }

    #[Test]
    public function cached_counts_are_reused_within_the_cache_window(): void
    {
        $this->assertSame(0, $this->query->cached()['pending_emails']);

        InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending->value]);

        $this->assertSame(0, $this->query->cached()['pending_emails']);
        $this->assertSame(1, $this->query->counts()['pending_emails']);
    }
}
