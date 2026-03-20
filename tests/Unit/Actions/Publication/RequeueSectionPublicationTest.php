<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Publication;

use App\Actions\Publication\RequeueSectionPublication;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RequeueSectionPublicationTest extends TestCase
{
    use RefreshDatabase;

    private RequeueSectionPublication $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new RequeueSectionPublication;
    }

    #[Test]
    public function it_transitions_rejected_section_back_to_pending_approval_and_saves(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::REJECTED->value,
        ]);

        $result = $this->action->execute($section);

        $this->assertTrue($result);
        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PENDING_APPROVAL, $section->publication_status);
    }

    #[Test]
    public function it_returns_false_when_section_cannot_be_requeued(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        // APPROVED → PENDING_APPROVAL is not an allowed transition
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::APPROVED->value,
        ]);

        $result = $this->action->execute($section);

        $this->assertFalse($result);
        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::APPROVED, $section->publication_status);
    }
}
