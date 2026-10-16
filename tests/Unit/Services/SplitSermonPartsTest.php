<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\SermonContinuationScreen;
use App\Services\ChurchService\Structure\ServiceStructureDrawExecutor;
use App\Services\ChurchService\Structure\SplitSermonParts;
use App\Services\ChurchService\Structure\ValidationContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class SplitSermonPartsTest extends TestCase
{
    /**
     * Run 1073's draws: Jonah either side of "In Awe And Wonder", both parts typed sermon.
     */
    #[Test]
    public function a_sermon_preached_around_a_song_keeps_its_longest_part_as_the_sermon(): void
    {
        $joined = app(SplitSermonParts::class)->join(ServiceStructure::fromSections([
            $this->section(ServiceSectionType::Sermon, 1654.66, 2697.18, 'God who saves: Jonah in the depths', oosItemId: 41),
            $this->section(ServiceSectionType::Song, 2697.18, 2888.66, 'In Awe And Wonder', oosItemId: 42),
            $this->section(ServiceSectionType::Sermon, 2888.66, 3595.22, 'God who saves: salvation through Jesus', oosItemId: 41),
        ]));

        [$first, $song, $second] = $joined->sections;
        $this->assertSame(ServiceSectionType::Sermon, $first->type);
        $this->assertSame(41, $first->oosItemId);
        $this->assertSame(ServiceSectionType::Song, $song->type);
        $this->assertSame(ServiceSectionType::Other, $second->type);
        $this->assertNull($second->oosItemId);
        $this->assertContains(SplitSermonParts::NOTE, $second->notes);
        $this->assertSame([2888.66, 3595.22], [$second->startTime, $second->endTime]);
    }

    /**
     * Run 1268's draws: Genesis 1 in three parts; only a later part named the OoS item.
     */
    #[Test]
    public function the_sermon_keeps_the_order_of_service_item_a_part_named(): void
    {
        $joined = app(SplitSermonParts::class)->join(ServiceStructure::fromSections([
            $this->section(ServiceSectionType::Sermon, 1147.07, 1855.0, 'In the Beginning, God — Part 1'),
            $this->section(ServiceSectionType::Song, 1855.0, 2029.64, 'All glory be to Christ'),
            $this->section(ServiceSectionType::Sermon, 2029.64, 2321.44, 'In the Beginning, God — Part 2', oosItemId: 77),
            $this->section(ServiceSectionType::Song, 2321.44, 2491.02, "You're The Word Of God The Father"),
            $this->section(ServiceSectionType::Sermon, 2491.02, 3185.86, 'In the Beginning, God — Part 3'),
        ]));

        $this->assertCount(1, $joined->sectionsOfType(ServiceSectionType::Sermon));
        $this->assertSame('In the Beginning, God — Part 1', $joined->sectionsOfType(ServiceSectionType::Sermon)[0]->title);
        $this->assertSame(77, $joined->sectionsOfType(ServiceSectionType::Sermon)[0]->oosItemId);
        $this->assertSame([null, null], array_map(
            static fn (ServiceStructureSection $part): ?int => $part->oosItemId,
            $joined->sectionsOfType(ServiceSectionType::Other),
        ));
    }

    #[Test]
    public function a_single_sermon_is_left_alone(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section(ServiceSectionType::Song, 0.0, 200.0, 'Opening song'),
            $this->section(ServiceSectionType::Sermon, 200.0, 2000.0, 'The sermon'),
        ]);

        $this->assertSame($structure, app(SplitSermonParts::class)->join($structure));
    }

    /**
     * The plan joins the parts through the continuation screen, so the note must be one it admits.
     */
    #[Test]
    public function the_note_on_a_part_is_continuation_evidence(): void
    {
        $section = new ServiceSection(['section_type' => ServiceSectionType::Other->value]);
        $section->metadata = ['ai_notes' => [SplitSermonParts::NOTE]];

        $this->assertSame(SplitSermonParts::NOTE, app(SermonContinuationScreen::class)->evidence($section));
    }

    /**
     * Every banked draw on 1073 and 1268 failed here, banked or replayed, before the join.
     */
    #[Test]
    public function a_draw_with_a_split_sermon_no_longer_fails_validation(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 3000.0, 'text' => 'And so we come to Jonah.'],
        ], 3000, ChurchServiceTranscript::SOURCE_MOCK);
        $input = [
            'transcript' => $transcript->toArray(),
            'audio_timeline' => AudioTimelineFixture::payload([], 3000),
            'rms_log' => null,
            'validation_context' => ServiceStructureDrawExecutor::contextSnapshot(ValidationContext::for($transcript)),
        ];

        $result = app(ServiceStructureDrawExecutor::class)->refineAndValidate($input, ServiceStructure::fromSections([
            $this->section(ServiceSectionType::Sermon, 0.0, 1400.0, 'Part one'),
            $this->section(ServiceSectionType::Song, 1400.0, 1600.0, 'A hymn'),
            $this->section(ServiceSectionType::Sermon, 1600.0, 3000.0, 'Part two'),
        ]));

        $this->assertNotContains('multiple_sermons', $result['validation']->failureCodes());
        $this->assertCount(1, $result['refined']->sectionsOfType(ServiceSectionType::Sermon));
    }

    private function section(ServiceSectionType $type, float $start, float $end, string $title, ?int $oosItemId = null): ServiceStructureSection
    {
        return new ServiceStructureSection($type, $title, $start, $end, 0.9, $oosItemId, null, null);
    }
}
