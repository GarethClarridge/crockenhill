<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\SectionPublication\SermonPublicationHandler;
use App\Services\ServiceSectionPublicationTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionPublicationTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceSectionPublicationTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ServiceSectionPublicationTransitionService::class);
    }

    #[Test]
    public function it_identifies_publishable_section_types_from_handler_registry(): void
    {
        config(['media-processing.section_publishing.handlers' => [
            'childrens_talk' => SermonPublicationHandler::class,
        ]]);

        $publishable = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
        ]);
        $nonPublishable = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::WELCOME->value,
        ]);

        $this->assertTrue($this->service->isPublishableType($publishable));
        $this->assertFalse($this->service->isPublishableType($nonPublishable));
    }

    #[Test]
    public function it_allows_documented_publication_transitions(): void
    {
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        $this->assertTrue(
            $this->service->canTransition($section, ServiceSectionPublicationStatus::APPROVED)
        );
        $this->assertFalse(
            $this->service->canTransition($section, ServiceSectionPublicationStatus::PUBLISHED)
        );
    }

    #[Test]
    public function it_mutates_the_section_when_a_transition_is_valid(): void
    {
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
        ]);

        $result = $this->service->transition($section, ServiceSectionPublicationStatus::APPROVED);

        $this->assertTrue($result);
        $this->assertSame(ServiceSectionPublicationStatus::APPROVED, $section->publication_status);
    }

    #[Test]
    public function it_logs_and_rejects_invalid_transitions(): void
    {
        Log::spy();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
        ]);

        $result = $this->service->transition($section, ServiceSectionPublicationStatus::APPROVED);

        $this->assertFalse($result);
        $this->assertSame(ServiceSectionPublicationStatus::PUBLISHED, $section->publication_status);

        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function published_sections_cannot_be_requeued_without_explicit_unpublish_logic(): void
    {
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::PUBLISHED->value,
        ]);

        $this->assertFalse(
            $this->service->canTransition($section, ServiceSectionPublicationStatus::PENDING_APPROVAL)
        );
    }

    #[Test]
    public function not_applicable_sections_can_transition_directly_to_published(): void
    {
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::NOT_APPLICABLE->value,
        ]);

        $this->assertTrue(
            $this->service->canTransition($section, ServiceSectionPublicationStatus::PUBLISHED)
        );

        $result = $this->service->transition($section, ServiceSectionPublicationStatus::PUBLISHED);

        $this->assertTrue($result);
        $this->assertSame(ServiceSectionPublicationStatus::PUBLISHED, $section->publication_status);
    }
}
