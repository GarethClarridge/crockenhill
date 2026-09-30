<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleScorer;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureEnsembleScorerTest extends TestCase
{
    #[Test]
    public function a_false_talk_is_an_accuracy_error_even_when_a_dispute_contains_it(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section(ServiceSectionType::ShortTalk, 100, 200, [ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES]),
            $this->section(ServiceSectionType::Sermon, 300, 600),
        ]);
        $truth = [['type' => 'sermon', 'start' => 300, 'end' => 600, 'basis' => 'operator']];

        $report = app(ServiceStructureEnsembleScorer::class)->score([
            'structure' => $structure->toArray(),
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [['type' => 'short_talk', 'start_time' => 100, 'end_time' => 200]],
        ], $truth);

        $this->assertTrue($report['talk_count']['error']);
        $this->assertTrue($report['talk_count']['flagged_error']);
        $this->assertFalse($report['talk_count']['unflagged_error']);
        $this->assertFalse($report['clean_verified']);

        $unflagged = app(ServiceStructureEnsembleScorer::class)->score([
            'structure' => ServiceStructure::fromSections([
                $this->section(ServiceSectionType::ShortTalk, 100, 200),
                $this->section(ServiceSectionType::Sermon, 300, 600),
            ])->toArray(),
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [['type' => 'prayer', 'start_time' => 100, 'end_time' => 200]],
        ], $truth);

        $this->assertTrue($unflagged['talk_count']['unflagged_error']);
    }

    #[Test]
    public function a_cut_past_the_truth_end_is_wrong_and_unflagged_unless_a_question_touches_it(): void
    {
        $structure = ServiceStructure::fromSections([$this->section(ServiceSectionType::Sermon, 300, 600)]);
        $truth = [['type' => 'sermon', 'start' => 300, 'end' => 600, 'basis' => 'operator', 'tolerance' => 20]];
        $cut = static fn (float $end): array => [
            'gated' => ['mode' => 'single_span', 'from_sections' => true, 'segments' => [['start_time' => 250.0, 'end_time' => $end]]],
            'as_written' => ['mode' => 'single_span', 'strategy' => 'adjacent_bible_plus_sermon', 'from_sections' => true, 'segments' => [['start_time' => 250.0, 'end_time' => $end]]],
        ];
        $score = fn (float $end, array $disputes): array => app(ServiceStructureEnsembleScorer::class)->score([
            'structure' => $structure->toArray(),
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => $disputes,
            'cut' => $cut($end),
        ], $truth)['cut'];

        $right = $score(610.0, []);
        $wrongUnreviewed = $score(700.0, []);
        $wrongQuestioned = $score(700.0, [['type' => 'song', 'start_time' => 640, 'end_time' => 900]]);
        $wrongElsewhere = $score(700.0, [['type' => 'song', 'start_time' => 1000, 'end_time' => 1200]]);

        $this->assertFalse($right['wrong']);
        $this->assertTrue($right['reaches_extraction_unreviewed']);
        $this->assertTrue($wrongUnreviewed['wrong_reaches_extraction_unreviewed']);
        $this->assertSame(100.0, $wrongUnreviewed['end_error_seconds']);
        $this->assertFalse($wrongQuestioned['wrong_unflagged']);
        $this->assertFalse($wrongQuestioned['reaches_extraction_unreviewed']);
        $this->assertTrue($wrongElsewhere['wrong_unflagged']);
        $this->assertFalse($wrongElsewhere['wrong_reaches_extraction_unreviewed']);
    }

    #[Test]
    public function a_cut_not_planned_from_sections_is_not_scored(): void
    {
        $report = app(ServiceStructureEnsembleScorer::class)->score([
            'structure' => ServiceStructure::fromSections([$this->section(ServiceSectionType::Sermon, 300, 600)])->toArray(),
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [],
            'cut' => [
                'gated' => ['mode' => 'baseline', 'from_sections' => false, 'segments' => [['start_time' => 0.0, 'end_time' => 900.0]]],
                'as_written' => ['mode' => 'baseline', 'from_sections' => false, 'segments' => [['start_time' => 0.0, 'end_time' => 900.0]]],
            ],
        ], [['type' => 'sermon', 'start' => 300, 'end' => 600, 'basis' => 'operator']]);

        $this->assertFalse($report['cut']['scored']);
        $this->assertFalse($report['cut']['reaches_extraction_unreviewed']);
    }

    #[Test]
    public function an_accepted_complete_alternative_matches_but_provisional_truth_is_not_verified(): void
    {
        $structure = ServiceStructure::fromSections([$this->section(ServiceSectionType::Sermon, 2706, 4600)]);
        $report = app(ServiceStructureEnsembleScorer::class)->score([
            'structure' => $structure->toArray(),
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [],
        ], [[
            'type' => 'sermon',
            'start' => 2744,
            'end' => 4600,
            'alternatives' => [['start' => 2706, 'end' => 4600]],
            'basis' => 'consensus',
        ]]);

        $this->assertSame('matched', $report['matches'][0]['status']);
        $this->assertFalse($report['clean_verified']);
        $this->assertSame(1, $report['truth_basis_counts']['consensus']);
    }

    #[Test]
    public function one_output_cannot_satisfy_two_expected_talks(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section(ServiceSectionType::ShortTalk, 100, 250),
            $this->section(ServiceSectionType::Sermon, 300, 600),
        ]);
        $report = app(ServiceStructureEnsembleScorer::class)->score([
            'structure' => $structure->toArray(),
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [],
        ], [
            ['type' => 'short_talk', 'start' => 100, 'end' => 250, 'basis' => 'operator'],
            ['type' => 'short_talk', 'start' => 105, 'end' => 245, 'basis' => 'operator'],
            ['type' => 'sermon', 'start' => 300, 'end' => 600, 'basis' => 'operator'],
        ]);

        $this->assertSame('missing', $report['matches'][1]['status']);
        $this->assertTrue($report['talk_count']['unflagged_error']);
        $this->assertFalse($report['clean_verified']);
    }

    #[Test]
    public function an_unrelated_talk_dispute_cannot_flag_a_unanimous_false_talk(): void
    {
        $report = app(ServiceStructureEnsembleScorer::class)->score([
            'structure' => ServiceStructure::fromSections([
                $this->section(ServiceSectionType::ShortTalk, 100, 200, [ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES]),
                $this->section(ServiceSectionType::ShortTalk, 900, 1000),
                $this->section(ServiceSectionType::Sermon, 1300, 2600),
            ])->toArray(),
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [['type' => 'short_talk', 'start_time' => 100, 'end_time' => 200, 'written' => true]],
        ], [
            ['type' => 'short_talk', 'start' => 100, 'end' => 200, 'basis' => 'operator'],
            ['type' => 'sermon', 'start' => 1300, 'end' => 2600, 'basis' => 'operator'],
        ]);

        $this->assertTrue($report['talk_count']['error']);
        $this->assertTrue($report['talk_count']['unflagged_error']);
        $this->assertFalse($report['talk_count']['flagged_error']);
        $this->assertSame([['kind' => 'false_talk', 'start_time' => 900.0, 'end_time' => 1000.0, 'flagged' => false]], $report['talk_count']['errors']);
    }

    #[Test]
    public function a_missed_talk_is_flagged_only_by_a_dispute_over_its_span(): void
    {
        $truth = [
            ['type' => 'short_talk', 'start' => 400, 'end' => 500, 'basis' => 'operator'],
            ['type' => 'sermon', 'start' => 1300, 'end' => 2600, 'basis' => 'operator'],
        ];
        $structure = ServiceStructure::fromSections([
            $this->section(ServiceSectionType::Prayer, 400, 500),
            $this->section(ServiceSectionType::Sermon, 1300, 2600),
        ])->toArray();
        $scorer = app(ServiceStructureEnsembleScorer::class);

        $covered = $scorer->score([
            'structure' => $structure,
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [['type' => 'short_talk', 'start_time' => 405, 'end_time' => 495, 'written' => false]],
        ], $truth);
        $elsewhere = $scorer->score([
            'structure' => $structure,
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [['type' => 'short_talk', 'start_time' => 3000, 'end_time' => 3100, 'written' => false]],
        ], $truth);

        $this->assertTrue($covered['talk_count']['flagged_error']);
        $this->assertFalse($covered['talk_count']['unflagged_error']);
        $this->assertTrue($elsewhere['talk_count']['unflagged_error']);
    }

    #[Test]
    public function a_wrong_reference_cannot_be_clean_verified(): void
    {
        $sermon = new ServiceStructureSection(ServiceSectionType::Sermon, null, 300.0, 600.0, 0.9, null, null, null, 'Luke 1:1-10');
        $replay = [
            'structure' => ServiceStructure::fromSections([$sermon])->toArray(),
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [],
        ];
        $scorer = app(ServiceStructureEnsembleScorer::class);

        $wrong = $scorer->score($replay, [['type' => 'sermon', 'start' => 300, 'end' => 600, 'basis' => 'operator', 'sermon_reference' => 'John 1:1-10']]);
        $right = $scorer->score($replay, [['type' => 'sermon', 'start' => 300, 'end' => 600, 'basis' => 'operator', 'sermon_reference' => 'Luke 1:1–10']]);

        $this->assertSame('wrong_identity', $wrong['matches'][0]['status']);
        $this->assertSame(['sermon_reference'], $wrong['matches'][0]['identity_mismatches']);
        $this->assertFalse($wrong['clean_verified']);
        $this->assertSame('matched', $right['matches'][0]['status']);
        $this->assertTrue($right['clean_verified']);
    }

    #[Test]
    public function songs_and_readings_need_labelled_identity_before_they_verify(): void
    {
        $reading = new ServiceStructureSection(ServiceSectionType::BibleReading, null, 100.0, 200.0, 0.9, 12, null, 'John 3:1-8');
        $song = new ServiceStructureSection(ServiceSectionType::Song, null, 200.0, 400.0, 0.9, 13, 'Amazing Grace', null);
        $replay = [
            'structure' => ServiceStructure::fromSections([$reading, $song])->toArray(),
            'validation_passed' => true,
            'degraded' => false,
            'disputes' => [],
        ];
        $scorer = app(ServiceStructureEnsembleScorer::class);

        $unlabelled = $scorer->score($replay, [
            ['type' => 'bible_reading', 'start' => 100, 'end' => 200, 'basis' => 'operator'],
            ['type' => 'song', 'start' => 200, 'end' => 400, 'basis' => 'operator'],
        ]);
        $wrongBinding = $scorer->score($replay, [
            ['type' => 'bible_reading', 'start' => 100, 'end' => 200, 'basis' => 'operator', 'reading_reference' => 'John 3:1-8', 'oos_item_id' => 12],
            ['type' => 'song', 'start' => 200, 'end' => 400, 'basis' => 'operator', 'song_title' => 'Amazing grace', 'oos_item_id' => 14],
        ]);
        $labelled = $scorer->score($replay, [
            ['type' => 'bible_reading', 'start' => 100, 'end' => 200, 'basis' => 'operator', 'reading_reference' => 'John 3:1-8', 'oos_item_id' => 12],
            ['type' => 'song', 'start' => 200, 'end' => 400, 'basis' => 'operator', 'song_title' => 'Amazing grace', 'oos_item_id' => 13],
        ]);

        $this->assertSame('identity_unverified', $unlabelled['matches'][0]['status']);
        $this->assertFalse($unlabelled['clean_verified']);
        $this->assertSame(['oos_item_id'], $wrongBinding['matches'][1]['identity_mismatches']);
        $this->assertFalse($wrongBinding['clean_verified']);
        $this->assertTrue($labelled['clean_verified']);
    }

    private function section(ServiceSectionType $type, int $start, int $end, array $flags = []): ServiceStructureSection
    {
        return new ServiceStructureSection($type, null, (float) $start, (float) $end, 0.9, null, null, null, reviewFlags: $flags);
    }
}
