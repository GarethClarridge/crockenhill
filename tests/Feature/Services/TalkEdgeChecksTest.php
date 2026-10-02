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
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\ValidationResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Agreement cannot see an error every draft shares. Where it hides most is a talk edge that
 * touches the speech next to it (plan DR6, ruled 2026-09-28: every such edge is asked).
 */
class TalkEdgeChecksTest extends TestCase
{
    #[Test]
    public function a_unanimous_talk_touching_a_reading_is_asked_about_its_end(): void
    {
        $composition = $this->compose($this->draws(talkEnd: 900.0, prayerStart: 900.0, neighbour: ServiceSectionType::BibleReading));
        $checks = $this->checks($composition);

        $this->assertCount(1, $checks);
        $this->assertSame(['end'], $checks[0]['edges']);
        $this->assertSame([['edge' => 'end', 'type' => 'bible_reading', 'title' => 'Prayer']], $checks[0]['neighbours']);
        $this->assertSame(600.0, $checks[0]['start_time']);
        $this->assertSame([0, 1, 2, 3], $checks[0]['alternatives'][0]['slots']);
        $this->assertTrue($composition->requiresReview());
        $talk = $composition->structure->sectionsOfType(ServiceSectionType::ShortTalk)[0];
        $this->assertContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $talk->reviewFlags);
    }

    #[Test]
    public function a_talk_ending_into_a_prayer_is_not_asked(): void
    {
        $composition = $this->compose($this->draws(talkEnd: 900.0, prayerStart: 900.0));

        $this->assertSame([], $this->checks($composition));
        $talk = $composition->structure->sectionsOfType(ServiceSectionType::ShortTalk)[0];
        $this->assertNotContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $talk->reviewFlags);
    }

    #[Test]
    public function prayer_before_a_talk_still_checks_its_start(): void
    {
        $draw = new ValidationResult(ServiceStructure::fromSections([
            new ServiceStructureSection(ServiceSectionType::Prayer, 'Prayer', 500.0, 600.0, 0.95, null, null, null),
            new ServiceStructureSection(ServiceSectionType::ShortTalk, 'Mission update', 600.0, 900.0, 0.95, null, null, null),
            new ServiceStructureSection(ServiceSectionType::Prayer, 'Closing prayer', 900.0, 1000.0, 0.95, null, null, null),
            new ServiceStructureSection(ServiceSectionType::Sermon, 'Sermon', 1200.0, 2150.0, 0.95, null, null, null),
        ]));
        $checks = $this->checks($this->compose(array_fill(0, 4, $draw)));

        $this->assertCount(1, $checks);
        $this->assertSame(['start'], $checks[0]['edges']);
    }

    /** A pause of three seconds or more is a boundary the recording itself marks. */
    #[Test]
    public function a_talk_with_a_pause_before_the_next_speech_is_not_asked(): void
    {
        $this->assertSame([], $this->checks($this->compose($this->draws(talkEnd: 900.0, prayerStart: 903.0))));
    }

    /** Music next to a talk is a boundary the sound stage places; it is not speech. */
    #[Test]
    public function a_talk_ending_at_a_song_is_not_asked(): void
    {
        $this->assertSame([], $this->checks($this->compose($this->draws(talkEnd: 900.0, prayerStart: 900.0, neighbour: ServiceSectionType::Song))));
    }

    /** A talk the drafts already disagree about is asked once, by its own question. */
    #[Test]
    public function a_talk_already_in_question_is_not_asked_again(): void
    {
        $draws = $this->draws(talkEnd: 900.0, prayerStart: 900.0);
        $draws[2] = $this->draw(talkEnd: 960.0, prayerStart: 960.0);
        $draws[3] = $this->draw(talkEnd: 960.0, prayerStart: 960.0);
        $composition = $this->compose($draws);

        $this->assertSame([], $this->checks($composition));
        $this->assertContains('short_talk', array_column($composition->disputes, 'type'));
    }

    /** Confirming the edge settles the check, and it stays settled on every later replay. */
    #[Test]
    public function an_answer_settles_the_check_and_carries_to_the_next_composition(): void
    {
        $composition = $this->compose($this->draws(talkEnd: 900.0, prayerStart: 900.0, neighbour: ServiceSectionType::BibleReading));
        $check = $this->checks($composition)[0];
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
            'disputes' => $composition->disputes,
            'majority_decisions' => $composition->majorityDecisions,
        ], [$ruling]);

        $this->assertSame([], $after['disputes']);
        $this->assertCount(1, $after['applied_rulings']);
        $talk = ServiceStructure::fromArray($after['structure'])->sectionsOfType(ServiceSectionType::ShortTalk)[0];
        $this->assertSame(880.0, $talk->endTime);
        $this->assertNotContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $talk->reviewFlags);
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
            ['start' => 600.0, 'end' => 1000.0, 'text' => 'News from the mission field, and then we pray.'],
            ['start' => 1200.0, 'end' => 2150.0, 'text' => 'Please turn with me to the passage.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK);
    }
}
