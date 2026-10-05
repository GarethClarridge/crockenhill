<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRulingApplier;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SongSpeechEdges;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureEnsembleRulingApplierTest extends TestCase
{
    #[Test]
    public function rejecting_an_absent_inner_talk_does_not_compete_with_the_accepted_containing_talk(): void
    {
        $proposal = $this->proposal(talkStart: 886.0, talkEnd: 1596.0, sermonStart: 2000.0, sermonEnd: 3000.0);
        $proposal['majority_decisions'] = $proposal['disputes'];
        $proposal['disputes'] = [];
        $accepted = [...$this->ruling('accept', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 886, 1596)->toArray()]], 'containing'),
            'scope' => ['type' => 'short_talk', 'start_time' => 886.0, 'end_time' => 1596.0]];
        $removed = [...$this->ruling('remove', ['absent' => true], 'inner'),
            'scope' => ['type' => 'short_talk', 'start_time' => 1057.0, 'end_time' => 1455.0]];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [$accepted, $removed]);

        $this->assertSame([], $result['conflicting_rulings']);
        $this->assertContains(['short_talk', 886.0, 1596.0], $this->spans($result));
        $this->assertCount(2, $result['structure']['sections']);
    }

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

    /**
     * F06: each answer made room for itself by trimming its neighbours, including content
     * another answer had settled, and both were then recorded as applied. Neither answer
     * order may decide which operator decision owns the overlap.
     */
    #[Test]
    public function answers_whose_settled_content_overlaps_remain_unresolved_in_either_order(): void
    {
        $reading = $this->section(ServiceSectionType::BibleReading, 100, 200);
        $talk = $this->section(ServiceSectionType::ShortTalk, 220, 300);
        $proposal = [...$this->proposalOf([$reading, $talk], []), 'disputes' => [
            ['question_id' => 'reading-question', 'type' => 'bible_reading', 'written' => true, 'start_time' => 100.0, 'end_time' => 200.0],
            ['question_id' => 'talk-question', 'type' => 'short_talk', 'written' => true, 'start_time' => 220.0, 'end_time' => 300.0],
        ]];
        $answers = [
            [...$this->ruling('choose', ['sections' => [$reading->toArray()]], 'reading-ruling'), 'scope' => ['type' => 'bible_reading', 'start_time' => 100.0, 'end_time' => 200.0]],
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 180, 300)->toArray()]], 'talk-ruling'), 'scope' => ['type' => 'short_talk', 'start_time' => 220.0, 'end_time' => 300.0]],
        ];
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        foreach ([$answers, array_reverse($answers)] as $ordered) {
            $result = $applier->apply($proposal, $ordered);

            $this->assertSame([], $result['applied_rulings']);
            $this->assertCount(2, $result['conflicting_rulings']);
            $this->assertCount(2, $result['disputes']);
            $this->assertSame([['bible_reading', 100.0, 200.0], ['short_talk', 220.0, 300.0]], $this->spans($result));
        }
    }

    /**
     * I3: independent answers own disjoint content, so their order cannot change the output,
     * and answering a question again with the same content (the answer action's next
     * revision) changes nothing. Two answers claiming one revision are a conflict by design,
     * which the action's row lock and revision counter never write.
     */
    #[Test]
    public function independent_answers_apply_alike_in_either_order_and_a_repeated_answer_changes_nothing(): void
    {
        $reading = $this->section(ServiceSectionType::BibleReading, 100, 200);
        $talk = $this->section(ServiceSectionType::ShortTalk, 220, 300);
        $proposal = [...$this->proposalOf([$reading, $talk], []), 'disputes' => [
            ['question_id' => 'reading-question', 'type' => 'bible_reading', 'written' => true, 'start_time' => 100.0, 'end_time' => 200.0],
            ['question_id' => 'talk-question', 'type' => 'short_talk', 'written' => true, 'start_time' => 220.0, 'end_time' => 300.0],
        ]];
        $answers = [
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::BibleReading, 95, 205)->toArray()]], 'reading-ruling'), 'scope' => ['type' => 'bible_reading', 'start_time' => 100.0, 'end_time' => 200.0]],
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 215, 310)->toArray()]], 'talk-ruling'), 'scope' => ['type' => 'short_talk', 'start_time' => 220.0, 'end_time' => 300.0]],
        ];
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        $forward = $applier->apply($proposal, $answers);
        $reversed = $applier->apply($proposal, array_reverse($answers));
        $repeated = $applier->apply($proposal, [...$answers, [...$answers[0], 'revision' => 2]]);

        $this->assertSame([['bible_reading', 95.0, 205.0], ['short_talk', 215.0, 310.0]], $this->spans($forward));
        $this->assertSame([], $forward['disputes']);
        $this->assertSame($forward['structure'], $reversed['structure']);
        $this->assertSame($forward['disputes'], $reversed['disputes']);
        $this->assertSame($forward['structure'], $repeated['structure']);
        $this->assertSame([], $repeated['conflicting_rulings']);
    }

    /** Fresh draws that raise no question still cannot let one of two overlapping answers win. */
    #[Test]
    public function overlapping_answers_against_unanimous_output_reopen_their_questions(): void
    {
        $unanimous = [...$this->proposal(), 'disputes' => []];
        $answers = [
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 280)->toArray()]]), 'question' => ['question_id' => 'talk-question', 'type' => 'short_talk', 'start_time' => 100.0, 'end_time' => 200.0]],
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::Sermon, 240, 500)->toArray()]], 'sermon-ruling'),
                'scope' => ['type' => 'sermon', 'start_time' => 250.0, 'end_time' => 500.0], 'question' => ['question_id' => 'sermon-question', 'type' => 'sermon', 'start_time' => 250.0, 'end_time' => 500.0]],
        ];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($unanimous, $answers);

        $this->assertSame([], $result['applied_rulings']);
        $this->assertCount(2, $result['conflicting_rulings']);
        $this->assertSame(['talk-question', 'sermon-question'], array_column($result['disputes'], 'question_id'));
        $this->assertSame(['talk-ruling', 'sermon-ruling'], array_column($result['disputes'], 'ruling_key'));

        $revised = app(ServiceStructureEnsembleRulingApplier::class)->apply($unanimous, [...$answers,
            [...$answers[1], 'revision' => 2, 'resolution' => ['sections' => [$this->section(ServiceSectionType::Sermon, 280, 500)->toArray()]]]]);

        $this->assertSame([], $revised['conflicting_rulings']);
        $this->assertSame([['short_talk', 100.0, 280.0], ['sermon', 280.0, 500.0]], $this->spans($revised));
        $this->assertSame([['short_talk', 100.0, 200.0], ['sermon', 250.0, 500.0]], $this->spans($result));
    }

    /** Settled sections that only touch, within snap noise, are not competing for content. */
    #[Test]
    public function answers_whose_settled_content_only_touches_both_apply(): void
    {
        $reading = $this->section(ServiceSectionType::BibleReading, 100, 200);
        $proposal = [...$this->proposalOf([$reading, $this->section(ServiceSectionType::ShortTalk, 220, 300)], []), 'disputes' => [
            ['question_id' => 'reading-question', 'type' => 'bible_reading', 'written' => true, 'start_time' => 100.0, 'end_time' => 200.0],
            ['question_id' => 'talk-question', 'type' => 'short_talk', 'written' => true, 'start_time' => 220.0, 'end_time' => 300.0],
        ]];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [
            [...$this->ruling('choose', ['sections' => [$reading->toArray()]], 'reading-ruling'), 'scope' => ['type' => 'bible_reading', 'start_time' => 100.0, 'end_time' => 200.0]],
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 200.5, 300)->toArray()]], 'talk-ruling'), 'scope' => ['type' => 'short_talk', 'start_time' => 220.0, 'end_time' => 300.0]],
        ]);

        $this->assertSame([], $result['conflicting_rulings']);
        $this->assertCount(2, $result['applied_rulings']);
        $this->assertSame([['bible_reading', 100.0, 200.0], ['short_talk', 200.5, 300.0]], $this->spans($result));
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

    /**
     * The shape of 936-q0 in canary 9: three drafts omitted a reading the operator said is there.
     * The majority now settles that dispute without asking, but the answer still outranks it.
     */
    #[Test]
    public function an_answer_overrides_a_majority_decision_and_unanswered_decisions_stay_on_the_skim_list(): void
    {
        $decided = [
            'question_id' => 'decided-reading',
            'type' => 'bible_reading',
            'written' => false,
            'start_time' => 1040.0,
            'end_time' => 1114.0,
        ];
        $untouched = [...$decided, 'question_id' => 'decided-notice', 'type' => 'notices', 'start_time' => 2445.0, 'end_time' => 2460.0];
        $proposal = [
            ...$this->proposal(),
            'disputes' => [],
            'majority_decisions' => [$decided, $untouched],
        ];
        $answer = [
            ...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::BibleReading, 1040, 1114)->toArray()]], 'reading-ruling'),
            'scope' => ['type' => 'bible_reading', 'start_time' => 1040.0, 'end_time' => 1114.0],
        ];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [$answer]);

        $this->assertContains(['bible_reading', 1040.0, 1114.0], $this->spans($result));
        $this->assertSame([], $result['disputes']);
        $this->assertSame(['decided-notice'], array_column($result['majority_decisions'], 'question_id'));
        $this->assertSame([], $result['stale_rulings']);
        $this->assertCount(1, $result['applied_rulings']);
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

    /**
     * Fresh draws of the same recording can agree on what the operator ruled out. Unanimity
     * is a four-nil vote, and an answer outranks the vote: no question is raised, so the
     * answer must still decide the section rather than going stale.
     */
    #[Test]
    public function a_saved_answer_outranks_unanimous_output_on_the_same_source(): void
    {
        $unanimous = [...$this->proposal(), 'disputes' => []];
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        $removed = $applier->apply($unanimous, [$this->ruling('remove', ['absent' => true])]);
        $chosen = $applier->apply($unanimous, [$this->ruling('choose', ['sections' => [
            $this->section(ServiceSectionType::ShortTalk, 100, 180)->toArray(),
        ]])]);

        $this->assertSame([['sermon', 250.0, 500.0]], $this->spans($removed));
        $this->assertCount(1, $removed['applied_rulings']);
        $this->assertSame([], $removed['stale_rulings']);
        $this->assertSame([['short_talk', 100.0, 180.0], ['sermon', 250.0, 500.0]], $this->spans($chosen));
        $this->assertCount(1, $chosen['applied_rulings']);
    }

    /** F01: identical times are not agreement when the answer settled which passage it is. */
    #[Test]
    public function a_saved_reference_outranks_unanimous_output_at_the_same_times(): void
    {
        $answered = new ServiceStructureSection(ServiceSectionType::BibleReading, null, 100, 200, 0.95, null, null, 'Luke 15:1-10');
        $preached = new ServiceStructureSection(ServiceSectionType::Sermon, null, 250, 500, 0.95, null, null, null, 'Luke 15:11-32');
        $unanimous = [...$this->proposalOf([
            new ServiceStructureSection(ServiceSectionType::BibleReading, null, 100, 200, 0.95, null, null, 'Psalm 23'),
            new ServiceStructureSection(ServiceSectionType::Sermon, null, 250, 500, 0.95, null, null, null, 'Psalm 23'),
        ], []), 'disputes' => []];
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        $reading = $applier->apply($unanimous, [[...$this->ruling('choose', ['sections' => [$answered->toArray()]], 'reading-ruling'),
            'scope' => ['type' => 'bible_reading', 'start_time' => 100.0, 'end_time' => 200.0]]]);
        $sermon = $applier->apply($unanimous, [[...$this->ruling('choose', ['sections' => [$preached->toArray()]], 'sermon-ruling'),
            'scope' => ['type' => 'sermon', 'start_time' => 250.0, 'end_time' => 500.0]]]);

        $this->assertSame('Luke 15:1-10', $reading['structure']['sections'][0]['reading_reference']);
        $this->assertCount(1, $reading['applied_rulings']);
        $this->assertSame('Luke 15:11-32', $sermon['structure']['sections'][1]['sermon_reference']);
        $this->assertCount(1, $sermon['applied_rulings']);
    }

    #[Test]
    public function a_saved_answer_the_unanimous_output_already_honours_changes_nothing(): void
    {
        $unanimous = [...$this->proposal(), 'disputes' => []];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($unanimous, [
            $this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 200)->toArray()]]),
            [...$this->ruling('remove', ['absent' => true], 'prayer-ruling'), 'scope' => ['type' => 'prayer', 'start_time' => 100.0, 'end_time' => 200.0]],
        ]);

        $this->assertSame([['short_talk', 100.0, 200.0], ['sermon', 250.0, 500.0]], $this->spans($result));
        $this->assertCount(2, $result['stale_rulings']);
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
     * The shape of 1250 in canary 10: a canary-9 answer chose a draft whose sermon had been
     * stitched across a quoted passage, but most drafts read one unbroken sermon, so the
     * composition dropped the stitch flag. The answer decides the sermon, not the vote on
     * how a minority of drafts reached it.
     */
    #[Test]
    public function a_chosen_version_does_not_restore_a_stitch_flag_the_composition_dropped(): void
    {
        $chosen = $this->section(ServiceSectionType::Sermon, 2454, 4311)
            ->withReviewFlags([ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, 'chosen_version_review']);
        $proposal = $this->proposalOf(
            [$this->section(ServiceSectionType::Sermon, 2454, 4311)->withReviewFlags([ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES])],
            ['type' => 'sermon', 'written' => true, 'start_time' => 2454.0, 'end_time' => 4311.0],
        );

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [[
            ...$this->ruling('choose', ['sections' => [$chosen->toArray()]], key: 'sermon'),
            'scope' => ['type' => 'sermon', 'start_time' => 2454.0, 'end_time' => 4311.0],
        ]]);

        $flags = $result['structure']['sections'][0]['review_flags'];
        $this->assertNotContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $flags);
        $this->assertContains('chosen_version_review', $flags);
    }

    #[Test]
    public function a_chosen_version_keeps_a_stitch_flag_the_composition_carries(): void
    {
        $proposal = $this->proposalOf(
            [$this->section(ServiceSectionType::Sermon, 2454, 4311)->withReviewFlags([
                ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES,
                ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED,
            ])],
            ['type' => 'sermon', 'written' => true, 'start_time' => 2454.0, 'end_time' => 4311.0],
        );

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [[
            ...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::Sermon, 2454, 4311)->toArray()]], key: 'sermon'),
            'scope' => ['type' => 'sermon', 'start_time' => 2454.0, 'end_time' => 4311.0],
        ]]);

        $this->assertContains(
            ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED,
            $result['structure']['sections'][0]['review_flags'],
        );
    }

    /**
     * The shape of 949 and 1250 in canary 10: an answered sermon keeps the composition's
     * exposed-speech question, and so must keep the interval that makes it answerable.
     */
    #[Test]
    public function a_chosen_version_keeps_the_interval_of_an_exposed_speech_question(): void
    {
        $note = SongSpeechEdges::exposedSpeechNote(4311.0, 4335.0);
        $proposal = $this->proposalOf(
            [$this->section(ServiceSectionType::Sermon, 2454, 4311)->withReviewFlags([
                ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES,
                ServiceStructureValidator::FLAG_SERMON_ADJACENT_SPEECH_UNOWNED,
            ], ['The composed draft\'s own note.', $note])],
            ['type' => 'sermon', 'written' => true, 'start_time' => 2454.0, 'end_time' => 4311.0],
        );

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [[
            ...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::Sermon, 2454, 4311)->toArray()]], key: 'sermon'),
            'scope' => ['type' => 'sermon', 'start_time' => 2454.0, 'end_time' => 4311.0],
        ]]);

        $sermon = $result['structure']['sections'][0];
        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_ADJACENT_SPEECH_UNOWNED, $sermon['review_flags']);
        $this->assertSame([$note], $sermon['notes']);
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
