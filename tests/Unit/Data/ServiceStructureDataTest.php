<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureDataTest extends TestCase
{
    #[Test]
    public function section_builds_from_a_full_payload(): void
    {
        $section = ServiceStructureSection::fromArray([
            'type' => 'bible_reading',
            'title' => 'Joshua 1',
            'start_time' => 245.0,
            'end_time' => 420.0,
            'confidence' => 0.92,
            'oos_item_id' => 7,
            'song_title' => null,
            'reading_reference' => 'Joshua 1:1-9',
            'notes' => ['Read by the service leader.'],
        ]);

        $this->assertInstanceOf(ServiceStructureSection::class, $section);
        $this->assertSame(ServiceSectionType::BibleReading, $section->type);
        $this->assertSame(245.0, $section->startTime);
        $this->assertSame(175.0, $section->duration());
        $this->assertSame(7, $section->oosItemId);
        $this->assertSame('Joshua 1:1-9', $section->readingReference);
        $this->assertSame([], $section->reviewFlags);
    }

    #[Test]
    public function section_normalises_an_unknown_type_to_other_with_a_review_flag(): void
    {
        $section = ServiceStructureSection::fromArray([
            'type' => 'interpretive_dance',
            'start_time' => 0.0,
            'end_time' => 60.0,
            'confidence' => 0.8,
        ]);

        $this->assertSame(ServiceSectionType::Other, $section->type);
        $this->assertContains(ServiceStructureSection::REVIEW_FLAG_UNKNOWN_TYPE, $section->reviewFlags);
        $this->assertContains('Detector requested unknown section type "interpretive_dance".', $section->notes);
    }

    #[Test]
    public function section_returns_null_without_usable_times(): void
    {
        $this->assertNull(ServiceStructureSection::fromArray([
            'type' => 'sermon',
            'start_time' => 'soon',
            'end_time' => 100.0,
        ]));
        $this->assertNull(ServiceStructureSection::fromArray('not an array'));
    }

    #[Test]
    public function section_clamps_confidence_and_time_ordering(): void
    {
        $section = ServiceStructureSection::fromArray([
            'type' => 'song',
            'start_time' => 100.0,
            'end_time' => 40.0,
            'confidence' => 1.7,
        ]);

        $this->assertSame(100.0, $section->startTime);
        $this->assertSame(100.0, $section->endTime, 'End before start is clamped up to the start.');
        $this->assertSame(1.0, $section->confidence);
    }

    #[Test]
    public function with_times_preserves_identity_and_appends_notes(): void
    {
        $original = ServiceStructureSection::fromArray([
            'type' => 'sermon',
            'start_time' => 500.0,
            'end_time' => 2200.0,
            'confidence' => 0.95,
            'oos_item_id' => 3,
            'notes' => ['Original note.'],
        ]);

        $snapped = $original->withTimes(498.5, 2201.0, ['Snapped to silence.']);

        $this->assertSame(498.5, $snapped->startTime);
        $this->assertSame(['Original note.', 'Snapped to silence.'], $snapped->notes);
        $this->assertSame(3, $snapped->oosItemId);
        $this->assertSame(500.0, $original->startTime, 'The original is untouched.');
    }

    #[Test]
    public function with_review_flags_deduplicates(): void
    {
        $section = ServiceStructureSection::fromArray([
            'type' => 'other',
            'start_time' => 0.0,
            'end_time' => 10.0,
            'review_flags' => ['micro_section'],
        ]);

        $flagged = $section->withReviewFlags(['micro_section', 'low_confidence']);

        $this->assertSame(['micro_section', 'low_confidence'], $flagged->reviewFlags);
    }

    #[Test]
    public function structure_orders_sections_by_start_time(): void
    {
        $structure = ServiceStructure::fromSections([
            ServiceStructureSection::fromArray(['type' => 'sermon', 'start_time' => 500.0, 'end_time' => 2000.0]),
            ServiceStructureSection::fromArray(['type' => 'welcome', 'start_time' => 0.0, 'end_time' => 60.0]),
        ], ['Run note.'], 'gpt-5');

        $this->assertSame(
            [ServiceSectionType::Welcome, ServiceSectionType::Sermon],
            array_map(static fn ($section) => $section->type, $structure->sections)
        );
        $this->assertSame(['Run note.'], $structure->notes);
        $this->assertSame('gpt-5', $structure->model);
    }

    #[Test]
    public function structure_round_trips_through_array_serialisation(): void
    {
        $original = ServiceStructure::fromSections([
            ServiceStructureSection::fromArray([
                'type' => 'song',
                'title' => 'Opening hymn',
                'start_time' => 60.0,
                'end_time' => 240.0,
                'confidence' => 0.9,
                'song_title' => 'Praise My Soul the King of Heaven',
            ]),
            ServiceStructureSection::fromArray([
                'type' => 'sermon',
                'start_time' => 430.0,
                'end_time' => 2200.0,
                'confidence' => 0.97,
                'oos_item_id' => 12,
            ]),
        ], ['Detected cleanly.'], 'gpt-5');

        $restored = ServiceStructure::fromArray(json_decode((string) json_encode($original), true));

        $this->assertSame($original->toArray(), $restored->toArray());
    }

    #[Test]
    public function sections_of_type_filters_correctly(): void
    {
        $structure = ServiceStructure::fromSections([
            ServiceStructureSection::fromArray(['type' => 'song', 'start_time' => 0.0, 'end_time' => 100.0]),
            ServiceStructureSection::fromArray(['type' => 'sermon', 'start_time' => 200.0, 'end_time' => 2000.0]),
            ServiceStructureSection::fromArray(['type' => 'song', 'start_time' => 2100.0, 'end_time' => 2300.0]),
        ]);

        $this->assertCount(2, $structure->sectionsOfType(ServiceSectionType::Song));
        $this->assertCount(1, $structure->sectionsOfType(ServiceSectionType::Sermon));
        $this->assertFalse($structure->isEmpty());
    }
}
