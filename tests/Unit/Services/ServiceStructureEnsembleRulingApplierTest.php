<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRulingApplier;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureEnsembleRulingApplierTest extends TestCase
{
    #[Test]
    public function a_content_bound_correction_replaces_the_claim_and_replay_is_idempotent(): void
    {
        $proposal = $this->proposal();
        $ruling = $this->ruling('correct', ['sections' => [
            $this->section(ServiceSectionType::ShortTalk, 100, 180)->toArray(),
            $this->section(ServiceSectionType::BibleReading, 180, 240)->toArray(),
        ]]);
        $applier = app(ServiceStructureEnsembleRulingApplier::class);
        $first = $applier->apply($proposal, [$ruling]);
        $second = $applier->apply($proposal, [$ruling]);

        $this->assertSame($first, $second);
        $this->assertSame([], $first['disputes']);
        $this->assertSame(['short_talk', 'bible_reading', 'sermon'], array_column($first['structure']['sections'], 'type'));
        $this->assertSame([100.0, 180.0], [
            $first['structure']['sections'][0]['start_time'],
            $first['structure']['sections'][0]['end_time'],
        ]);
        $this->assertNotContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $first['structure']['sections'][2]['review_flags']);
        $this->assertContains('unrelated_review', $first['structure']['sections'][2]['review_flags']);
    }

    #[Test]
    public function stale_answer_cannot_clear_a_dispute_or_change_the_output(): void
    {
        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($this->proposal(), [
            [...$this->ruling('remove', ['absent' => true]), 'source_hash' => 'different-source'],
        ]);

        $this->assertCount(1, $result['disputes']);
        $this->assertCount(1, $result['stale_rulings']);
        $this->assertCount(2, $result['structure']['sections']);
    }

    #[Test]
    public function contradictory_answers_remain_unresolved(): void
    {
        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($this->proposal(), [
            $this->ruling('accept', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 200)->toArray()]]),
            $this->ruling('remove', ['absent' => true]),
        ]);

        $this->assertCount(1, $result['disputes']);
        $this->assertSame([], $result['applied_rulings']);
        $this->assertCount(2, $result['conflicting_rulings']);
    }

    #[Test]
    public function two_answers_over_one_question_resolve_nothing(): void
    {
        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($this->proposal(), [
            $this->ruling('remove', ['absent' => true], key: 'first'),
            $this->ruling('accept', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 200)->toArray()]], key: 'second'),
        ]);

        $this->assertCount(1, $result['disputes']);
        $this->assertSame([], $result['applied_rulings']);
        $this->assertCount(2, $result['structure']['sections']);
    }

    #[Test]
    public function choosing_a_version_applies_the_recorded_content(): void
    {
        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($this->proposal(), [
            $this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 110, 210)->toArray()]]),
        ]);

        $this->assertSame([110.0, 210.0], [
            $result['structure']['sections'][0]['start_time'],
            $result['structure']['sections'][0]['end_time'],
        ]);
    }

    #[Test]
    public function an_answer_survives_a_small_rule_driven_boundary_shift(): void
    {
        $shifted = $this->proposal(talkStart: 104.0, talkEnd: 196.0);
        $shifted['disputes'][0]['question_id'] = 'recomputed-identity';

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($shifted, [
            $this->ruling('remove', ['absent' => true]),
        ]);

        $this->assertSame([], $result['disputes']);
        $this->assertSame(['sermon'], array_column($result['structure']['sections'], 'type'));
    }

    #[Test]
    public function an_answer_does_not_carry_to_a_different_span_or_type(): void
    {
        $elsewhere = $this->proposal(talkStart: 400.0, talkEnd: 480.0, sermonStart: 500.0, sermonEnd: 900.0);
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        $moved = $applier->apply($elsewhere, [$this->ruling('remove', ['absent' => true])]);
        $otherType = $applier->apply($this->proposal(), [
            [...$this->ruling('remove', ['absent' => true]), 'scope' => ['type' => 'prayer', 'start_time' => 100.0, 'end_time' => 200.0]],
        ]);

        $this->assertCount(1, $moved['disputes']);
        $this->assertCount(1, $moved['stale_rulings']);
        $this->assertCount(1, $otherType['disputes']);
    }

    #[Test]
    public function accepted_reduced_coverage_does_not_carry_to_a_new_set_of_draws(): void
    {
        $proposal = [
            'source_hash' => 'source-a',
            'attempt_id' => 'attempt-2',
            'structure' => ServiceStructure::fromSections([$this->section(ServiceSectionType::Sermon, 250, 500)])->toArray(),
            'disputes' => [['question_id' => 'coverage', 'type' => 'degraded_coverage', 'written' => false]],
            'degraded' => true,
        ];
        $ruling = [
            ...$this->ruling('accept', []),
            'scope' => ServiceStructureEnsembleRulingApplier::scopeFor(['type' => 'degraded_coverage'], 'attempt-1'),
        ];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [$ruling]);

        $this->assertCount(1, $result['disputes']);
        $this->assertFalse($result['degraded_reviewed']);
    }

    #[Test]
    public function cannot_tell_stays_open_and_absence_needs_an_explanation(): void
    {
        $proposal = [
            'source_hash' => 'source-a',
            'structure' => ServiceStructure::fromSections([])->toArray(),
            'disputes' => [['question_id' => 'absence', 'type' => 'sermon_absence', 'written' => false]],
            'degraded' => false,
        ];
        $scope = ServiceStructureEnsembleRulingApplier::scopeFor(['type' => 'sermon_absence'], null);
        $applier = app(ServiceStructureEnsembleRulingApplier::class);
        $deferred = $applier->apply($proposal, [[...$this->ruling('defer', null), 'scope' => $scope]]);

        $this->assertTrue($deferred['disputes'][0]['deferred']);
        $this->assertSame('talk-ruling', $deferred['disputes'][0]['ruling_key']);
        $this->assertNull($deferred['structure']['sermon_absence']);

        $answered = $applier->apply($proposal, [[
            ...$this->ruling('absent', null),
            'scope' => $scope,
            'explanation' => 'This was a prayer service.',
        ]]);

        $this->assertSame([], $answered['disputes']);
        $this->assertSame('This was a prayer service.', $answered['structure']['sermon_absence']['explanation']);
    }

    #[Test]
    public function latest_revision_supersedes_prior_local_answer(): void
    {
        $applier = app(ServiceStructureEnsembleRulingApplier::class);
        $result = $applier->apply($this->proposal(), [
            [...$this->ruling('accept', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 200)->toArray()]]), 'revision' => 1],
            [...$this->ruling('remove', ['absent' => true]), 'revision' => 2],
        ]);

        $this->assertSame([], $result['disputes']);
        $this->assertCount(1, $result['structure']['sections']);
        $this->assertSame('remove', $result['applied_rulings'][0]['kind']);
    }

    #[Test]
    public function a_chosen_version_absorbs_a_section_it_wholly_covers(): void
    {
        $proposal = $this->proposalOf(
            [
                $this->section(ServiceSectionType::ShortTalk, 100, 200),
                $this->section(ServiceSectionType::Prayer, 201, 300),
                $this->section(ServiceSectionType::Sermon, 400, 600),
            ],
            ['type' => 'short_talk', 'written' => true, 'start_time' => 100.0, 'end_time' => 200.0],
        );

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [
            $this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 300)->toArray()]]),
        ]);

        $this->assertSame([], $result['disputes']);
        $this->assertSame([
            ['short_talk', 100.0, 300.0],
            ['sermon', 400.0, 600.0],
        ], $this->spans($result));
    }

    #[Test]
    public function a_chosen_version_inside_a_section_splits_that_section_around_it(): void
    {
        $proposal = $this->proposalOf(
            [
                $this->section(ServiceSectionType::Welcome, 0, 150),
                $this->section(ServiceSectionType::Sermon, 400, 600),
            ],
            ['type' => 'bible_reading', 'written' => false, 'start_time' => 60.0, 'end_time' => 140.0],
        );

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [[
            ...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::BibleReading, 60, 140)->toArray()]]),
            'scope' => ['type' => 'bible_reading', 'start_time' => 60.0, 'end_time' => 140.0],
        ]]);

        $this->assertSame([
            ['welcome', 0.0, 60.0],
            ['bible_reading', 60.0, 140.0],
            ['welcome', 140.0, 150.0],
            ['sermon', 400.0, 600.0],
        ], $this->spans($result));
    }

    #[Test]
    public function a_chosen_version_trims_a_section_it_partly_overlaps(): void
    {
        $proposal = $this->proposalOf(
            [
                $this->section(ServiceSectionType::Other, 100, 200),
                $this->section(ServiceSectionType::Sermon, 400, 600),
            ],
            ['type' => 'short_talk', 'written' => false, 'start_time' => 150.0, 'end_time' => 200.0],
        );

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [[
            ...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 150, 200)->toArray()]]),
            'scope' => ['type' => 'short_talk', 'start_time' => 150.0, 'end_time' => 200.0],
        ]]);

        $this->assertSame([
            ['other', 100.0, 150.0],
            ['short_talk', 150.0, 200.0],
            ['sermon', 400.0, 600.0],
        ], $this->spans($result));
    }

    #[Test]
    public function a_neighbour_touching_the_chosen_version_by_a_snap_is_left_alone(): void
    {
        $proposal = $this->proposalOf(
            [
                $this->section(ServiceSectionType::ShortTalk, 100, 200),
                $this->section(ServiceSectionType::Song, 199.5, 300),
            ],
            ['type' => 'short_talk', 'written' => true, 'start_time' => 100.0, 'end_time' => 200.0],
        );

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [
            $this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 200)->toArray()]]),
        ]);

        $this->assertSame([
            ['short_talk', 100.0, 200.0],
            ['song', 199.5, 300.0],
        ], $this->spans($result));
    }

    #[Test]
    public function an_answer_about_part_of_a_passage_does_not_block_the_answer_about_the_whole(): void
    {
        $proposal = [
            'source_hash' => 'source-a',
            'attempt_id' => 'attempt-1',
            'structure' => ServiceStructure::fromSections([
                $this->section(ServiceSectionType::ShortTalk, 100, 300),
                $this->section(ServiceSectionType::Sermon, 400, 600),
            ])->toArray(),
            'disputes' => [
                ['question_id' => 'whole', 'type' => 'short_talk', 'written' => true, 'start_time' => 100.0, 'end_time' => 300.0],
                ['question_id' => 'part', 'type' => 'short_talk', 'written' => false, 'start_time' => 150.0, 'end_time' => 260.0],
            ],
            'degraded' => false,
        ];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [
            [
                ...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 300)->toArray()]], key: 'whole'),
                'scope' => ['type' => 'short_talk', 'start_time' => 100.0, 'end_time' => 300.0],
            ],
            [
                ...$this->ruling('accept', ['absent' => true], key: 'part'),
                'scope' => ['type' => 'short_talk', 'start_time' => 150.0, 'end_time' => 260.0],
            ],
        ]);

        $this->assertSame([], $result['disputes']);
        $this->assertSame([], $result['conflicting_rulings']);
        $this->assertCount(2, $result['applied_rulings']);
        $this->assertSame([
            ['short_talk', 100.0, 300.0],
            ['sermon', 400.0, 600.0],
        ], $this->spans($result));
    }

    #[Test]
    public function a_drifted_answer_still_reaches_its_question_when_a_closer_answer_claims_the_other(): void
    {
        $proposal = [
            'source_hash' => 'source-a',
            'attempt_id' => 'attempt-1',
            'structure' => ServiceStructure::fromSections([
                $this->section(ServiceSectionType::ShortTalk, 100, 300),
                $this->section(ServiceSectionType::Sermon, 400, 600),
            ])->toArray(),
            'disputes' => [
                ['question_id' => 'whole', 'type' => 'short_talk', 'written' => true, 'start_time' => 100.0, 'end_time' => 300.0],
                ['question_id' => 'part', 'type' => 'short_talk', 'written' => false, 'start_time' => 150.0, 'end_time' => 260.0],
            ],
            'degraded' => false,
        ];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [
            [
                ...$this->ruling('remove', ['absent' => true], key: 'whole'),
                'scope' => ['type' => 'short_talk', 'start_time' => 100.0, 'end_time' => 200.0],
            ],
            [
                ...$this->ruling('accept', ['absent' => true], key: 'part'),
                'scope' => ['type' => 'short_talk', 'start_time' => 150.0, 'end_time' => 260.0],
            ],
        ]);

        $this->assertSame([], $result['disputes']);
        $this->assertSame([], $result['conflicting_rulings']);
        $this->assertSame([['sermon', 400.0, 600.0]], $this->spans($result));
    }

    #[Test]
    public function a_later_revision_about_another_span_does_not_replace_the_first_answer(): void
    {
        $proposal = [
            'source_hash' => 'source-a',
            'attempt_id' => 'attempt-1',
            'structure' => ServiceStructure::fromSections([
                $this->section(ServiceSectionType::ShortTalk, 100, 300),
                $this->section(ServiceSectionType::Sermon, 400, 600),
            ])->toArray(),
            'disputes' => [
                ['question_id' => 'whole', 'type' => 'short_talk', 'written' => true, 'start_time' => 100.0, 'end_time' => 300.0],
                ['question_id' => 'part', 'type' => 'short_talk', 'written' => false, 'start_time' => 150.0, 'end_time' => 260.0],
            ],
            'degraded' => false,
        ];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [
            [
                ...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 300)->toArray()]], key: 'shared'),
                'scope' => ['type' => 'short_talk', 'start_time' => 100.0, 'end_time' => 300.0],
            ],
            [
                ...$this->ruling('accept', ['absent' => true], key: 'shared'),
                'revision' => 2,
                'scope' => ['type' => 'short_talk', 'start_time' => 150.0, 'end_time' => 260.0],
            ],
        ]);

        $this->assertSame([], $result['disputes']);
        $this->assertCount(2, $result['applied_rulings']);
        $this->assertSame([
            ['short_talk', 100.0, 300.0],
            ['sermon', 400.0, 600.0],
        ], $this->spans($result));
    }

    #[Test]
    public function a_re_answer_after_a_boundary_drift_supersedes_the_earlier_answer(): void
    {
        $drifted = $this->proposal(talkStart: 104.0, talkEnd: 196.0);

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($drifted, [
            $this->ruling('accept', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 200)->toArray()]]),
            [
                ...$this->ruling('remove', ['absent' => true]),
                'revision' => 2,
                'scope' => ['type' => 'short_talk', 'start_time' => 104.0, 'end_time' => 196.0],
            ],
        ]);

        $this->assertSame([], $result['disputes']);
        $this->assertSame([], $result['conflicting_rulings']);
        $this->assertSame('remove', $result['applied_rulings'][0]['kind']);
        $this->assertSame([['sermon', 250.0, 500.0]], $this->spans($result));
    }

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  array<string, mixed>  $dispute
     * @return array<string, mixed>
     */
    private function proposalOf(array $sections, array $dispute): array
    {
        return [
            'source_hash' => 'source-a',
            'attempt_id' => 'attempt-1',
            'structure' => ServiceStructure::fromSections($sections)->toArray(),
            'disputes' => [['question_id' => 'question', ...$dispute]],
            'degraded' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{0: string, 1: float, 2: float}>
     */
    private function spans(array $result): array
    {
        return array_map(
            static fn (array $section): array => [$section['type'], $section['start_time'], $section['end_time']],
            $result['structure']['sections'],
        );
    }

    /**
     * @param  array<string, mixed>|null  $resolution
     * @return array<string, mixed>
     */
    private function ruling(string $kind, ?array $resolution, string $key = 'talk-ruling'): array
    {
        return [
            'ruling_key' => $key,
            'revision' => 1,
            'source_hash' => 'source-a',
            'scope' => ['type' => 'short_talk', 'start_time' => 100.0, 'end_time' => 200.0],
            'kind' => $kind,
            'resolution' => $resolution,
        ];
    }

    /** @return array<string, mixed> */
    private function proposal(
        float $talkStart = 100.0,
        float $talkEnd = 200.0,
        float $sermonStart = 250.0,
        float $sermonEnd = 500.0,
    ): array {
        $talk = $this->section(ServiceSectionType::ShortTalk, $talkStart, $talkEnd)
            ->withReviewFlags([ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES]);
        $sermon = $this->section(ServiceSectionType::Sermon, $sermonStart, $sermonEnd)
            ->withReviewFlags([ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, 'unrelated_review']);

        return [
            'source_hash' => 'source-a',
            'attempt_id' => 'attempt-1',
            'structure' => ServiceStructure::fromSections([$talk, $sermon])->toArray(),
            'disputes' => [[
                'question_id' => 'talk-question',
                'type' => 'short_talk',
                'written' => true,
                'start_time' => $talkStart,
                'end_time' => $talkEnd,
            ]],
            'degraded' => false,
        ];
    }

    private function section(ServiceSectionType $type, float $start, float $end): ServiceStructureSection
    {
        return new ServiceStructureSection($type, null, $start, $end, 0.95, null, null, null);
    }
}
