<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Publication;

use App\Actions\Publication\RejectSectionPublication;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RejectSectionPublicationTest extends TestCase
{
    use RefreshDatabase;

    private RejectSectionPublication $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(RejectSectionPublication::class);
    }

    #[Test]
    public function it_transitions_pending_section_to_rejected_and_saves(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        $result = $this->action->execute($section);

        $this->assertTrue($result);
        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::REJECTED, $section->publication_status);
    }

    #[Test]
    public function it_returns_false_when_section_cannot_be_rejected(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        // PUBLISHED → REJECTED is not an allowed transition
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
        ]);

        $result = $this->action->execute($section);

        $this->assertFalse($result);
        $section->refresh();
        $this->assertSame(ServiceSectionPublicationStatus::PUBLISHED, $section->publication_status);
    }
}
