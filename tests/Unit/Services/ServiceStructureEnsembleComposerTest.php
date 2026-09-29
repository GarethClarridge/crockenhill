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
                $this->section(ServiceSectionType::Other, 105, 120),
                $this->section(ServiceSectionType::Other, 120, 140),
            )),
            1 => $this->vote($this->structure($this->section(ServiceSectionType::Other, 125, 135))),
            2 => $this->vote($this->structure($this->section(ServiceSectionType::Other, 125, 135))),
            3 => $this->vote($this->structure($this->section(ServiceSectionType::Other, 125, 135))),
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
