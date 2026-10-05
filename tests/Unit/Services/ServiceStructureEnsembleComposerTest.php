<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSermonAbsence;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Enums\TalkType;
use App\Services\ChurchService\Structure\OutputEdgeReview;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleComposer;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SongSpeechEdges;
use App\Services\ChurchService\Structure\TalkEdgeChecks;
use App\Services\ChurchService\Structure\UntranscribedSpeechBeforeSection;
use App\Services\ChurchService\Structure\ValidationResult;
use App\Services\Song\SongTitleResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureEnsembleComposerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SongTitleResolver::class, SongTitleResolver::fromRows([
            ['id' => 304, 'canonical_key' => 'god we praise you 177', 'title' => 'God We Praise You #177', 'praise_number' => '177', 'first_line_key' => 'God, we praise you, God, we bless you'],
            ['id' => 991, 'canonical_key' => 'we have heard a joyful sound', 'title' => 'We Have Heard A Joyful Sound', 'alternate_title' => 'Jesus Saves'],
            ['id' => 1, 'canonical_key' => 'amazing grace', 'title' => 'Amazing Grace'],
            ['id' => 2, 'canonical_key' => 'be thou my vision', 'title' => 'Be Thou My Vision'],
        ]));
    }

    /** The shape of runs 949, 964, 1221, 1356 and 1358 in canary 9: one passage cited at two granularities. */
    #[Test]
    public function a_talk_end_question_does_not_block_a_neighbour_touching_only_its_start(): void
    {
        $question = ['check' => TalkEdgeChecks::CHECK,
            'type' => 'short_talk', 'edges' => ['end'], 'start_time' => 100.0, 'end_time' => 200.0];
        $this->assertFalse(OutputEdgeReview::touches($question, [['start_time' => 0.0, 'end_time' => 100.0]]));
        $this->assertTrue(OutputEdgeReview::touches($question, [['start_time' => 100.0, 'end_time' => 200.0]]));
        $this->assertTrue(OutputEdgeReview::touches($question, [['start_time' => 200.0, 'end_time' => 500.0]]));
    }

    #[Test]
    public function overlapping_references_are_one_passage_unless_they_pair_the_sermon_differently(): void
    {
        $draw = fn (string $readingReference, string $sermonReference): ServiceStructure => $this->structure(
            $this->section(ServiceSectionType::BibleReading, 100, 200, $readingReference),
            new ServiceStructureSection(ServiceSectionType::Sermon, null, 1000.0, 3000.0, 0.9, null, null, null, $sermonReference),
        );
        $votes = fn (array $first, array $second): array => [
            0 => $this->vote($draw(...$first)),
            1 => $this->vote($draw(...$first)),
            2 => $this->vote($draw(...$second)),
            3 => $this->vote($draw(...$second)),
        ];
        $composer = app(ServiceStructureEnsembleComposer::class);

        $granularity = $composer->compose($votes(['Psalm 95', 'Philippians 3:4-9'], ['Psalm 95:1-7', 'Philippians 3:4b-9']));
        $differentPassages = $composer->compose($votes(['Acts 17:22-31', 'Acts 17:22-25'], ['Acts 17:22-31', '1 Peter 4:7-11']));
        $differentPairing = $composer->compose($votes(['John 19:1-16', 'John 19'], ['John 19:1-16', 'John 19:17-42']));

        $this->assertSame([], $granularity->disputes);
        $this->assertSame(['sermon'], array_column($differentPassages->disputes, 'type'));
        $this->assertSame(['sermon'], array_column($differentPairing->disputes, 'type'));
    }

    /**
     * F08: John 8:12-25 holds the whole reading John 8:12-20, so extraction pairs them; John
     * 8:18-30 only crosses it, so extraction asks. The two references overlap each other and
     * every counterpart, but they do not cut the same reading.
     */
    #[Test]
    public function sermon_references_that_change_reading_membership_disagree(): void
    {
        $draw = fn (string $sermonReference): ServiceStructure => $this->structure(
            $this->section(ServiceSectionType::BibleReading, 1314, 1394, 'John 8:12-20'),
            new ServiceStructureSection(ServiceSectionType::Sermon, null, 1690.0, 3500.0, 0.9, null, null, null, $sermonReference),
        );

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw('John 8:12-25')),
            1 => $this->vote($draw('John 8:12-25')),
            2 => $this->vote($draw('John 8:18-30')),
            3 => $this->vote($draw('John 8:18-30')),
        ]);

        $this->assertSame(['sermon'], array_column($result->disputes, 'type'));
    }

    /**
     * F02: extraction pairs a matching reading however long before the sermon it ends, so the
     * composer holds that reading to its edges too; a reading's lost verse is a question.
     */
    #[Test]
    public function a_matching_reading_long_before_the_sermon_is_held_to_its_edges(): void
    {
        $draw = fn (int $readingEnd): ServiceStructure => $this->structure(
            $this->section(ServiceSectionType::BibleReading, 100, $readingEnd, 'John 3'),
            new ServiceStructureSection(ServiceSectionType::Sermon, null, 2000.0, 4000.0, 0.9, null, null, null, 'John 3:16'),
        );

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(300)),
            1 => $this->vote($draw(300)),
            2 => $this->vote($draw(270)),
            3 => $this->vote($draw(270)),
        ]);

        $this->assertContains('bible_reading', array_column($result->disputes, 'type'));
    }

    /** The shape of runs 964, 1304 and 1356 in canary 9; 1311 and 1304 ruled an untitled song both ways. */
    #[Test]
    public function unbound_titles_naming_one_catalogued_song_are_one_song(): void
    {
        $votes = function (?string $first, ?string $second): array {
            $draw = fn (?string $title): ServiceStructure => $this->structure(
                new ServiceStructureSection(ServiceSectionType::Song, null, 100.0, 200.0, 0.9, null, $title, null),
                $this->section(ServiceSectionType::Sermon, 1000, 3000),
            );

            return [0 => $this->vote($draw($first)), 1 => $this->vote($draw($first)), 2 => $this->vote($draw($second)), 3 => $this->vote($draw($second))];
        };
        $composer = app(ServiceStructureEnsembleComposer::class);

        $this->assertSame([], $composer->compose($votes('Jesus Saves', 'We Have Heard a Joyful Sound (Jesus Saves)'))->disputes);
        $this->assertSame([], $composer->compose($votes('God, we praise you, God, we bless you', 'God, We Praise You'))->disputes);
        $this->assertSame(['song'], array_column($composer->compose($votes('Amazing Grace', 'Be Thou My Vision'))->disputes, 'type'));
        $this->assertSame(['song'], array_column($composer->compose($votes('Everlasting God', 'Strength Will Rise'))->disputes, 'type'));
        $this->assertSame(['song'], array_column($composer->compose($votes('Amazing Grace', null))->disputes, 'type'));
    }

    /** The shape of run 936 in canary 9: the operator confirms a talk's type in its own review. */
    #[Test]
    public function a_proposed_talk_type_is_left_to_talk_type_review(): void
    {
        $draw = fn (?TalkType $talkType): ServiceStructure => $this->structure(
            new ServiceStructureSection(ServiceSectionType::ShortTalk, null, 1495.0, 2103.0, 0.9, 3636, null, null, talkType: $talkType),
            $this->section(ServiceSectionType::Sermon, 2600, 3800),
        );

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(TalkType::PartnerUpdate)),
            1 => $this->vote($draw(TalkType::PartnerUpdate)),
            2 => $this->vote($draw(null)),
            3 => $this->vote($draw(null)),
        ]);

        $this->assertSame([], $result->disputes);
        $this->assertCount(1, $result->structure->sectionsOfType(ServiceSectionType::ShortTalk));
    }

    /**
     * Canary 9 and the 232 §6 draws: where the truth was ruled, a three-to-one vote was never
     * wrong about a talk or sermon. The majority decides; each decision is kept for a skim list
     * and stays answerable, but holds nothing.
     */
    #[Test]
    public function a_three_to_one_majority_decides_and_is_recorded_for_the_skim_list(): void
    {
        $draw = fn (int $talkEnd): ServiceStructure => $this->structure(
            $this->section(ServiceSectionType::ShortTalk, 632, $talkEnd),
            $this->section(ServiceSectionType::Sermon, 2454, 4310),
        );

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(985)),
            1 => $this->vote($draw(1018)),
            2 => $this->vote($draw(985)),
            3 => $this->vote($draw(985)),
        ]);
        $talks = $result->structure->sectionsOfType(ServiceSectionType::ShortTalk);

        $this->assertSame([], $result->disputes);
        $this->assertFalse($result->requiresReview());
        $this->assertSame(985.0, $talks[0]->endTime);
        $this->assertNotContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $talks[0]->reviewFlags);
        $this->assertNotContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $result->structure->sectionsOfType(ServiceSectionType::Sermon)[0]->reviewFlags);
        $this->assertCount(1, $result->majorityDecisions);
        $this->assertSame('short_talk', $result->majorityDecisions[0]['type']);
        $this->assertCount(2, $result->majorityDecisions[0]['alternatives']);
        $this->assertIsString($result->majorityDecisions[0]['question_id']);
    }

    #[Test]
    public function one_voter_cannot_write_an_unflagged_talk(): void
    {
        $base = $this->structure($this->section(ServiceSectionType::Prayer, 0, 100));
        $invented = $this->structure(
            $this->section(ServiceSectionType::Prayer, 0, 100),
            $this->section(ServiceSectionType::ShortTalk, 120, 300),
        );

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($invented),
            1 => $this->vote($base),
            2 => $this->vote($base),
            3 => $this->vote($base),
        ]);

        $this->assertSame([], $result->structure->sectionsOfType(ServiceSectionType::ShortTalk));
        $this->assertNotContains('short_talk', array_column($result->disputes, 'type'));
        $this->assertSame(['short_talk'], array_column($result->majorityDecisions, 'type'));
        $this->assertFalse($result->majorityDecisions[0]['written']);
    }

    #[Test]
    public function unanimous_boundaries_select_a_supported_complete_pair(): void
    {
        $votes = [
            0 => $this->vote($this->structure($this->section(ServiceSectionType::Sermon, 100, 500))),
            1 => $this->vote($this->structure($this->section(ServiceSectionType::Sermon, 104, 496))),
            2 => $this->vote($this->structure($this->section(ServiceSectionType::Sermon, 105, 495))),
            3 => $this->vote($this->structure($this->section(ServiceSectionType::Sermon, 108, 492))),
        ];

        $result = app(ServiceStructureEnsembleComposer::class)->compose($votes);

        $this->assertSame([104.0, 496.0], [
            $result->structure->sections[0]->startTime,
            $result->structure->sections[0]->endTime,
        ]);
        $this->assertSame([], $result->disputes);
        $this->assertCount(1, $result->structure->chapterMarkers);
    }

    #[Test]
    public function two_valid_votes_are_degraded_even_when_they_agree(): void
    {
        $vote = $this->vote($this->structure($this->section(ServiceSectionType::Sermon, 100, 500)));
        $result = app(ServiceStructureEnsembleComposer::class)->compose([0 => $vote, 1 => $vote]);

        $this->assertTrue($result->degraded);
        $this->assertTrue($result->requiresReview());
        $this->assertContains(ServiceStructureValidator::FLAG_ENSEMBLE_DEGRADED, $result->structure->sections[0]->reviewFlags);
    }

    #[Test]
    public function fewer_than_two_valid_votes_refuse_composition(): void
    {
        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($this->structure($this->section(ServiceSectionType::Sermon, 100, 500))),
        ]);

        $this->assertTrue($result->refused);
        $this->assertTrue($result->requiresReview());
    }

    #[Test]
    public function a_two_two_identity_split_uses_the_first_slot_and_flags_the_result(): void
    {
        $first = $this->structure($this->section(ServiceSectionType::BibleReading, 100, 200, 'John 1'));
        $second = $this->structure($this->section(ServiceSectionType::BibleReading, 100, 200, 'Luke 1'));

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            3 => $this->vote($second),
            2 => $this->vote($second),
            1 => $this->vote($first),
            0 => $this->vote($first),
        ]);

        $this->assertSame('John 1', $result->structure->sections[0]->readingReference);
        $this->assertContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $result->structure->sections[0]->reviewFlags);
    }

    #[Test]
    public function pairwise_matching_does_not_turn_a_non_transitive_chain_into_agreement(): void
    {
        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($this->structure($this->section(ServiceSectionType::ShortTalk, 100, 200))),
            1 => $this->vote($this->structure($this->section(ServiceSectionType::ShortTalk, 125, 200))),
            2 => $this->vote($this->structure($this->section(ServiceSectionType::ShortTalk, 150, 200))),
            3 => $this->vote($this->structure($this->section(ServiceSectionType::ShortTalk, 150, 200))),
        ]);

        $this->assertNotEmpty($result->disputes);
        $this->assertCount(1, $result->structure->sectionsOfType(ServiceSectionType::ShortTalk));
        $this->assertContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $result->structure->sections[0]->reviewFlags);
    }

    #[Test]
    public function sermon_prayer_equivalence_selects_a_supported_start(): void
    {
        $before = $this->structure(
            $this->section(ServiceSectionType::Sermon, 100, 500),
        );
        $after = $this->structure(
            $this->section(ServiceSectionType::Prayer, 100, 150),
            $this->section(ServiceSectionType::Sermon, 150, 500),
        );

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($before), 1 => $this->vote($before),
            2 => $this->vote($after), 3 => $this->vote($after),
        ]);

        $this->assertSame([], $result->disputes);
        $this->assertContains($result->structure->sectionsOfType(ServiceSectionType::Sermon)[0]->startTime, [100.0, 150.0]);
    }

    #[Test]
    public function a_song_between_sermon_starts_prevents_prayer_equivalence(): void
    {
        $before = $this->structure($this->section(ServiceSectionType::Sermon, 100, 500));
        $after = $this->structure(
            $this->section(ServiceSectionType::Prayer, 100, 150),
            $this->section(ServiceSectionType::Sermon, 150, 500),
        );
        $song = $this->structure(
            $this->section(ServiceSectionType::Song, 100, 150),
            $this->section(ServiceSectionType::Sermon, 150, 500),
        );

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($before), 1 => $this->vote($before),
            2 => $this->vote($after), 3 => $this->vote($song),
        ]);

        $this->assertNotEmpty($result->disputes);
        $this->assertTrue($result->requiresReview());
    }

    #[Test]
    public function an_ambiguous_one_to_one_alignment_is_reported(): void
    {
        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($this->structure(
                $this->section(ServiceSectionType::ShortTalk, 105, 120),
                $this->section(ServiceSectionType::ShortTalk, 120, 140),
            )),
            1 => $this->vote($this->structure($this->section(ServiceSectionType::ShortTalk, 125, 135))),
            2 => $this->vote($this->structure($this->section(ServiceSectionType::ShortTalk, 125, 135))),
            3 => $this->vote($this->structure($this->section(ServiceSectionType::ShortTalk, 125, 135))),
        ]);

        $this->assertContains('alignment', array_column($result->disputes, 'type'));
        $this->assertTrue($result->requiresReview());
    }

    #[Test]
    public function completion_order_and_slot_array_order_cannot_change_a_tie(): void
    {
        $first = $this->vote($this->structure($this->section(ServiceSectionType::ShortTalk, 100, 200)));
        $second = $this->vote($this->structure($this->section(ServiceSectionType::ShortTalk, 100, 230)));
        $composer = app(ServiceStructureEnsembleComposer::class);
        $forward = $composer->compose([0 => $first, 1 => $second, 2 => $second, 3 => $first]);
        $reverse = $composer->compose([3 => $first, 2 => $second, 1 => $second, 0 => $first]);

        $this->assertSame($forward->structure->toArray(), $reverse->structure->toArray());
        $this->assertSame($forward->disputes, $reverse->disputes);
    }

    #[Test]
    public function disputed_song_boundary_does_not_hold_an_unrelated_sermon(): void
    {
        $early = $this->structure(
            $this->section(ServiceSectionType::Sermon, 100, 500),
            $this->section(ServiceSectionType::Song, 510, 600),
        );
        $late = $this->structure(
            $this->section(ServiceSectionType::Sermon, 100, 500),
            $this->section(ServiceSectionType::Song, 550, 640),
        );

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($early), 1 => $this->vote($early),
            2 => $this->vote($late), 3 => $this->vote($late),
        ]);

        $this->assertNotEmpty($result->disputes);
        $this->assertNotContains(
            ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES,
            $result->structure->sectionsOfType(ServiceSectionType::Sermon)[0]->reviewFlags,
        );
    }

    #[Test]
    public function equivalent_scripture_renderings_do_not_create_a_reference_dispute(): void
    {
        $first = $this->vote($this->structure(
            $this->section(ServiceSectionType::BibleReading, 100, 200, 'John 3:16-18'),
            $this->section(ServiceSectionType::Sermon, 210, 500),
        ));
        $second = $this->vote($this->structure(
            $this->section(ServiceSectionType::BibleReading, 100, 200, 'John 3:16–18'),
            $this->section(ServiceSectionType::Sermon, 210, 500),
        ));

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $first, 1 => $second, 2 => $first, 3 => $second,
        ]);

        $this->assertSame([], $result->disputes);
        $this->assertCount(2, $result->structure->sections);
    }

    #[Test]
    public function decorated_and_plain_sung_titles_share_one_identity(): void
    {
        $plain = $this->vote($this->structure(
            new ServiceStructureSection(ServiceSectionType::Song, null, 100.0, 200.0, 0.9, null, 'Amazing Grace', null),
            $this->section(ServiceSectionType::Sermon, 210, 500),
        ));
        $decorated = $this->vote($this->structure(
            new ServiceStructureSection(ServiceSectionType::Song, null, 100.0, 200.0, 0.9, null, 'Closing hymn: Amazing Grace', null),
            $this->section(ServiceSectionType::Sermon, 210, 500),
        ));

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $plain, 1 => $decorated, 2 => $plain, 3 => $decorated,
        ]);

        $this->assertSame([], $result->disputes);
        $this->assertSame('Amazing Grace', $result->structure->sectionsOfType(ServiceSectionType::Song)[0]->songTitle);
    }

    /**
     * The shape of runs 949 and 936 in the 2026-09-30 evaluation: one voter starts the reading
     * at the leader's introduction, three at the first verse. The reading is included with its
     * introduction when that names the passage, and starts after it when it does not.
     */
    #[Test]
    public function a_reading_starts_with_an_introduction_that_names_its_passage_and_after_one_that_does_not(): void
    {
        $draw = fn (int $readingStart): ServiceStructure => $this->structure(
            $this->section(ServiceSectionType::BibleReading, $readingStart, 2447, 'John 19'),
            $this->section(ServiceSectionType::Sermon, 2500, 4600),
        );
        $votes = [
            0 => $this->vote($draw(2209)),
            1 => $this->vote($draw(2209)),
            2 => $this->vote($draw(2226)),
            3 => $this->vote($draw(2226)),
        ];
        $transcript = fn (string $introduction): ChurchServiceTranscript => ChurchServiceTranscript::fromCues([
            ['start' => 2209.0, 'end' => 2225.0, 'text' => $introduction],
            ['start' => 2226.0, 'end' => 2447.0, 'text' => 'Then Pilate took Jesus and had him flogged.'],
            ['start' => 2500.0, 'end' => 4600.0, 'text' => 'Behold the man.'],
        ], 4700.0, ChurchServiceTranscript::SOURCE_MOCK);
        $composer = app(ServiceStructureEnsembleComposer::class);

        $named = $composer->compose($votes, $transcript("We're going to be reading from John and chapter 19."));
        $unnamed = $composer->compose($votes, $transcript('Peter will come and do his readings. Thank you, Laurie.'));
        $unheard = $composer->compose($votes);

        $this->assertSame([], $named->disputes);
        $this->assertSame(2209.0, $named->structure->sectionsOfType(ServiceSectionType::BibleReading)[0]->startTime);
        $this->assertSame([], $unnamed->disputes);
        $this->assertSame(2226.0, $unnamed->structure->sectionsOfType(ServiceSectionType::BibleReading)[0]->startTime);
        $this->assertContains('bible_reading', array_column($unheard->disputes, 'type'));
    }

    #[Test]
    public function a_numbered_book_is_recognised_in_its_spoken_form_and_a_song_in_the_gap_is_no_introduction(): void
    {
        $draw = fn (int $readingStart, bool $song = false): ServiceStructure => $this->structure(...array_values(array_filter([
            $song ? $this->song(2200, 2220, 'Amazing Grace') : null,
            $this->section(ServiceSectionType::BibleReading, $readingStart, 2447, '1 Corinthians 13'),
            $this->section(ServiceSectionType::Sermon, 2500, 4600),
        ])));
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 2190.0, 'end' => 2225.0, 'text' => 'Our reading this morning is from First Corinthians thirteen.'],
            ['start' => 2226.0, 'end' => 2447.0, 'text' => 'If I speak in the tongues of men.'],
        ], 4700.0, ChurchServiceTranscript::SOURCE_MOCK);
        $composer = app(ServiceStructureEnsembleComposer::class);

        $spoken = $composer->compose([
            0 => $this->vote($draw(2190)), 1 => $this->vote($draw(2190)), 2 => $this->vote($draw(2226)), 3 => $this->vote($draw(2226)),
        ], $transcript);
        $sung = $composer->compose([
            0 => $this->vote($draw(2190, true)), 1 => $this->vote($draw(2190, true)), 2 => $this->vote($draw(2226, true)), 3 => $this->vote($draw(2226, true)),
        ], $transcript);

        $this->assertSame(2190.0, $spoken->structure->sectionsOfType(ServiceSectionType::BibleReading)[0]->startTime);
        $this->assertContains('bible_reading', array_column($sung->disputes, 'type'));
    }

    /** The shape of run 1025 in the 2026-09-30 evaluation: one item, its title spelt two ways. */
    #[Test]
    public function a_song_bound_to_one_order_of_service_item_is_one_song_however_its_title_is_spelt(): void
    {
        $song = fn (string $title, ?int $item): ServiceStructure => $this->structure(
            new ServiceStructureSection(ServiceSectionType::Song, null, 100.0, 200.0, 0.9, $item, $title, null),
            $this->section(ServiceSectionType::Sermon, 210, 500),
        );

        $bound = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
            1 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
            2 => $this->vote($song('Praise My Soul, the King of Heaven', 4860)),
            3 => $this->vote($song('Praise My Soul, the King of Heaven', 4860)),
        ]);
        $unbound = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($song('Praise My Soul The King Of Heaven', null)),
            1 => $this->vote($song('Praise My Soul The King Of Heaven', null)),
            2 => $this->vote($song('Bless the Lord, O my soul', null)),
            3 => $this->vote($song('Bless the Lord, O my soul', null)),
        ]);
        $differentItems = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
            1 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
            2 => $this->vote($song('Praise My Soul The King Of Heaven', 4861)),
            3 => $this->vote($song('Praise My Soul The King Of Heaven', 4861)),
        ]);
        $boundAndUnbound = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
            1 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
            2 => $this->vote($song('Praise My Soul The King Of Heaven', null)),
            3 => $this->vote($song('Praise My Soul The King Of Heaven', null)),
        ]);

        $this->assertSame([], $bound->disputes);
        $this->assertSame(4860, $bound->structure->sectionsOfType(ServiceSectionType::Song)[0]->oosItemId);
        $this->assertContains('song', array_column($unbound->disputes, 'type'));
        $this->assertContains('song', array_column($differentItems->disputes, 'type'));
        $this->assertContains('song', array_column($boundAndUnbound->disputes, 'type'));
    }

    #[Test]
    public function no_sermon_is_clean_only_when_each_voter_explicitly_asserts_absence(): void
    {
        $asserted = ServiceStructure::fromSections(
            [$this->section(ServiceSectionType::Prayer, 0, 100)],
            sermonAbsence: new ServiceSermonAbsence(null, 'Prayer meeting without a sermon.'),
        );
        $unasserted = $this->structure($this->section(ServiceSectionType::Prayer, 0, 100));

        $mixed = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($asserted), 1 => $this->vote($asserted),
            2 => $this->vote($asserted), 3 => $this->vote($unasserted),
        ]);

        $this->assertTrue($mixed->requiresReview());
        $this->assertSame('sermon_absence', $mixed->disputes[0]['type']);
        $this->assertFalse($mixed->structure->assertsSermonAbsence());

        $all = app(ServiceStructureEnsembleComposer::class)->compose(array_fill(0, 4, $this->vote($asserted)));
        $this->assertSame([], $all->disputes);
        $this->assertTrue($all->structure->assertsSermonAbsence());
    }

    #[Test]
    public function agreement_keeps_every_supporting_voters_review_flags(): void
    {
        $plain = $this->structure(new ServiceStructureSection(ServiceSectionType::Song, null, 100.0, 300.0, 0.9, null, 'Amazing Grace', null));
        $flagged = $this->structure(new ServiceStructureSection(
            ServiceSectionType::Song, null, 101.0, 301.0, 0.9, null, 'Amazing Grace', null,
            reviewFlags: [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
        ));

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($plain),
            1 => $this->vote($plain),
            2 => $this->vote($plain),
            3 => $this->vote($flagged),
        ]);

        $this->assertSame([], array_values(array_filter($result->disputes, static fn (array $dispute): bool => ($dispute['type'] ?? null) === 'song')));
        $this->assertSame(100.0, $result->structure->sections[0]->startTime);
        $this->assertContains(ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH, $result->structure->sections[0]->reviewFlags);
    }

    #[Test]
    public function filler_far_from_the_sermon_follows_the_representative_without_a_question(): void
    {
        $draw = fn (float $welcomeEnd, bool $withNotices): ServiceStructure => $this->structure(...array_values(array_filter([
            $this->section(ServiceSectionType::Welcome, 0, (int) $welcomeEnd),
            $withNotices ? $this->section(ServiceSectionType::Notices, (int) $welcomeEnd, 400) : null,
            $this->section(ServiceSectionType::Sermon, 2000, 4000),
        ])));

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(100, true)),
            1 => $this->vote($draw(160, true)),
            2 => $this->vote($draw(130, false)),
            3 => $this->vote($draw(100, true)),
        ]);

        $this->assertSame([], $result->disputes);
        $this->assertSame(['welcome', 'notices', 'sermon'], array_map(static fn ($section): string => $section->type->value, $result->structure->sections));
    }

    #[Test]
    public function filler_that_can_change_the_sermon_cut_is_still_compared(): void
    {
        $draw = fn (int $prayerStart): ServiceStructure => $this->structure(
            $this->section(ServiceSectionType::Prayer, $prayerStart, 1990),
            $this->section(ServiceSectionType::Sermon, 2000, 4000),
            $this->section(ServiceSectionType::Notices, 4000, 4100),
            $this->section(ServiceSectionType::Song, 4100, 4300),
        );

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(1900)),
            1 => $this->vote($draw(1900)),
            2 => $this->vote($draw(1850)),
            3 => $this->vote($draw(1850)),
        ]);

        $this->assertSame(['prayer'], array_column($result->disputes, 'type'));
        $starts = array_map(static fn (array $alternative): float => $alternative['section']['start_time'], $result->disputes[0]['alternatives']);
        sort($starts);
        $this->assertSame([1850.0, 1900.0], $starts);
        $this->assertSame([], $result->disputes[0]['absent_slots']);
    }

    #[Test]
    public function a_mid_service_song_matches_on_overlap_but_the_song_ending_the_sermon_is_held_to_its_start(): void
    {
        $draw = fn (int $firstSongStart, int $closingSongStart): ServiceStructure => $this->structure(
            $this->song($firstSongStart, 600, 'Amazing Grace'),
            $this->section(ServiceSectionType::Sermon, 1000, $closingSongStart),
            $this->song($closingSongStart, 3500, 'In Christ Alone'),
        );

        $midServiceOnly = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(300, 3200)),
            1 => $this->vote($draw(340, 3200)),
            2 => $this->vote($draw(300, 3200)),
            3 => $this->vote($draw(345, 3200)),
        ]);
        $closing = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(300, 3200)),
            1 => $this->vote($draw(300, 3200)),
            2 => $this->vote($draw(300, 3240)),
            3 => $this->vote($draw(300, 3240)),
        ]);

        $this->assertSame([], $midServiceOnly->disputes);
        $this->assertSame(300.0, $midServiceOnly->structure->sectionsOfType(ServiceSectionType::Song)[0]->startTime);
        $this->assertContains('song', array_column($closing->disputes, 'type'));
    }

    #[Test]
    public function a_mid_service_song_identity_or_presence_disagreement_is_still_a_question(): void
    {
        $draw = fn (?string $title): ServiceStructure => $this->structure(...array_values(array_filter([
            $title === null ? null : $this->song(300, 600, $title),
            $this->section(ServiceSectionType::Sermon, 1000, 3000),
        ])));

        $identity = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw('Amazing Grace')),
            1 => $this->vote($draw('Amazing Grace')),
            2 => $this->vote($draw('Be Thou My Vision')),
            3 => $this->vote($draw('Be Thou My Vision')),
        ]);
        $presence = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw('Amazing Grace')),
            1 => $this->vote($draw('Amazing Grace')),
            2 => $this->vote($draw(null)),
            3 => $this->vote($draw(null)),
        ]);

        $this->assertContains('song', array_column($identity->disputes, 'type'));
        $this->assertContains('song', array_column($presence->disputes, 'type'));
    }

    /**
     * The sermon's reference rules Isaiah 40 out, as extraction would, however near it is. A
     * sermon with no reference sends every earlier reading to review, so each is held.
     */
    #[Test]
    public function a_reading_the_sermon_cannot_pair_matches_on_overlap_but_the_preached_reading_is_held_to_both_edges(): void
    {
        $draw = fn (int $earlyReadingStart, int $preachedReadingEnd): ServiceStructure => $this->structure(
            $this->section(ServiceSectionType::BibleReading, $earlyReadingStart, 500, 'Isaiah 40'),
            $this->section(ServiceSectionType::BibleReading, 1800, $preachedReadingEnd, 'John 3'),
            new ServiceStructureSection(ServiceSectionType::Sermon, null, 2000.0, 4000.0, 0.9, null, null, null, 'John 3:16'),
        );

        $earlyOnly = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(300, 1990)),
            1 => $this->vote($draw(340, 1990)),
            2 => $this->vote($draw(300, 1990)),
            3 => $this->vote($draw(345, 1990)),
        ]);
        $preached = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(300, 1990)),
            1 => $this->vote($draw(300, 1990)),
            2 => $this->vote($draw(300, 1960)),
            3 => $this->vote($draw(300, 1960)),
        ]);

        $this->assertSame([], $earlyOnly->disputes);
        $this->assertSame(300.0, $earlyOnly->structure->sectionsOfType(ServiceSectionType::BibleReading)[0]->startTime);
        $this->assertContains('bible_reading', array_column($preached->disputes, 'type'));
    }

    /**
     * A sermon misses its preached reading exactly when extraction's membership rule could cut
     * no reading into it: distance does not matter, a reading sharing verses or with no
     * reference could still be the preached one, and only an unrelated reading leaves it bare.
     */
    #[Test]
    public function a_missing_preached_reading_follows_extractions_membership_rule(): void
    {
        $flags = function (?string $readingReference, int $readingStart, ?string $sermonReference): array {
            $draw = $this->structure(
                $this->section(ServiceSectionType::BibleReading, $readingStart, $readingStart + 200, $readingReference),
                new ServiceStructureSection(ServiceSectionType::Sermon, null, 2000.0, 4000.0, 0.9, null, null, null, $sermonReference),
            );

            $composed = app(ServiceStructureEnsembleComposer::class)->compose(array_fill(0, 4, $this->vote($draw)));

            return $composed->structure->sectionsOfType(ServiceSectionType::Sermon)[0]->reviewFlags;
        };

        $this->assertNotContains(ServiceStructureValidator::FLAG_MISSING_PREACHED_READING, $flags('John 3', 300, 'John 3:16'), 'a matching reading pairs however early');
        $this->assertNotContains(ServiceStructureValidator::FLAG_MISSING_PREACHED_READING, $flags('Psalm 23', 300, null), 'an unnamed sermon pairs with any earlier reading');
        $this->assertNotContains(ServiceStructureValidator::FLAG_MISSING_PREACHED_READING, $flags(null, 1700, 'John 3:16'), 'an unnamed reading could be the preached one');
        $this->assertNotContains(ServiceStructureValidator::FLAG_MISSING_PREACHED_READING, $flags('John 3:1-21', 1950, 'John 3:16'), 'a reading that overlaps the sermon start is still before it');
        $this->assertContains(ServiceStructureValidator::FLAG_MISSING_PREACHED_READING, $flags('Isaiah 40', 1700, 'John 3:16'), 'an unrelated reading never pairs');
    }

    /**
     * Holds are a union over the supporting draws, so the sermon asks about exposed speech when
     * any supporter found some. The question is only answerable with the interval, which lives
     * in that supporter's note, not the representative's.
     */
    #[Test]
    public function an_exposed_speech_question_keeps_every_supporters_interval(): void
    {
        $question = ServiceStructureValidator::FLAG_SERMON_ADJACENT_SPEECH_UNOWNED;
        $sermon = $this->section(ServiceSectionType::Sermon, 2000, 4000);
        $asked = fn (float $from, float $to): ServiceStructureSection => $sermon->withReviewFlags([$question], [SongSpeechEdges::exposedSpeechNote($from, $to)]);
        $draw = fn (ServiceStructureSection $sermon): ValidationResult => $this->vote($this->structure(
            $this->section(ServiceSectionType::BibleReading, 1700, 1900, 'John 3'),
            $sermon,
        ));

        $composed = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $draw($sermon->withReviewFlags([], ['The representative draw explains nothing.'])),
            1 => $draw($asked(4000.0, 4031.5)),
            2 => $draw($asked(4000.0, 4029.5)),
            3 => $draw($sermon),
        ])->structure->sectionsOfType(ServiceSectionType::Sermon)[0];

        $this->assertContains($question, $composed->reviewFlags);
        $this->assertContains(SongSpeechEdges::exposedSpeechNote(4000.0, 4031.5), $composed->notes);
        $this->assertContains(SongSpeechEdges::exposedSpeechNote(4000.0, 4029.5), $composed->notes);
    }

    /**
     * F11's question travels like F07's: the union of holds raises it, and the interval that
     * makes it answerable lives in the note of whichever supporter found it.
     */
    #[Test]
    public function an_untranscribed_speech_question_keeps_every_supporters_interval(): void
    {
        $question = ServiceStructureValidator::FLAG_UNTRANSCRIBED_SPEECH_BEFORE_SECTION;
        $prayer = $this->section(ServiceSectionType::Prayer, 2706, 2800);
        $asked = fn (float $from): ServiceStructureSection => $prayer->withReviewFlags([$question], [UntranscribedSpeechBeforeSection::note($from, 2706.0)]);
        $draw = fn (ServiceStructureSection $prayer): ValidationResult => $this->vote($this->structure(
            $this->section(ServiceSectionType::Song, 2400, 2690),
            $prayer,
        ));

        $composed = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $draw($prayer->withReviewFlags([], ['The representative draw explains nothing.'])),
            1 => $draw($asked(2690.0)),
            2 => $draw($asked(2695.0)),
            3 => $draw($prayer),
        ])->structure->sectionsOfType(ServiceSectionType::Prayer)[0];

        $this->assertContains($question, $composed->reviewFlags);
        $this->assertContains(UntranscribedSpeechBeforeSection::note(2690.0, 2706.0), $composed->notes);
        $this->assertContains(UntranscribedSpeechBeforeSection::note(2695.0, 2706.0), $composed->notes);
    }

    #[Test]
    public function an_unpaired_reading_reference_or_presence_disagreement_is_still_a_question(): void
    {
        $draw = fn (?string $reference): ServiceStructure => $this->structure(...array_values(array_filter([
            $reference === null ? null : $this->section(ServiceSectionType::BibleReading, 300, 500, $reference),
            $this->section(ServiceSectionType::Sermon, 2000, 4000),
        ])));

        $reference = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw('Isaiah 40')),
            1 => $this->vote($draw('Isaiah 40')),
            2 => $this->vote($draw('Isaiah 53')),
            3 => $this->vote($draw('Isaiah 53')),
        ]);
        $presence = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw('Isaiah 40')),
            1 => $this->vote($draw('Isaiah 40')),
            2 => $this->vote($draw(null)),
            3 => $this->vote($draw(null)),
        ]);

        $this->assertContains('bible_reading', array_column($reference->disputes, 'type'));
        $this->assertContains('bible_reading', array_column($presence->disputes, 'type'));
    }

    /**
     * A 2–2 presence split whose tie-break leaves the reading out touches neither sermon edge,
     * yet answering it can add the reading to the cut. The sermon is held, and the question
     * counts against the sermon's output; a song elsewhere is not swept in.
     */
    #[Test]
    public function an_omitted_disputed_reading_holds_the_sermon_it_could_join(): void
    {
        $draw = fn (bool $withReading): ServiceStructure => $this->structure(...array_values(array_filter([
            $withReading ? $this->section(ServiceSectionType::BibleReading, 300, 500, 'Genesis 8') : null,
            $this->song(1000, 1200, 'Amazing Grace'),
            $this->section(ServiceSectionType::Sermon, 2000, 4000),
        ])));

        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(false)),
            1 => $this->vote($draw(false)),
            2 => $this->vote($draw(true)),
            3 => $this->vote($draw(true)),
        ]);

        $this->assertSame([], $result->structure->sectionsOfType(ServiceSectionType::BibleReading));
        $reading = collect($result->disputes)->firstWhere('type', 'bible_reading');
        $this->assertIsArray($reading);
        $this->assertContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $result->structure->sectionsOfType(ServiceSectionType::Sermon)[0]->reviewFlags);
        $this->assertNotContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $result->structure->sectionsOfType(ServiceSectionType::Song)[0]->reviewFlags);
        $this->assertTrue(OutputEdgeReview::concernsOutput($reading, [['start_time' => 2000.0, 'end_time' => 4000.0]]));
        $this->assertFalse(OutputEdgeReview::concernsOutput($reading, [['start_time' => 100.0, 'end_time' => 250.0]]));
    }

    #[Test]
    public function only_a_disputed_prayer_between_the_sermon_and_the_next_song_concerns_its_output(): void
    {
        $prayer = fn (float $start, float $end): array => ['type' => 'prayer', 'start_time' => $start, 'end_time' => $end, 'alternatives' => []];
        $sermon = [['start_time' => 2000.0, 'end_time' => 4000.0]];

        $this->assertTrue(OutputEdgeReview::concernsOutput($prayer(4100.0, 4200.0), $sermon));
        $this->assertFalse(OutputEdgeReview::concernsOutput($prayer(1000.0, 1100.0), $sermon));
        $this->assertFalse(OutputEdgeReview::concernsOutput($prayer(4400.0, 4500.0), $sermon, [4100.0]), 'A prayer after the closing song is not the concluding prayer.');
    }

    /**
     * The sermon's own reference outranks every other pairing evidence, so a reading within
     * reach that does not match it can never be cut. Without a sermon reference, any reading
     * within reach could be, so each is held.
     */
    #[Test]
    public function only_readings_the_sermon_could_pair_with_are_held_to_their_edges(): void
    {
        $draw = fn (int $psalmEnd, ?string $sermonReference): ServiceStructure => $this->structure(
            $this->section(ServiceSectionType::BibleReading, 1400, $psalmEnd, 'Psalm 23'),
            $this->section(ServiceSectionType::BibleReading, 1800, 1990, 'John 3'),
            new ServiceStructureSection(ServiceSectionType::Sermon, null, 2000.0, 4000.0, 0.9, null, null, null, $sermonReference),
        );
        $votes = fn (?string $sermonReference): array => [
            0 => $this->vote($draw(1600, $sermonReference)),
            1 => $this->vote($draw(1600, $sermonReference)),
            2 => $this->vote($draw(1640, $sermonReference)),
            3 => $this->vote($draw(1640, $sermonReference)),
        ];

        $named = app(ServiceStructureEnsembleComposer::class)->compose($votes('John 3:16'));
        $unnamed = app(ServiceStructureEnsembleComposer::class)->compose($votes(null));

        $this->assertSame([], $named->disputes);
        $this->assertContains('bible_reading', array_column($unnamed->disputes, 'type'));
    }

    /** The shape of run 1356 in the baseline draws: the written filler and song come from different voters. */
    #[Test]
    public function filler_gives_way_to_the_claim_it_overlaps(): void
    {
        $sermon = $this->section(ServiceSectionType::Sermon, 1000, 3000);
        $sections = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($this->structure($this->section(ServiceSectionType::Other, 497, 526), $this->song(530, 700, 'Amazing Grace'), $sermon)),
            1 => $this->vote($this->structure($this->song(522, 695, 'Amazing Grace'), $sermon)),
            2 => $this->vote($this->structure($this->song(497, 700, 'Amazing Grace'), $sermon)),
            3 => $this->vote($this->structure($this->section(ServiceSectionType::Other, 490, 521), $this->song(522, 700, 'Amazing Grace'), $sermon)),
        ])->structure->sections;

        $this->assertSame(['other', 'song', 'sermon'], array_map(static fn ($section): string => $section->type->value, $sections));
        $this->assertSame(522.0, $sections[1]->startTime);
        $this->assertSame(522.0, $sections[0]->endTime);
    }

    /** The shape of run 1117 in the p3 draws: two fillers written over one span from different groups. */
    #[Test]
    public function the_less_supported_of_two_overlapping_fillers_gives_way(): void
    {
        $sermon = $this->section(ServiceSectionType::Sermon, 1000, 3900);
        $sections = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($this->structure($sermon, $this->song(4015, 4176, 'Amazing Grace'), $this->section(ServiceSectionType::Other, 4176, 4219))),
            1 => $this->vote($this->structure($sermon, $this->song(4005, 4175, 'Amazing Grace'), $this->section(ServiceSectionType::Prayer, 4176, 4219))),
            2 => $this->vote($this->structure($sermon, $this->song(3980, 4174, 'Amazing Grace'), $this->section(ServiceSectionType::Prayer, 4176, 4219))),
            3 => $this->vote($this->structure($sermon, $this->song(4005, 4175, 'Amazing Grace'), $this->section(ServiceSectionType::Other, 4175, 4200), $this->section(ServiceSectionType::Prayer, 4200, 4219))),
        ])->structure->sections;

        $this->assertSame(['sermon', 'song', 'prayer'], array_map(static fn ($section): string => $section->type->value, $sections));
        $this->assertSame([4176.0, 4219.0], [$sections[2]->startTime, $sections[2]->endTime]);
    }

    /** The shape of run 1028 in the p5order draws: a mid-service song's unheld end runs into a talk. */
    #[Test]
    public function a_mid_service_song_edge_gives_way_to_a_talk(): void
    {
        $sermon = $this->section(ServiceSectionType::Sermon, 1965, 4097);
        $draw = fn (int $songStart, int $songEnd, int $talkStart): ServiceStructure => $this->structure(
            $this->song($songStart, $songEnd, 'Amazing Grace'),
            $this->section(ServiceSectionType::ShortTalk, $talkStart, 1242),
            $sermon,
        );

        $sections = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw(990, 1120, 1120)),
            1 => $this->vote($draw(979, 1125, 1120)),
            2 => $this->vote($draw(979, 1125, 1129)),
            3 => $this->vote($draw(979, 1120, 1120)),
        ])->structure->sections;

        $this->assertSame(['song', 'short_talk', 'sermon'], array_map(static fn ($section): string => $section->type->value, $sections));
        $this->assertSame(1120.0, $sections[1]->startTime);
        $this->assertSame(1120.0, $sections[0]->endTime);
    }

    #[Test]
    public function a_song_and_the_sermon_after_it_share_one_supported_boundary(): void
    {
        $result = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($this->structure($this->song(500, 720, 'Amazing Grace'), $this->section(ServiceSectionType::Sermon, 720, 3000))),
            1 => $this->vote($this->structure($this->song(500, 720, 'Amazing Grace'), $this->section(ServiceSectionType::Sermon, 720, 3000))),
            2 => $this->vote($this->structure($this->song(500, 750, 'Amazing Grace'), $this->section(ServiceSectionType::Sermon, 740, 3000))),
            3 => $this->vote($this->structure($this->song(500, 750, 'Amazing Grace'), $this->section(ServiceSectionType::Sermon, 740, 3000))),
        ]);
        $song = $result->structure->sectionsOfType(ServiceSectionType::Song)[0];
        $sermon = $result->structure->sectionsOfType(ServiceSectionType::Sermon)[0];

        $this->assertSame(500.0, $song->startTime);
        $this->assertSame(720.0, $sermon->startTime);
        $this->assertSame($sermon->startTime, $song->endTime);
    }

    private function song(int $start, int $end, string $title): ServiceStructureSection
    {
        return new ServiceStructureSection(ServiceSectionType::Song, null, (float) $start, (float) $end, 0.9, null, $title, null);
    }

    /** Run 1050: slots 1 and 3 split quoted Scripture; slots 0 and 2 keep one sermon, 79–837 s. */
    #[Test]
    public function a_two_of_four_stitch_flag_does_not_hold_the_composed_sermon_but_other_flags_stay(): void
    {
        $sermon = $this->section(ServiceSectionType::Sermon, 79, 837);
        $stitch = ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED;
        $sections = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($this->structure($sermon)),
            1 => $this->vote($this->structure($sermon->withReviewFlags([$stitch, 'content_defect_hold']))),
            2 => $this->vote($this->structure($sermon)),
            3 => $this->vote($this->structure($sermon->withReviewFlags([$stitch]))),
        ])->structure->sectionsOfType(ServiceSectionType::Sermon);

        $this->assertNotContains($stitch, $sections[0]->reviewFlags);
        $this->assertContains('content_defect_hold', $sections[0]->reviewFlags);
    }

    #[Test]
    public function a_three_of_four_stitch_flag_keeps_the_composed_sermon_held(): void
    {
        $sermon = $this->section(ServiceSectionType::Sermon, 238, 1710);
        $stitch = ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED;
        $votes = array_map(fn (int $slot): ValidationResult => $this->vote($this->structure($slot < 3 ? $sermon->withReviewFlags([$stitch]) : $sermon)), range(0, 3));

        $sections = app(ServiceStructureEnsembleComposer::class)->compose($votes)->structure->sectionsOfType(ServiceSectionType::Sermon);

        $this->assertContains($stitch, $sections[0]->reviewFlags);
    }

    private function vote(ServiceStructure $structure): ValidationResult
    {
        return new ValidationResult($structure);
    }

    private function structure(ServiceStructureSection ...$sections): ServiceStructure
    {
        return ServiceStructure::fromSections($sections);
    }

    private function section(ServiceSectionType $type, int $start, int $end, ?string $reference = null): ServiceStructureSection
    {
        return new ServiceStructureSection($type, null, (float) $start, (float) $end, 0.9, null, null, $reference);
    }
}
