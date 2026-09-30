<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceSermonAbsence;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleComposer;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\ValidationResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureEnsembleComposerTest extends TestCase
{
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
        $this->assertNotEmpty($result->disputes);
        $this->assertTrue($result->requiresReview());
        $this->assertFalse($result->disputes[0]['written']);
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
            0 => $this->vote($before), 1 => $this->vote($after),
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
    public function disputed_song_boundary_holds_the_sermon_it_could_cut(): void
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
        $this->assertContains(
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
            3 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
        ]);
        $boundAndUnbound = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
            1 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
            2 => $this->vote($song('Praise My Soul The King Of Heaven', null)),
            3 => $this->vote($song('Praise My Soul The King Of Heaven', 4860)),
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

        $this->assertSame(['prayer', 'prayer'], array_column($result->disputes, 'type'));
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
            3 => $this->vote($draw('Amazing Grace')),
        ]);
        $presence = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw('Amazing Grace')),
            1 => $this->vote($draw('Amazing Grace')),
            2 => $this->vote($draw(null)),
            3 => $this->vote($draw('Amazing Grace')),
        ]);

        $this->assertContains('song', array_column($identity->disputes, 'type'));
        $this->assertContains('song', array_column($presence->disputes, 'type'));
    }

    #[Test]
    public function a_reading_the_sermon_cannot_pair_matches_on_overlap_but_the_preached_reading_is_held_to_both_edges(): void
    {
        $draw = fn (int $earlyReadingStart, int $preachedReadingEnd): ServiceStructure => $this->structure(
            $this->section(ServiceSectionType::BibleReading, $earlyReadingStart, 500, 'Isaiah 40'),
            $this->section(ServiceSectionType::BibleReading, 1800, $preachedReadingEnd, 'John 3'),
            $this->section(ServiceSectionType::Sermon, 2000, 4000),
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
            3 => $this->vote($draw('Isaiah 40')),
        ]);
        $presence = app(ServiceStructureEnsembleComposer::class)->compose([
            0 => $this->vote($draw('Isaiah 40')),
            1 => $this->vote($draw('Isaiah 40')),
            2 => $this->vote($draw(null)),
            3 => $this->vote($draw('Isaiah 40')),
        ]);

        $this->assertContains('bible_reading', array_column($reference->disputes, 'type'));
        $this->assertContains('bible_reading', array_column($presence->disputes, 'type'));
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
