<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

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
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $this->assertTrue(
            $this->service->canTransition($section, ServiceSectionPublicationStatus::Approved)
        );
        $this->assertFalse(
            $this->service->canTransition($section, ServiceSectionPublicationStatus::Published)
        );
    }

    #[Test]
    public function it_mutates_the_section_when_a_transition_is_valid(): void
    {
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $result = $this->service->transition($section, ServiceSectionPublicationStatus::Approved);

        $this->assertTrue($result);
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $section->publication_status);
    }

    #[Test]
    public function it_logs_and_rejects_invalid_transitions(): void
    {
        Log::spy();

        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);

        $result = $this->service->transition($section, ServiceSectionPublicationStatus::Approved);

        $this->assertFalse($result);
        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->publication_status);

        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function published_sections_cannot_be_requeued_without_explicit_unpublish_logic(): void
    {
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);

        $this->assertFalse(
            $this->service->canTransition($section, ServiceSectionPublicationStatus::PendingApproval)
        );
    }

    #[Test]
    public function not_applicable_sections_can_transition_directly_to_published(): void
    {
        $section = ServiceSection::factory()->create([
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertTrue(
            $this->service->canTransition($section, ServiceSectionPublicationStatus::Published)
        );

        $result = $this->service->transition($section, ServiceSectionPublicationStatus::Published);

        $this->assertTrue($result);
        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->publication_status);
    }
}
