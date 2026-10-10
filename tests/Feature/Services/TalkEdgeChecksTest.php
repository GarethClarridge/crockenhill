<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\CutAwareEnsembleComposer;
use App\Services\ChurchService\Structure\EnsembleComposition;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRulingApplier;
use App\Services\ChurchService\Structure\TalkEdgeChecks;
use App\Services\ChurchService\Structure\ValidationResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Talk edges every draft agreed on were asked from 2026-09-28 (plan DR6) because agreement cannot see
 * an error every draft shares. All fifteen answered by canary 15 confirmed the talk, and the operator
 * ruled on 2026-10-10 that they are no longer asked. Checks already stored on a run still apply.
 */
class TalkEdgeChecksTest extends TestCase
{
    #[Test]
    public function a_unanimous_talk_touching_other_speech_is_not_asked(): void
    {
        foreach ([ServiceSectionType::BibleReading, ServiceSectionType::Prayer, ServiceSectionType::Other, ServiceSectionType::Notices] as $neighbour) {
            $this->assertSame([], $this->checks($this->compose($this->draws(talkEnd: 900.0, prayerStart: 900.0, neighbour: $neighbour))), $neighbour->value);
        }
    }

    /** A talk the drafts disagree about is still asked, by its own question. */
    #[Test]
    public function a_talk_the_drafts_disagree_about_is_still_asked(): void
    {
        $draws = $this->draws(talkEnd: 900.0, prayerStart: 900.0, neighbour: ServiceSectionType::BibleReading);
        $draws[2] = $this->draw(talkEnd: 960.0, prayerStart: 960.0, neighbour: ServiceSectionType::BibleReading);
        $draws[3] = $this->draw(talkEnd: 960.0, prayerStart: 960.0, neighbour: ServiceSectionType::BibleReading);

        $this->assertContains('short_talk', array_column($this->compose($draws)->disputes, 'type'));
    }

    /** A check banked before the ruling is still answered and applied like any question. */
    #[Test]
    public function a_stored_check_is_still_settled_by_its_answer(): void
    {
        $composition = $this->compose($this->draws(talkEnd: 900.0, prayerStart: 900.0, neighbour: ServiceSectionType::BibleReading));
        $talk = $composition->structure->sectionsOfType(ServiceSectionType::ShortTalk)[0];
        $check = [
            'type' => 'short_talk',
            'check' => TalkEdgeChecks::CHECK,
            'edges' => ['end'],
            'neighbours' => [['edge' => 'end', 'type' => 'bible_reading', 'title' => 'Prayer']],
            'start_time' => $talk->startTime,
            'end_time' => $talk->endTime,
            'written' => true,
            'supporting_slots' => [0, 1, 2, 3],
            'absent_slots' => [],
            'alternatives' => [['slots' => [0, 1, 2, 3], 'section' => $talk->toArray()]],
            'question_id' => 'stored-check',
        ];
        $ruling = [
            'ruling_key' => 'edge-1',
            'question_id' => $check['question_id'],
            'source_hash' => 'source',
            'scope' => ServiceStructureEnsembleRulingApplier::scopeFor($check, null),
            'kind' => 'correct',
            'resolution' => ['sections' => [[...$check['alternatives'][0]['section'], 'end_time' => 880.0]]],
            'revision' => 1,
        ];

        $after = app(ServiceStructureEnsembleRulingApplier::class)->apply([
            'source_hash' => 'source',
            'structure' => $composition->structure->toArray(),
            'disputes' => [...$composition->disputes, $check],
            'majority_decisions' => $composition->majorityDecisions,
        ], [$ruling]);

        $this->assertSame([], $after['disputes']);
        $this->assertSame(880.0, ServiceStructure::fromArray($after['structure'])->sectionsOfType(ServiceSectionType::ShortTalk)[0]->endTime);
    }

    /** @param  array<int, ValidationResult>  $draws */
    private function compose(array $draws): EnsembleComposition
    {
        return app(CutAwareEnsembleComposer::class)->compose($draws, $this->transcript(), null);
    }

    /** @return list<array<string, mixed>> */
    private function checks(EnsembleComposition $composition): array
    {
        return array_values(array_filter($composition->disputes, static fn (array $dispute): bool => ($dispute['check'] ?? null) === 'talk_edge'));
    }

    /** @return array<int, ValidationResult> */
    private function draws(float $talkEnd, float $prayerStart, ServiceSectionType $neighbour = ServiceSectionType::Prayer): array
    {
        $draw = $this->draw($talkEnd, $prayerStart, $neighbour);

        return [0 => $draw, 1 => $draw, 2 => $draw, 3 => $draw];
    }

    private function draw(float $talkEnd, float $prayerStart, ServiceSectionType $neighbour = ServiceSectionType::Prayer): ValidationResult
    {
        return new ValidationResult(ServiceStructure::fromSections([
            new ServiceStructureSection(ServiceSectionType::Welcome, 'Welcome', 0.0, 120.0, 0.95, null, null, null),
            new ServiceStructureSection(ServiceSectionType::ShortTalk, 'Mission update', 600.0, $talkEnd, 0.95, null, null, null),
            new ServiceStructureSection($neighbour, $neighbour === ServiceSectionType::Song ? 'Amazing grace' : 'Prayer', $prayerStart, 1000.0, 0.95, null, $neighbour === ServiceSectionType::Song ? 'Amazing grace' : null, null),
            new ServiceStructureSection(ServiceSectionType::Sermon, 'Sermon', 1200.0, 2150.0, 0.95, null, null, null),
        ]));
    }

    private function transcript(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 120.0, 'text' => 'Welcome everyone.'],
            ['start' => 600.0, 'end' => 880.0, 'text' => 'News from the mission field.'],
            ['start' => 900.0, 'end' => 1000.0, 'text' => 'And then we pray.'],
            ['start' => 1200.0, 'end' => 2150.0, 'text' => 'Please turn with me to the passage.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK);
    }
}
