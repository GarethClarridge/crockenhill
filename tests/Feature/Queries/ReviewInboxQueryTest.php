<?php

declare(strict_types=1);

namespace Tests\Feature\Queries;

use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Queries\ReviewInboxQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithInboundEmailTestHelpers;

class ReviewInboxQueryTest extends TestCase
{
    use DatabaseTransactions;
    use WithInboundEmailTestHelpers;

    private ReviewInboxQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = app(ReviewInboxQuery::class);
    }

    #[Test]
    public function it_groups_disparate_items_by_resolved_service_slot(): void
    {
        $date = '2026-06-07';
        $serviceValue = 'morning';

        // 1. Inbound Email
        InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata($date, $serviceValue, []),
        ]);

        // 2. Flagged Section
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => $date,
            'extracted_service' => $serviceValue,
        ]);
        // Null the item relation so the run identity (extracted_date/service)
        // resolves the group. Left unset, the factory attaches a random
        // ChurchServiceItem -> ChurchService, which resolveGroupContext() prefers,
        // scattering this section into an unrelated, faker-dependent group.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'needs_manual_review' => true,
        ]);

        // 3. Awaiting Manual Review Segment
        MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'extracted_date' => $date,
            'extracted_service' => $serviceValue,
        ]);

        // 4. Church Service with pending merge AND flagged for review
        ChurchService::factory()->create([
            'date' => $date,
            'service' => SermonService::Morning,
            'pending_structure_merge_source' => 'openlp',
            'needs_review' => true,
        ]);

        $result = $this->query->build();

        $this->assertCount(1, $result['groups']);
        $group = $result['groups'][0];
        $this->assertSame($date, $group['date']);
        $this->assertSame($serviceValue, $group['service_value']);

        $kinds = collect($group['items'])->pluck('kind')->toArray();
        $this->assertContains('email', $kinds);
        $this->assertContains('section', $kinds);
        $this->assertContains('segment', $kinds);
        $this->assertContains('merge', $kinds);
        $this->assertContains('service_flag', $kinds);
    }

    #[Test]
    public function it_pins_unattributed_items_to_the_top(): void
    {
        // Unattributed item (failed parse)
        InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Failed->value,
            'processing_metadata' => null,
        ]);

        // Attributed item (far in the future)
        InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata('2099-01-01', 'morning', []),
        ]);

        $result = $this->query->build();

        $this->assertCount(2, $result['groups']);
        $this->assertSame('unattributed', $result['groups'][0]['key']);
        $this->assertSame('2099-01-01|morning', $result['groups'][1]['key']);
    }

    #[Test]
    public function it_sorts_groups_chronologically_descending(): void
    {
        InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata('2026-06-01', 'morning', []),
        ]);

        InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata('2026-06-07', 'morning', []),
        ]);

        $result = $this->query->build();

        $this->assertCount(2, $result['groups']);
        $this->assertSame('2026-06-07', $result['groups'][0]['date']);
        $this->assertSame('2026-06-01', $result['groups'][1]['date']);
    }

    #[Test]
    public function it_sorts_services_within_the_same_date(): void
    {
        InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata('2026-06-07', 'evening', []),
        ]);

        InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata('2026-06-07', 'morning', []),
        ]);

        $result = $this->query->build();

        $this->assertCount(2, $result['groups']);
        $this->assertSame('morning', $result['groups'][0]['service_value']);
        $this->assertSame('evening', $result['groups'][1]['service_value']);
    }

    #[Test]
    public function it_caps_each_source_independently(): void
    {
        // 51 emails
        InboundEmail::factory()->count(ReviewInboxQuery::SOURCE_CAP + 1)->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => null, // Unattributed
        ]);

        // 1 flagged service
        ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $result = $this->query->build();

        $this->assertSame(ReviewInboxQuery::SOURCE_CAP, $result['counts']['emails']);
        $this->assertSame(1, $result['counts']['services']);

        // Check the actual items in the unattributed group
        $unattributedGroup = collect($result['groups'])->firstWhere('key', 'unattributed');
        $this->assertCount(ReviewInboxQuery::SOURCE_CAP, $unattributedGroup['items']);
    }

    #[Test]
    public function it_reports_accurate_summary_counts(): void
    {
        InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending->value]);
        ChurchService::factory()->create(['needs_review' => true]);

        $run = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create();

        $run2 = MediaProcessingLog::factory()->livestream()->completed()->create(['sermon_id' => null]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run2->id,
            'needs_manual_review' => true,
        ]);

        $result = $this->query->build();

        $this->assertSame(1, $result['counts']['emails']);
        $this->assertSame(1, $result['counts']['services']);
        $this->assertSame(1, $result['counts']['segments']);
        $this->assertSame(1, $result['counts']['sections']);
        $this->assertSame(4, $result['counts']['all']);
    }
}
