<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService\ReviewInventory;

use App\Models\ServiceSection;
use App\Services\ChurchService\ReviewInventory\PendingApplication;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PendingApplicationTest extends TestCase
{
    #[Test]
    public function a_sermon_whose_end_is_unassessed_is_compared_on_its_starts_only(): void
    {
        $pending = PendingApplication::fromRows([
            $this->sermonRow(1, recorded: [[100.0, 200.0], [300.0, 400.0]], withoutEnd: [[100.0, 201.5], [300.0, 380.0]]),
            $this->sermonRow(2, recorded: [[100.0, 200.0]], withoutEnd: [[95.5, 200.0]]),
            $this->sermonRow(3, recorded: [[100.0, 200.0]], withoutEnd: [[0.0, 90.0], [100.0, 200.0]]),
        ]);

        // Run 1 moves only ends, which the end-less replan cannot compare (K16's inner kept lines are skipped).
        $this->assertNull($pending->fixFor($this->section(11, 1)));
        $this->assertSame(PendingApplication::MISSING_EDGE_WORDS, $pending->missingInputFor($this->section(11, 1)));
        $this->assertSame(PendingApplication::SERMON_RECUT, $pending->fixFor($this->section(21, 2)));
        // A part added before the first (a K18 introduction) changes the cut.
        $this->assertSame(PendingApplication::SERMON_RECUT, $pending->fixFor($this->section(31, 3)));
    }

    #[Test]
    public function a_sermon_refused_now_changes_only_if_it_was_cut_before(): void
    {
        $refused = static fn (int $run, array $recorded): array => ['run' => $run, 'sermon' => [
            'recorded_segments' => $recorded, 'replayed_segments' => [], 'recorded_selected' => [$run * 10 + 1], 'replayed_selected' => [$run * 10 + 1],
        ]];

        $pending = PendingApplication::fromRows([$refused(4, [['start_time' => 1.0, 'end_time' => 2.0]]), $refused(5, [])]);

        $this->assertSame(PendingApplication::SERMON_RECUT, $pending->fixFor($this->section(41, 4)));
        $this->assertNull($pending->fixFor($this->section(51, 5)));
    }

    #[Test]
    public function structure_changes_reach_overlapping_sections_and_routes_name_each_operation(): void
    {
        $pending = PendingApplication::fromRows([
            ['run' => 7, 'structure' => ['diffs' => [['stored' => ['start_time' => 135.0, 'end_time' => 196.0], 'replayed' => ['start_time' => 135.0, 'end_time' => 330.0]]]],
                'sections' => [['id' => 71, 'same_cut' => false], ['id' => 72, 'unassessable' => ['missing' => 'edge_word_timings']], ['id' => 73, 'same_cut' => true]]],
            ['run' => 942, 'structure' => ['diffs' => [['stored' => ['start_time' => 0.0, 'end_time' => 10.0], 'replayed' => null]]]],
            ['run' => 8, 'skipped' => 'excluded'],
            ['run' => 9, 'error' => ['class' => 'Boom']],
        ]);

        $this->assertSame(PendingApplication::RECOMPOSE, $pending->fixFor($this->section(99, 7, 300.0, 340.0)));
        $this->assertNull($pending->fixFor($this->section(98, 7, 330.0, 400.0)));
        $this->assertSame(PendingApplication::SECTION_RECUT, $pending->fixFor($this->section(71, 7, 500.0, 600.0)));
        $this->assertFalse($pending->replayed(8));
        $this->assertFalse($pending->replayed(9));
        $this->assertSame([
            7 => ['recompose (structure changes)', 're-cut sections 71', 'edge step first: '.PendingApplication::MISSING_EDGE_WORDS],
            942 => ['X7: hand-edited structure, never recomposed; re-extract as it stands'],
        ], $pending->routes([942]));
    }

    /**
     * @param  list<array{0: float, 1: float}>  $recorded
     * @param  list<array{0: float, 1: float}>  $withoutEnd
     * @return array<string, mixed>
     */
    private function sermonRow(int $run, array $recorded, array $withoutEnd): array
    {
        $spans = static fn (array $pairs): array => array_map(static fn (array $pair): array => ['start_time' => $pair[0], 'end_time' => $pair[1]], $pairs);

        return ['run' => $run, 'sermon' => [
            'recorded_segments' => $spans($recorded), 'replayed_segments' => [], 'without_end_segments' => $spans($withoutEnd),
            'recorded_selected' => [$run * 10 + 1], 'replayed_selected' => [$run * 10 + 1],
            'unassessable' => ['missing' => 'edge_word_timings'],
        ]];
    }

    private function section(int $id, int $run, float $start = 0.0, float $end = 1.0): ServiceSection
    {
        $section = new ServiceSection;
        $section->forceFill(['media_processing_log_id' => $run, 'start_time' => $start, 'end_time' => $end]);
        $section->id = $id;

        return $section;
    }
}
