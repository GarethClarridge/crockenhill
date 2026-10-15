<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRulingApplier;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\ChurchService\Structure\SongSpeechEdges;
use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Support\SermonAutoExtractionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * another answer had settled, so answer order decided which owned the overlap. 1096
     * (operator, 2026-10-14): a stretch can end one item and begin the next, so a seam two
     * answers share stays in both, whichever order they are applied in.
     */
    #[Test]
    public function answers_sharing_a_seam_both_keep_their_edges_in_either_order(): void
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

            $this->assertCount(2, $result['applied_rulings']);
            $this->assertSame([], $result['conflicting_rulings']);
            $this->assertSame([], $result['disputes']);
            $this->assertSame([['bible_reading', 100.0, 200.0], ['short_talk', 180.0, 300.0]], $this->spans($result));
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

    /** Fresh draws that raise no question still cannot let one of two grossly overlapping answers win. */
    #[Test]
    public function overlapping_answers_against_unanimous_output_reopen_their_questions(): void
    {
        $unanimous = [...$this->proposal(), 'disputes' => []];
        $answers = [
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 100, 280)->toArray()]]), 'question' => ['question_id' => 'talk-question', 'type' => 'short_talk', 'start_time' => 100.0, 'end_time' => 200.0]],
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::Sermon, 150, 500)->toArray()]], 'sermon-ruling'),
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

    /**
     * Full pass, 1027 and 987 (2026-10-11): the sermon answer took the earliest start, "unless
     * the extra is an item of its own", and the reading answer said the passage inside that
     * span is an item of its own. The exception decides it: the sermon starts after the item
     * rather than both answers staying open as a clash.
     *
     * @param  list<array{0: string, 1: float, 2: float}>  $inner
     * @param  array{0: float, 1: float}  $sermonScope
     * @param  list<array{0: string, 1: float, 2: float}>  $expected
     */
    #[Test]
    #[DataProvider('itemsAnsweredInsideASermon')]
    public function a_sermon_answer_yields_to_an_item_answered_as_its_own_inside_it(array $inner, array $sermonScope, float $sermonStart, float $sermonEnd, array $expected): void
    {
        $innerSections = array_map(fn (array $span): ServiceStructureSection => $this->section(ServiceSectionType::from($span[0]), $span[1], $span[2]), $inner);
        $innerScope = ['type' => $inner[0][0], 'start_time' => $inner[0][1], 'end_time' => end($inner)[2]];
        $proposal = [...$this->proposalOf([
            $this->section(ServiceSectionType::from($innerScope['type']), $innerScope['start_time'], $innerScope['end_time']),
            $this->section(ServiceSectionType::Sermon, $sermonScope[0], $sermonScope[1]),
        ], []), 'disputes' => [
            ['question_id' => 'item-question', 'written' => true, ...$innerScope],
            ['question_id' => 'sermon-question', 'type' => 'sermon', 'written' => true, 'start_time' => $sermonScope[0], 'end_time' => $sermonScope[1]],
        ]];
        $answers = [
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::Sermon, $sermonStart, $sermonEnd)->toArray()]], 'sermon-ruling'),
                'scope' => ['type' => 'sermon', 'start_time' => $sermonScope[0], 'end_time' => $sermonScope[1]]],
            [...$this->ruling(count($inner) > 1 ? 'correct' : 'choose', ['sections' => array_map(static fn (ServiceStructureSection $section): array => $section->toArray(), $innerSections)], 'item-ruling'),
                'scope' => $innerScope],
        ];
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        foreach ([$answers, array_reverse($answers)] as $ordered) {
            $result = $applier->apply($proposal, $ordered);

            $this->assertSame([], $result['conflicting_rulings']);
            $this->assertSame([], $result['disputes']);
            $this->assertCount(2, $result['applied_rulings']);
            $this->assertSame($expected, $this->spans($result));
        }
    }

    /** @return array<string, array<int, mixed>> */
    public static function itemsAnsweredInsideASermon(): array
    {
        return [
            '1027: reading after the preacher\'s introduction' => [
                [['bible_reading', 233.0, 430.0]], [433.0, 2275.0], 0.0, 2275.0,
                [['bible_reading', 233.0, 430.0], ['sermon', 430.0, 2275.0]],
            ],
            '987: reading at the very start' => [
                [['bible_reading', 0.0, 253.0]], [0.0, 1144.0], 0.0, 1144.0,
                [['bible_reading', 0.0, 253.0], ['sermon', 253.0, 1144.0]],
            ],
            '1363: a corrected greeting and talk' => [
                [['other', 889.0, 894.0], ['short_talk', 894.0, 932.0]], [1178.0, 3059.0], 889.0, 3059.0,
                [['other', 889.0, 894.0], ['short_talk', 894.0, 932.0], ['sermon', 932.0, 3059.0]],
            ],
        ];
    }

    /**
     * Full pass, 1285, 1305 and 1269: the operator chose one reading spanning two passages, and
     * the short-readings rule answered that one of those passages is a reading. Both are true:
     * the whole reading includes it, so the part's answer is honoured by the whole one.
     */
    #[Test]
    public function an_answer_about_part_of_a_reading_is_honoured_by_the_answer_about_the_whole(): void
    {
        $proposal = [...$this->proposalOf([
            $this->section(ServiceSectionType::BibleReading, 1172, 1309),
            $this->section(ServiceSectionType::BibleReading, 1309, 1373),
        ], []), 'disputes' => [
            ['question_id' => 'whole', 'type' => 'bible_reading', 'written' => true, 'start_time' => 1172.0, 'end_time' => 1309.0],
            ['question_id' => 'part', 'type' => 'bible_reading', 'written' => true, 'start_time' => 1309.0, 'end_time' => 1373.0],
        ]];
        $answers = [
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::BibleReading, 1162, 1373)->toArray()]], 'whole-ruling'),
                'scope' => ['type' => 'bible_reading', 'start_time' => 1172.0, 'end_time' => 1309.0]],
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::BibleReading, 1309, 1371)->toArray()]], 'part-ruling'),
                'scope' => ['type' => 'bible_reading', 'start_time' => 1309.0, 'end_time' => 1373.0]],
        ];
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        foreach ([$answers, array_reverse($answers)] as $ordered) {
            $result = $applier->apply($proposal, $ordered);

            $this->assertSame([], $result['conflicting_rulings']);
            $this->assertSame([], $result['disputes']);
            $this->assertCount(2, $result['applied_rulings']);
            $this->assertSame([['bible_reading', 1162.0, 1373.0]], $this->spans($result));
        }
    }

    /**
     * Full pass, 1343: the chosen talk ran five seconds past the start of the chosen song. The
     * seam is in both clips; neither answer trims the other.
     */
    #[Test]
    public function a_talk_sharing_a_seam_with_a_song_keeps_its_end(): void
    {
        $proposal = [...$this->proposalOf([
            $this->section(ServiceSectionType::ShortTalk, 218, 486),
            $this->section(ServiceSectionType::Song, 490, 660),
        ], []), 'disputes' => [
            ['question_id' => 'talk', 'type' => 'short_talk', 'written' => true, 'start_time' => 218.0, 'end_time' => 486.0],
            ['question_id' => 'song', 'type' => 'song', 'written' => true, 'start_time' => 490.0, 'end_time' => 660.0],
        ]];
        $answers = [
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::ShortTalk, 218, 486)->toArray()]], 'talk-ruling'),
                'scope' => ['type' => 'short_talk', 'start_time' => 218.0, 'end_time' => 486.0]],
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::Song, 481, 660)->toArray()]], 'song-ruling'),
                'scope' => ['type' => 'song', 'start_time' => 490.0, 'end_time' => 660.0]],
        ];
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        foreach ([$answers, array_reverse($answers)] as $ordered) {
            $result = $applier->apply($proposal, $ordered);

            $this->assertSame([], $result['conflicting_rulings']);
            $this->assertCount(2, $result['applied_rulings']);
            $this->assertSame([['short_talk', 218.0, 486.0], ['song', 481.0, 660.0]], $this->spans($result));
        }
    }

    /** Two answers of different types where one lies mostly inside the other, and neither contains it, stay a clash. */
    #[Test]
    public function a_sermon_answer_crossing_part_of_another_item_stays_unresolved(): void
    {
        $proposal = [...$this->proposalOf([
            $this->section(ServiceSectionType::BibleReading, 233, 430),
            $this->section(ServiceSectionType::Sermon, 433, 2275),
        ], []), 'disputes' => [
            ['question_id' => 'reading', 'type' => 'bible_reading', 'written' => true, 'start_time' => 233.0, 'end_time' => 430.0],
            ['question_id' => 'sermon', 'type' => 'sermon', 'written' => true, 'start_time' => 433.0, 'end_time' => 2275.0],
        ]];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::Sermon, 300, 2275)->toArray()]], 'sermon-ruling'),
                'scope' => ['type' => 'sermon', 'start_time' => 433.0, 'end_time' => 2275.0]],
            [...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::BibleReading, 233, 430)->toArray()]], 'reading-ruling'),
                'scope' => ['type' => 'bible_reading', 'start_time' => 233.0, 'end_time' => 430.0]],
        ]);

        $this->assertCount(2, $result['conflicting_rulings']);
        $this->assertCount(2, $result['disputes']);
    }

    /**
     * Full pass, 1042: the operator chose the version of the closing hymn that starts 3s after
     * the composed one. Inserted beside it, the choice left a 3s sliver of the same hymn and
     * failed validation as a duplicate order-of-service item.
     */
    #[Test]
    public function choosing_an_unwritten_version_replaces_the_composed_section_it_mostly_covers(): void
    {
        $hymn = new ServiceStructureSection(ServiceSectionType::Song, 'Hail the Day', 4140.0, 4332.1, 0.95, 4790, null, null);
        $chosen = new ServiceStructureSection(ServiceSectionType::Song, 'Hail the Day That Sees Him Rise', 4143.3, 4332.1, 0.95, 4790, null, null);
        $proposal = $this->proposalOf([$this->section(ServiceSectionType::Sermon, 2000, 4100), $hymn],
            ['type' => 'song', 'written' => false, 'start_time' => 4143.3, 'end_time' => 4332.1]);

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [
            [...$this->ruling('choose', ['sections' => [$chosen->toArray()]]), 'scope' => ['type' => 'song', 'start_time' => 4143.3, 'end_time' => 4332.1]],
        ]);

        $this->assertSame([['sermon', 2000.0, 4100.0], ['song', 4143.3, 4332.1]], $this->spans($result));
        $this->assertSame([], $result['disputes']);
    }

    /**
     * Full pass, 1004: "either version is fine" names the section the question was about, but
     * the edge rules had since moved its end (4191.9s to 4184.2s), so an exact match found
     * nothing and the answer was refused.
     *
     * @param  array{0: float, 1: float}  $asked
     */
    #[Test]
    #[DataProvider('sectionsAQuestionNames')]
    public function a_question_names_its_section_by_its_times_or_the_closest_fit_of_its_type(array $asked, ?int $expected): void
    {
        $sections = [
            $this->section(ServiceSectionType::Song, 3900, 4110),
            $this->section(ServiceSectionType::Song, 4120, 4184.2),
            $this->section(ServiceSectionType::Prayer, 4120, 4191.9),
        ];

        $this->assertSame($expected, ServiceStructureEnsembleRulingApplier::sectionFor($sections, ['type' => 'song', 'start_time' => $asked[0], 'end_time' => $asked[1]]));
    }

    /** @return array<string, array<int, mixed>> */
    public static function sectionsAQuestionNames(): array
    {
        return [
            'exact times' => [[3900.0, 4110.0], 0],
            'an edge moved after the question' => [[4120.0, 4191.9], 1],
            'mostly another section' => [[4000.0, 4300.0], null],
        ];
    }

    #[Test]
    public function a_question_equally_close_to_two_sections_names_neither(): void
    {
        $sections = [$this->section(ServiceSectionType::Song, 100, 200), $this->section(ServiceSectionType::Song, 200, 300)];

        $this->assertNull(ServiceStructureEnsembleRulingApplier::sectionFor($sections, ['type' => 'song', 'start_time' => 100.0, 'end_time' => 300.0]));
        $this->assertSame(0, ServiceStructureEnsembleRulingApplier::sectionFor($sections, ['type' => 'song', 'start_time' => 100.0, 'end_time' => 290.0]));
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

    /**
     * 987: the composition flagged the sermon because no reading was written; the operator's
     * answer wrote the reading it preached, so the flag no longer holds.
     */
    #[Test]
    public function an_answered_reading_clears_the_missing_preached_reading_flag(): void
    {
        $reading = new ServiceStructureSection(ServiceSectionType::BibleReading, null, 100, 200, 0.95, null, null, 'Luke 15:11-32');
        $sermon = (new ServiceStructureSection(ServiceSectionType::Sermon, null, 250, 500, 0.95, null, null, null, 'Luke 15:11-32'))
            ->withReviewFlags([ServiceStructureValidator::FLAG_MISSING_PREACHED_READING]);
        $proposal = $this->proposalOf([$sermon], ['type' => 'bible_reading', 'written' => false, 'start_time' => 100.0, 'end_time' => 200.0]);

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [[
            ...$this->ruling('choose', ['sections' => [$reading->toArray()]], 'reading-ruling'),
            'scope' => ['type' => 'bible_reading', 'start_time' => 100.0, 'end_time' => 200.0],
        ]]);

        $this->assertSame([['bible_reading', 100.0, 200.0], ['sermon', 250.0, 500.0]], $this->spans($result));
        $this->assertNotContains(ServiceStructureValidator::FLAG_MISSING_PREACHED_READING, $result['structure']['sections'][1]['review_flags']);
    }

    /** The other way: an answer removing the only reading before the sermon leaves it bare. */
    #[Test]
    public function an_answer_removing_the_preached_reading_flags_the_sermon(): void
    {
        $reading = new ServiceStructureSection(ServiceSectionType::BibleReading, null, 100, 200, 0.95, null, null, 'Luke 15:11-32');
        $sermon = new ServiceStructureSection(ServiceSectionType::Sermon, null, 250, 500, 0.95, null, null, null, 'Luke 15:11-32');
        $proposal = $this->proposalOf([$reading, $sermon], ['type' => 'bible_reading', 'written' => true, 'start_time' => 100.0, 'end_time' => 200.0]);

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [[
            ...$this->ruling('remove', ['absent' => true], 'reading-ruling'),
            'scope' => ['type' => 'bible_reading', 'start_time' => 100.0, 'end_time' => 200.0],
        ]]);

        $this->assertSame([['sermon', 250.0, 500.0]], $this->spans($result));
        $this->assertContains(ServiceStructureValidator::FLAG_MISSING_PREACHED_READING, $result['structure']['sections'][0]['review_flags']);
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
     * An answered prayer keeps the composition's untranscribed-speech question and its interval.
     */
    #[Test]
    public function a_chosen_version_keeps_the_interval_of_an_untranscribed_speech_question(): void
    {
        $note = UntranscribedSpeechBeforeSection::note(2690.0, 2706.4);
        $proposal = $this->proposalOf(
            [$this->section(ServiceSectionType::Prayer, 2706.4, 2800)->withReviewFlags([
                ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES,
                ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION,
            ], ['The composed draft\'s own note.', $note])],
            ['type' => 'prayer', 'written' => true, 'start_time' => 2706.4, 'end_time' => 2800.0],
        );

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [[
            ...$this->ruling('choose', ['sections' => [$this->section(ServiceSectionType::Prayer, 2706.4, 2800)->toArray()]], key: 'prayer'),
            'scope' => ['type' => 'prayer', 'start_time' => 2706.4, 'end_time' => 2800.0],
        ]]);

        $prayer = $result['structure']['sections'][0];
        $this->assertContains(ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION, $prayer['review_flags']);
        $this->assertSame([$note], $prayer['notes']);
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
     * Canary 12, 949 (operator, 2026-10-06): "It's really one question: is the intro and reading
     * part of the sermon or not?" Answered "leave it out" — the reading is part of the sermon —
     * yet the sermon kept `structure_sermon_interruption_merged`, which asks the same thing, and
     * stayed parked. The answer settles that flag, and only that flag, on that sermon, and keeps
     * settling it when the answers are applied again on recomposition.
     */
    #[Test]
    public function leaving_out_a_reading_the_sermon_absorbed_settles_its_interruption_flag(): void
    {
        $proposal = $this->interruptedSermon();
        $answer = $this->readingAnswer('accept', ['absent' => true]);
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        $result = $applier->apply($proposal, [$answer]);
        $sermon = $this->sermonOf($result);

        $this->assertNotContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $sermon['review_flags']);
        $this->assertContains('unrelated_review', $sermon['review_flags']);
        $this->assertContains(ServiceStructureEnsembleRulingApplier::interruptionSettledNote('bible_reading', 2931.0, 2982.0), $sermon['notes']);
        $this->assertSame([], $result['disputes']);
        $this->assertSame([['song', 2459.81, 2690.0], ['sermon', 2690.1, 4620.0]], $this->spans($result), 'no bounds change');
        $this->assertTrue(SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction(false, array_values(array_diff($sermon['review_flags'], ['unrelated_review']))));

        $again = $applier->apply($proposal, [$answer]);
        $this->assertSame($result['structure'], $again['structure'], 'recomposition applies the same answer the same way');
    }

    /** Choosing the separate reading does not say the merge was right: the flag stays. */
    #[Test]
    public function choosing_a_separate_reading_leaves_the_interruption_flag(): void
    {
        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($this->interruptedSermon(), [$this->readingAnswer('choose', [
            'sections' => [$this->section(ServiceSectionType::BibleReading, 2931.0, 2982.0)->toArray()],
        ])]);

        $sermons = array_values(array_filter($result['structure']['sections'], static fn (array $section): bool => $section['type'] === 'sermon'));
        $this->assertTrue(array_any($sermons, static fn (array $section): bool => in_array(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $section['review_flags'], true)));
    }

    /** A note naming another interruption, or a reading outside the sermon, settles nothing here. */
    #[Test]
    public function an_answer_about_a_different_interruption_leaves_the_flag(): void
    {
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        $prayer = $applier->apply($this->interruptedSermon('prayer'), [$this->readingAnswer('accept', ['absent' => true])]);
        $outside = $applier->apply($this->interruptedSermon(readingAt: [1000.0, 1050.0]), [$this->readingAnswer('accept', ['absent' => true], [1000.0, 1050.0])]);

        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $this->sermonOf($prayer)['review_flags']);
        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $this->sermonOf($outside)['review_flags']);
    }

    /**
     * Codex review of the §6.1 build: a sermon merged across two readings, one answered, kept the
     * other's doubt only by accident of matching. The answer settles its own occurrence; the flag
     * goes when every recorded occurrence is settled, and stays where the merge recorded none.
     */
    #[Test]
    public function answering_one_of_two_interruptions_leaves_the_flag_for_the_other(): void
    {
        $applier = app(ServiceStructureEnsembleRulingApplier::class);
        $two = [['bible_reading', 2931.0, 2982.0], ['bible_reading', 3500.0, 3560.0]];

        $one = $applier->apply($this->interruptedSermon(occurrences: $two), [$this->readingAnswer('accept', ['absent' => true])]);
        $legacy = $applier->apply($this->interruptedSermon(occurrences: []), [$this->readingAnswer('accept', ['absent' => true])]);

        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $this->sermonOf($one)['review_flags']);
        $this->assertContains(ServiceStructureEnsembleRulingApplier::interruptionSettledNote('bible_reading', 2931.0, 2982.0), $this->sermonOf($one)['notes']);
        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $this->sermonOf($legacy)['review_flags'], 'nothing recorded, nothing settled');
    }

    /**
     * Codex review: an answer about a moment inside a long reading is not an answer about the
     * reading. It settles an occurrence only by covering most of it.
     */
    #[Test]
    public function an_answer_about_a_fragment_of_an_interruption_does_not_settle_it(): void
    {
        $proposal = $this->interruptedSermon(readingAt: [2950.0, 2951.0], occurrences: [['bible_reading', 2931.0, 2991.0]]);

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [$this->readingAnswer('accept', ['absent' => true], [2950.0, 2951.0])]);

        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $this->sermonOf($result)['review_flags']);
        $this->assertNotContains(ServiceStructureEnsembleRulingApplier::interruptionSettledNote('bible_reading', 2931.0, 2991.0), $this->sermonOf($result)['notes']);
    }

    #[Test]
    public function answering_every_interruption_settles_the_flag(): void
    {
        $proposal = $this->interruptedSermon(occurrences: [['bible_reading', 2931.0, 2982.0], ['bible_reading', 3500.0, 3560.0]]);
        $proposal['disputes'][] = ['question_id' => 'second-question', 'type' => 'bible_reading', 'written' => false, 'start_time' => 3500.0, 'end_time' => 3560.0, 'absent_slots' => [0, 1]];
        $second = [...$this->readingAnswer('accept', ['absent' => true], [3500.0, 3560.0]), 'ruling_key' => 'second-ruling'];

        $result = app(ServiceStructureEnsembleRulingApplier::class)->apply($proposal, [$this->readingAnswer('accept', ['absent' => true]), $second]);

        $this->assertNotContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $this->sermonOf($result)['review_flags']);
    }

    /**
     * One absorbed reading settled by evidence at the merge and the other by an answer: each is
     * settled, so the flag goes. The evidence for one never settles the other unasked.
     */
    #[Test]
    public function an_answer_and_evidence_together_settle_every_interruption(): void
    {
        $proposal = $this->interruptedSermon(occurrences: [['bible_reading', 2931.0, 2982.0], ['bible_reading', 3500.0, 3560.0]]);
        $structure = ServiceStructure::fromArray($proposal['structure']);
        $sermon = $structure->sections[1];
        $evidenced = $sermon->withReviewFlags([], [SilenceSnapService::settledByEvidenceNote('bible_reading', 3500.0, 3560.0, 'the sermon names Numbers 21:4-9 before reading it')]);
        $proposal['structure'] = ServiceStructure::fromSections([$structure->sections[0], $evidenced])->toArray();
        $applier = app(ServiceStructureEnsembleRulingApplier::class);

        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $this->sermonOf($applier->apply($proposal, []))['review_flags'], 'the other reading is still in question');
        $this->assertNotContains(ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, $this->sermonOf($applier->apply($proposal, [$this->readingAnswer('accept', ['absent' => true])]))['review_flags']);
    }

    /**
     * @param  array{0: float, 1: float}  $readingAt
     * @param  list<array{0: string, 1: float, 2: float}>|null  $occurrences  The interruptions the merge recorded
     * @return array<string, mixed>
     */
    private function interruptedSermon(string $interruption = 'bible_reading', array $readingAt = [2931.0, 2982.0], ?array $occurrences = null): array
    {
        $song = $this->section(ServiceSectionType::Song, 2459.81, 2690.0);
        $sermon = $this->section(ServiceSectionType::Sermon, 2690.1, 4620.0)
            ->withReviewFlags([ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED, 'unrelated_review'], [
                "Sermon interrupted by {$interruption} and resumed; merged 4 following sections into one sermon (2690.1s-4599.2s).",
                ...array_map(static fn (array $occurrence): string => SilenceSnapService::interruptionNote(...$occurrence), $occurrences ?? [[$interruption, 2931.0, 2982.0]]),
            ]);

        return [
            'source_hash' => 'source-a',
            'attempt_id' => 'attempt-1',
            'structure' => ServiceStructure::fromSections([$song, $sermon])->toArray(),
            'disputes' => [[
                'question_id' => 'reading-question',
                'type' => 'bible_reading',
                'written' => false,
                'start_time' => $readingAt[0],
                'end_time' => $readingAt[1],
                'absent_slots' => [2, 3],
            ]],
            'degraded' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @param  array{0: float, 1: float}  $span
     * @return array<string, mixed>
     */
    private function readingAnswer(string $kind, array $resolution, array $span = [2931.0, 2982.0]): array
    {
        return [
            'ruling_key' => 'reading-ruling',
            'revision' => 1,
            'source_hash' => 'source-a',
            'scope' => ['type' => 'bible_reading', 'start_time' => $span[0], 'end_time' => $span[1]],
            'kind' => $kind,
            'resolution' => $resolution,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function sermonOf(array $result): array
    {
        return collect($result['structure']['sections'])->firstWhere('type', 'sermon');
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
