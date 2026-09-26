<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Media;

use App\Enums\SoundClass;
use App\Services\Media\Audio\AudioTimeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;
use UnexpectedValueException;

class AudioTimelineTest extends TestCase
{
    /**
     * @return array<string, array{0: float, 1: float, 2: SoundClass}>
     */
    public static function scoreCases(): array
    {
        return [
            'music at the cut-off' => [0.40, 0.10, SoundClass::Music],
            'music just below the cut-off' => [0.39, 0.10, SoundClass::Neither],
            'instrumental music raises speech below its cut-off' => [0.80, 0.42, SoundClass::Music],
            'speech at the cut-off' => [0.10, 0.50, SoundClass::Speech],
            'speech just below the cut-off' => [0.10, 0.49, SoundClass::Neither],
            'a reading over a song outro is mixed, not speech' => [0.70, 0.70, SoundClass::Mixed],
        ];
    }

    #[Test]
    #[DataProvider('scoreCases')]
    public function it_classes_a_window_by_independent_music_and_speech_cut_offs(float $music, float $speech, SoundClass $expected): void
    {
        $timeline = AudioTimeline::fromJson(AudioTimelineFixture::json([[0, 5, $music, $speech]], 5.0));

        $this->assertSame($expected, $timeline->classOf(0));
    }

    #[Test]
    public function it_weights_shares_by_overlap_seconds_including_partial_windows(): void
    {
        // 0-10 music, 10-20 speech, 20-30 mixed.
        $timeline = AudioTimeline::fromJson(AudioTimelineFixture::json([
            [0, 10, 0.9, 0.1],
            [10, 20, 0.1, 0.9],
            [20, 30, 0.7, 0.7],
        ], 30.0));

        // 7.5-12.5: 2.5 s of music window, 2.5 s of speech window.
        $this->assertEqualsWithDelta(0.5, $timeline->musicShare(7.5, 12.5), 1e-9);
        $this->assertEqualsWithDelta(0.5, $timeline->speechShare(7.5, 12.5), 1e-9);

        // A mixed window counts towards both shares.
        $this->assertEqualsWithDelta(2 / 3, $timeline->musicShare(0.0, 30.0), 1e-9);
        $this->assertEqualsWithDelta(2 / 3, $timeline->speechShare(0.0, 30.0), 1e-9);
        $this->assertSame(0.0, $timeline->musicShare(12.0, 12.0));

        // A class share counts windows of that class alone: mixed is not music.
        $this->assertEqualsWithDelta(1 / 3, $timeline->classShare(SoundClass::Music, 0.0, 30.0), 1e-9);
        $this->assertEqualsWithDelta(1 / 3, $timeline->classShare(SoundClass::Mixed, 0.0, 30.0), 1e-9);
        $this->assertEqualsWithDelta(0.5, $timeline->classShare(SoundClass::Speech, 7.5, 12.5), 1e-9);
    }

    #[Test]
    public function it_merges_consecutive_windows_of_one_class_into_spans(): void
    {
        // 1304's shape: a song, a reading over its outro, the reading, then the next song.
        $timeline = AudioTimeline::fromJson(AudioTimelineFixture::json([
            [145, 215, 0.9, 0.1],
            [215, 220, 0.7, 0.7],
            [220, 265, 0.1, 0.9],
            [265, 360, 0.9, 0.2],
        ], 402.5));

        $this->assertSame([[145.0, 215.0], [265.0, 360.0]], $timeline->spans(SoundClass::Music, 15.0));
        $this->assertSame([[215.0, 220.0]], $timeline->spans(SoundClass::Mixed));
        $this->assertSame([], $timeline->spans(SoundClass::Mixed, 15.0));
        $this->assertSame([[0.0, 145.0], [360.0, 402.5]], $timeline->spans(SoundClass::Neither, 15.0));
    }

    #[Test]
    public function it_finds_the_window_holding_a_time(): void
    {
        $timeline = AudioTimeline::fromJson(AudioTimelineFixture::json([], 12.0));

        $this->assertSame(0, $timeline->windowAt(0.0));
        $this->assertSame(1, $timeline->windowAt(9.99));
        $this->assertSame(2, $timeline->windowAt(11.9));
        $this->assertNull($timeline->windowAt(12.0));
        $this->assertNull($timeline->windowAt(-1.0));
        $this->assertSame(12.0, $timeline->end());
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function malformedCases(): array
    {
        return [
            'no model revision' => [fn (array $p): array => array_replace($p, ['model_revision' => '']), 'model and revision'],
            'no windows' => [fn (array $p): array => array_replace($p, ['windows' => []]), 'no windows'],
            'a gap between windows' => [function (array $p): array {
                unset($p['windows'][1]);

                return $p;
            }, 'contiguous'],
            'not starting at zero' => [function (array $p): array {
                array_shift($p['windows']);

                return $p;
            }, 'contiguous'],
            'a window longer than the window length' => [function (array $p): array {
                $p['windows'][0]['end'] = 6.0;

                return $p;
            }, 'at most'],
            'a score outside 0-1' => [function (array $p): array {
                $p['windows'][0]['music'] = 1.5;

                return $p;
            }, 'outside 0-1'],
            'a missing score' => [function (array $p): array {
                unset($p['windows'][0]['speech']);

                return $p;
            }, 'no speech'],
            'short coverage' => [fn (array $p): array => array_replace($p, ['audio_seconds' => 60.0]), 'covers 30.0s of 60.0s'],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $corrupt
     */
    #[Test]
    #[DataProvider('malformedCases')]
    public function it_refuses_a_malformed_or_short_artifact(callable $corrupt, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        AudioTimeline::fromArray($corrupt(AudioTimelineFixture::payload([], 30.0)));
    }

    #[Test]
    public function it_refuses_content_that_is_not_json(): void
    {
        $this->expectException(UnexpectedValueException::class);

        AudioTimeline::fromJson('not json');
    }
}
