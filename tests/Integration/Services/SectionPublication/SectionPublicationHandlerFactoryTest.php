<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\SectionPublication\SermonPublicationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SectionPublicationHandlerFactoryTest extends TestCase
{
    use RefreshDatabase;

    private SectionPublicationHandlerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SectionPublicationHandlerFactory;
    }

    #[Test]
    public function it_resolves_a_handler_for_configured_section_types(): void
    {
        config(['media-processing.section_publishing.handlers' => [
            'childrens_talk' => SermonPublicationHandler::class,
        ]]);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $handler = $this->factory->forSection($section);

        $this->assertInstanceOf(SermonPublicationHandler::class, $handler);
    }

    #[Test]
    public function it_returns_null_for_section_types_without_a_handler(): void
    {
        config(['media-processing.section_publishing.handlers' => [
            'childrens_talk' => SermonPublicationHandler::class,
        ]]);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::WELCOME->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertNull($this->factory->forSection($section));
    }

    #[Test]
    public function it_returns_null_when_no_handlers_are_configured(): void
    {
        config(['media-processing.section_publishing.handlers' => []]);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::CHILDRENS_TALK->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->assertNull($this->factory->forSection($section));
    }
}
