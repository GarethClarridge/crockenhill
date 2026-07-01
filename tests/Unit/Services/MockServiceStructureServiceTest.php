<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\MockServiceStructureService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MockServiceStructureServiceTest extends TestCase
{
    private MockServiceStructureService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MockServiceStructureService;
    }

    protected function tearDown(): void
    {
        MockServiceStructureService::useStructure(null);

        parent::tearDown();
    }

    #[Test]
    public function it_is_deterministic_for_the_same_transcript(): void
    {
        $transcript = $this->transcript();

        $first = $this->service->detect($transcript, $this->oosItems());
        $second = $this->service->detect($transcript, $this->oosItems());

        $this->assertSame($first->toArray(), $second->toArray());
    }

    #[Test]
    public function it_derives_typed_sections_from_transcript_markers(): void
    {
        $structure = $this->service->detect($this->transcript(), $this->oosItems());

        $types = array_map(static fn ($section) => $section->type, $structure->sections);

        $this->assertContains(ServiceSectionType::Welcome, $types);
        $this->assertContains(ServiceSectionType::Song, $types);
        $this->assertContains(ServiceSectionType::Sermon, $types);
        $this->assertSame('mock', $structure->model);
    }

    #[Test]
    public function it_claims_each_oos_item_at_most_once(): void
    {
        $structure = $this->service->detect($this->transcript(), $this->oosItems());

        $claimedIds = array_values(array_filter(array_map(
            static fn ($section) => $section->oosItemId,
            $structure->sections
        )));

        $this->assertSame($claimedIds, array_unique($claimedIds));
    }

    #[Test]
    public function it_returns_the_fixture_when_one_is_set(): void
    {
        $fixture = ServiceStructure::fromSections([
            ServiceStructureSection::fromArray([
                'type' => 'sermon',
                'start_time' => 100.0,
                'end_time' => 2000.0,
                'confidence' => 1.0,
            ]),
        ], ['Fixture structure.'], 'mock');

        MockServiceStructureService::useStructure($fixture);

        $structure = $this->service->detect($this->transcript(), $this->oosItems());

        $this->assertSame($fixture->toArray(), $structure->toArray());
    }

    private function transcript(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 20.0, 'text' => 'Good morning everyone and a very warm welcome.'],
            ['start' => 60.0, 'end' => 240.0, 'text' => 'We sing our first hymn together.'],
            ['start' => 245.0, 'end' => 420.0, 'text' => 'Our reading this morning is from Joshua chapter one.'],
            ['start' => 430.0, 'end' => 2200.0, 'text' => 'Please turn with me to our passage. Our first point this morning.'],
        ], 2400.0, ChurchServiceTranscript::SOURCE_MOCK);
    }

    /**
     * @return array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>
     */
    private function oosItems(): array
    {
        return [
            ['id' => 1, 'position' => 1, 'type' => 'welcome', 'title' => 'Welcome', 'song_id' => null],
            ['id' => 2, 'position' => 2, 'type' => 'song', 'title' => 'Praise My Soul', 'song_id' => 9],
            ['id' => 3, 'position' => 3, 'type' => 'bible_reading', 'title' => 'Joshua 1:1-9', 'song_id' => null],
            ['id' => 4, 'position' => 4, 'type' => 'sermon', 'title' => 'The faithfulness of God', 'song_id' => null],
        ];
    }
}
