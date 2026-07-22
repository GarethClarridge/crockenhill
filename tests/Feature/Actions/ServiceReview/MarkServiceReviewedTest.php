<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\ServiceReview\MarkServiceReviewed;
use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarkServiceReviewedTest extends TestCase
{
    use RefreshDatabase;

    private MarkServiceReviewed $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(MarkServiceReviewed::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_clears_the_needs_review_flag(): void
    {
        $service = ChurchService::factory()->create(['needs_review' => true]);

        $this->action->execute($service, $this->admin->id);

        $this->assertFalse($service->fresh()->needs_review);
    }

    #[Test]
    public function it_refuses_to_clear_service_review_while_section_review_remains(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-06-07',
            'extracted_service' => 'morning',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);

        $warning = $this->action->execute($service, $this->admin->id);

        $this->assertSame('This service still has 1 section needing attention. Confirm or resolve the section first.', $warning);
        $this->assertTrue($service->fresh()->needs_review);
    }

    #[Test]
    public function it_removes_canonical_conflict_from_import_metadata(): void
    {
        $service = ChurchService::factory()->create([
            'needs_review' => true,
            'review_reason' => 'Service items changed after manual review.',
            'import_metadata' => [
                'canonical_conflict' => [
                    'detected_at' => now()->subHour()->toIso8601String(),
                    'incoming_source' => 'openlp',
                ],
                'canonical_conflict_history' => [[
                    'detected_at' => now()->subHour()->toIso8601String(),
                    'incoming_source' => 'openlp',
                ]],
            ],
        ]);

        $this->action->execute($service, $this->admin->id);

        $fresh = $service->fresh();
        $metadata = $fresh?->import_metadata?->toArray() ?? [];
        $this->assertArrayNotHasKey('canonical_conflict', $metadata);
        $this->assertCount(1, $metadata['canonical_conflict_history'] ?? []);
        $this->assertNull($fresh?->review_reason);
    }

    #[Test]
    public function it_writes_manual_review_audit_metadata(): void
    {
        $service = ChurchService::factory()->create(['needs_review' => true]);

        $this->action->execute($service, $this->admin->id);

        $metadata = $service->fresh()?->import_metadata?->toArray() ?? [];
        $this->assertArrayHasKey('manual_review', $metadata);
        $this->assertSame($this->admin->id, $metadata['manual_review']['reviewed_by_user_id'] ?? null);
        $this->assertSame(ChurchServiceReviewState::Reviewed, $service->fresh()?->review_state);
    }

    #[Test]
    public function it_preserves_existing_import_metadata_fields(): void
    {
        $service = ChurchService::factory()->create([
            'needs_review' => true,
            'import_metadata' => [
                'openlp_import' => ['imported_at' => '2026-01-01T10:00:00Z'],
            ],
        ]);

        $this->action->execute($service, $this->admin->id);

        $metadata = $service->fresh()?->import_metadata?->toArray() ?? [];
        $this->assertArrayHasKey('openlp_import', $metadata);
    }
}
