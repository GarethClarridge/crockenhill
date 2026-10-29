<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MediaProcessingLog;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\RuledEndingsShards;
use Tests\Support\RuledEndingsTestCase;

class RuledEndingsHarnessTest extends TestCase
{
    #[Test]
    public function shards_cover_every_capture_once_without_inheriting_the_bespoke_tests(): void
    {
        $all = [];
        for ($index = 0; $index < RuledEndingsShards::COUNT; $index++) {
            $class = 'Tests\\Integration\\Services\\ChurchService\\RuledEndingsShard'.($index + 1).'Test';
            $fixtures = $class::fixtures();
            $this->assertNotEmpty($fixtures);
            $this->assertSame($fixtures, $class::fixtures());
            $this->assertFalse(method_exists($class, 'a_song_start_inside_an_announcement_moves_after_its_sentence'));
            foreach ($fixtures as [$path]) {
                $all[] = $path;
            }
        }
        $expected = glob(dirname(__DIR__).'/Fixtures/RuledEndings/*.json.gz');
        sort($expected);
        sort($all);

        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $all);
    }

    #[Test]
    public function shard_assignment_is_balanced_and_independent_of_input_order(): void
    {
        $weights = array_combine(range('a', 'p'), array_fill(0, 16, 2.0));
        $shards = RuledEndingsShards::partition($weights);

        $this->assertSame($shards, RuledEndingsShards::partition(array_reverse($weights, true)));
        $this->assertSame(array_fill(0, 8, 2), array_map(count(...), $shards));
        $this->assertContains('new-capture', array_merge(...RuledEndingsShards::partition([...$weights, 'new-capture' => 4.0])));
    }

    #[Test]
    public function a_capture_is_prepared_once_and_each_output_is_planned_once(): void
    {
        $harness = $this->harness();
        $capture = ['id' => 'run-test', 'rulings' => [
            $this->entry('song-start', 7, 'start'),
            $this->entry('song-end', 7, 'end'),
            $this->entry('sermon-start', null, 'start'),
            $this->entry('sermon-end', null, 'end'),
            $this->entry('next-song', 8, 'start'),
        ]];

        $harness->check($capture);

        $this->assertSame(1, $harness->preparations);
        $this->assertSame([7, null, 8], $harness->planned);
        $this->assertSame(array_column($capture['rulings'], 'id'), $harness->checked);
        $harness->check($capture);
        $this->assertSame(2, $harness->preparations);
        $this->assertSame([7, null, 8, 7, null, 8], $harness->planned, 'Plans must not leak across captures.');
    }

    #[Test]
    public function original_single_ruling_captures_still_use_the_same_assertions(): void
    {
        $harness = $this->harness();
        $harness->check(['id' => 'end-123-sermon', 'ruling' => ['answer' => 'right', 'ruled_cut' => 20.0], 'live' => ['cut' => 20.0]]);

        $this->assertSame([null], $harness->planned);
        $this->assertSame(['end-123-sermon'], $harness->checked);
    }

    #[Test]
    public function assertion_failures_do_not_hide_later_rulings(): void
    {
        $harness = $this->harness();
        $bad = $this->entry('bad-cut', 7, 'start');
        $bad['live']['cut'] = 99.0;

        try {
            $harness->check(['id' => 'capture', 'rulings' => [$bad, $this->entry('good-cut', 7, 'end')]]);
            $this->fail('The bad cut must fail.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('bad-cut: the fixture no longer reproduces its live cut', $exception->getMessage());
        }
        $this->assertSame(['bad-cut', 'good-cut'], $harness->checked);
    }

    #[Test]
    public function a_failed_plan_is_reported_for_each_affected_ruling_and_other_outputs_are_checked(): void
    {
        $harness = $this->harness();
        $harness->failedSection = 7;
        try {
            $harness->check(['id' => 'capture', 'rulings' => [
                $this->entry('failed-start', 7, 'start'), $this->entry('failed-end', 7, 'end'),
                $this->entry('good-output', 8, 'end'),
            ]]);
            $this->fail('The failed plan must fail its rulings.');
        } catch (AssertionFailedError $exception) {
            $this->assertStringContainsString('failed-start: RuntimeException: Missing evidence', $exception->getMessage());
            $this->assertStringContainsString('failed-end: RuntimeException: Missing evidence', $exception->getMessage());
        }
        $this->assertSame([7, 8], $harness->planned);
        $this->assertSame(['good-output'], $harness->checked);
    }

    #[Test]
    public function a_ruling_cannot_silently_override_the_shared_evidence(): void
    {
        $harness = $this->harness();
        $entry = [...$this->entry('changed-evidence', 7, 'start'), 'files' => []];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('changed-evidence: ruling overrides shared evidence');
        $harness->check(['id' => 'capture', 'rulings' => [$entry]]);
    }

    #[Test]
    public function an_empty_ruling_list_cannot_pass_without_checks(): void
    {
        $harness = $this->harness();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('empty: capture has no rulings');
        $harness->check(['id' => 'empty', 'rulings' => []]);
    }

    #[Test]
    public function a_ruling_on_a_later_part_checks_that_parts_start_not_the_videos_opening(): void
    {
        $harness = $this->harness();
        $harness->segments = [['start_time' => 10.0, 'end_time' => 20.0], ['start_time' => 31.0, 'end_time' => 40.0]];

        $harness->check($this->partCapture(31.0));

        $this->assertSame(['part-start'], $harness->checked);
    }

    #[Test]
    public function a_later_part_that_cannot_be_found_fails_clearly(): void
    {
        $harness = $this->harness();
        $harness->segments = [['start_time' => 10.0, 'end_time' => 20.0]];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('part-start: RuntimeException: no segment of the sermon video overlaps §5');
        $harness->check($this->partCapture(31.0));
    }

    #[Test]
    public function a_later_part_that_now_opens_the_video_is_not_judged_by_its_ruling(): void
    {
        $harness = $this->harness();
        $harness->segments = [['start_time' => 31.0, 'end_time' => 40.0]];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('part-start: RuntimeException: §5 opens the sermon video');
        $harness->check($this->partCapture(31.0));
    }

    #[Test]
    public function a_ruling_on_an_earlier_parts_end_checks_that_end_and_never_the_videos_close(): void
    {
        $harness = $this->harness();
        $harness->segments = [['start_time' => 10.0, 'end_time' => 34.0], ['start_time' => 50.0, 'end_time' => 60.0]];
        $capture = $this->partCapture(34.0);
        $capture['rulings'][0]['ruling']['edge'] = 'end';

        $harness->check($capture);
        $this->assertSame(['part-start'], $harness->checked);

        $harness->segments = [['start_time' => 10.0, 'end_time' => 34.0]];
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('part-start: RuntimeException: §5 closes the sermon video');
        $harness->check($capture);
    }

    #[Test]
    public function a_ruling_on_a_later_part_never_supersedes_the_videos_opening_ruling_or_the_reverse(): void
    {
        $harness = $this->harness();
        $harness->recorded = [
            ['id' => 'opening', 'run' => 1, 'output' => 'sermon', 'section_id' => null, 'section_type' => null, 'edge' => 'start',
                'heard' => 10.0, 'expect' => 'keep', 'ruled_time' => null, 'ruled_on' => '2026-10-01', 'ruled_at' => '2026-10-01T10:00:00Z'],
            ['id' => 'later-part', 'run' => 1, 'output' => 'sermon', 'section_id' => null, 'section_type' => null, 'edge' => 'start',
                'heard' => 31.0, 'expect' => 'keep', 'ruled_time' => null, 'ruled_on' => '2026-10-02', 'ruled_at' => '2026-10-02T10:00:00Z',
                'part' => ['section_id' => 5]],
        ];
        $fixture = ['run' => ['id' => 1], 'captured_on' => '2026-10-03', 'sections' => []];
        $opening = ['section_id' => null, 'edge' => 'start', 'heard' => 10.0, 'expect' => 'keep', 'ruled_time' => null, 'part' => null];

        $this->assertSame(['opening'], array_column($harness->latest($fixture, $opening), 'id'));
        $this->assertSame(['later-part'], array_column($harness->latest($fixture, [...$opening, 'heard' => 31.0, 'part' => ['section_id' => 5]]), 'id'));
    }

    #[Test]
    public function a_capture_is_an_open_defect_only_when_its_cut_fails_its_ruling(): void
    {
        $either = ['edge' => 'start', 'heard' => 12.0, 'expect' => 'either', 'points' => [10.0, 12.0]];
        $this->assertTrue(RuledEndingsTestCase::cutMeetsRuling($either, 10.2));
        $this->assertFalse(RuledEndingsTestCase::cutMeetsRuling($either, 11.0), 'Between the two cuts heard is neither.');
        $this->assertTrue(RuledEndingsTestCase::cutMeetsRuling([...$either, 'expect' => 'between'], 12.0));

        $clipped = ['edge' => 'end', 'heard' => 20.0, 'expect' => 'outward'];
        $this->assertTrue(RuledEndingsTestCase::cutMeetsRuling($clipped, 21.0));
        $this->assertFalse(RuledEndingsTestCase::cutMeetsRuling($clipped, 20.0));
        $this->assertTrue(RuledEndingsTestCase::cutMeetsRuling([...$clipped, 'edge' => 'start', 'expect' => 'inward'], 21.0));
        $this->assertFalse(RuledEndingsTestCase::cutMeetsRuling([...$clipped, 'expect' => 'inward'], 21.0));

        $at = ['edge' => 'end', 'heard' => 20.0, 'expect' => 'at', 'ruled_time' => 25.0];
        $this->assertTrue(RuledEndingsTestCase::cutMeetsRuling($at, 25.8));
        $this->assertFalse(RuledEndingsTestCase::cutMeetsRuling($at, 20.0));
        $this->assertFalse(RuledEndingsTestCase::cutMeetsRuling(['edge' => 'end', 'heard' => 20.0, 'expect' => 'keep'], 21.0));
    }

    /** @return array<string, mixed> */
    private function partCapture(float $cut): array
    {
        return ['id' => 'capture', 'sections' => [['id' => 5, 'section_type' => 'sermon', 'start_time' => '30.0', 'end_time' => '40.0']], 'rulings' => [
            ['id' => 'part-start', 'live' => ['cut' => $cut], 'ruling' => [
                'output' => 'sermon', 'section_id' => null, 'edge' => 'start', 'expect' => 'keep', 'heard' => $cut, 'part' => ['section_id' => 5],
            ]],
        ]];
    }

    /** @return array<string, mixed> */
    private function entry(string $id, ?int $section, string $edge): array
    {
        $cut = $edge === 'start' ? 10.0 : 20.0;

        return ['id' => $id, 'live' => ['cut' => $cut], 'ruling' => [
            'output' => $section === null ? 'sermon' : 'section', 'section_id' => $section,
            'edge' => $edge, 'expect' => 'keep', 'heard' => $cut,
        ]];
    }

    private function harness(): RuledEndingsTestCase
    {
        return new class('check') extends RuledEndingsTestCase
        {
            public int $preparations = 0;

            /** @var list<int|null> */
            public array $planned = [];

            /** @var list<string> */
            public array $checked = [];

            public ?int $failedSection = null;

            /** @var list<array{start_time: float, end_time: float}> */
            public array $segments = [['start_time' => 10.0, 'end_time' => 20.0]];

            /** @var list<array<string, mixed>> */
            public array $recorded = [];

            public function check(array $capture): void
            {
                $this->checkCapture($capture);
            }

            /**
             * @param  array<string, mixed>  $fixture
             * @param  array{section_id: int|null, edge: string, heard: float, expect: string, ruled_time: float|null, part: array{section_id: int}|null}  $ruling
             * @return list<array<string, mixed>>
             */
            public function latest(array $fixture, array $ruling): array
            {
                return $this->latestRulings($fixture, $ruling);
            }

            protected function recordedRulings(): array
            {
                return $this->recorded;
            }

            protected function prepareCapture(array $fixture): MediaProcessingLog
            {
                $this->preparations++;

                return new MediaProcessingLog;
            }

            protected function planForRuling(MediaProcessingLog $log, ?int $sectionId): array
            {
                $this->planned[] = $sectionId;
                if ($sectionId !== null && $sectionId === $this->failedSection) {
                    throw new RuntimeException('Missing evidence');
                }

                return ['segments' => $this->segments];
            }

            protected function assertRuledEdge(array $fixture, array $ruling, array $plan): void
            {
                $this->checked[] = $fixture['id'];
                parent::assertRuledEdge($fixture, $ruling, $plan);
            }
        };
    }
}
