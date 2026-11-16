<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\SongEndOutro;
use App\Services\ChurchService\SongEndSpokenWords;
use App\Services\ChurchService\SongEndStems;
use App\Services\Media\Audio\AudioTimeline;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionMethod;
use Tests\TestCase;

/** Saved C1 inputs heard on 14 November: real planner path, without database or media access. */
class SongEndFadeSpeechTest extends TestCase
{
    #[Test]
    #[TestWith(['1042/1521'])]
    #[TestWith(['1064/1674'])]
    #[TestWith(['1146/2187'])]
    #[TestWith(['1168/2303'])]
    #[TestWith(['1210/2587'])]
    #[TestWith(['1219/2698'])]
    #[TestWith(['1290/3628'])]
    #[TestWith(['1295/166091'])]
    #[TestWith(['1299/214775'])]
    public function the_song_ends_before_corroborated_talking_inside_its_fade(string $key): void
    {
        $row = $this->fixture($key);
        $ending = $this->ending($row);

        $this->assertNotNull($ending);
        $this->assertSame((float) $row['expected'], $ending['time']);
        $this->assertSame(CueSafeExtractionPlan::cutBeforeTheMove($row['before'])['time'], CueSafeExtractionPlan::cutBeforeTheMove($ending)['time']);
        $this->assertSame($row['fade'], $ending['fade_out']);
        $this->assertArrayNotHasKey('voice_removal', $ending);
    }

    #[Test]
    #[TestWith(['1242/3009'])]
    #[TestWith(['1278/3483'])]
    #[TestWith(['1282/3535'])]
    public function unused_stems_do_not_reopen_a_cut_after_the_music_has_stopped(string $key): void
    {
        $row = $this->fixture($key);
        $ending = $this->ending($row);

        $this->assertSame((float) $row['expected'], $ending['time']);
        $this->assertSame((float) $row['fade'], $ending['fade_out']);
        $this->assertArrayNotHasKey('fade_speech', $ending);
    }

    #[Test]
    public function missing_stems_keep_the_existing_planner_decision(): void
    {
        $row = $this->fixture('1042/1521');
        $this->mock(SongEndStems::class)->shouldReceive('read')->andReturnNull();

        $this->assertSame($this->ending($row, 'songEndBeforeFadeSpeech'), $this->ending($row));
    }

    #[Test]
    #[TestWith(['one_window'])]
    #[TestWith(['duplicate_window'])]
    #[TestWith(['lyric'])]
    #[TestWith(['zero_length'])]
    #[TestWith(['stacked'])]
    #[TestWith(['smeared'])]
    #[TestWith(['window_edge'])]
    public function a_voice_return_requires_independent_short_non_lyric_words(string $missing): void
    {
        $words = [['start' => 100.2, 'end' => 100.4, 'word' => 'please'],
            ['start' => 100.5, 'end' => 100.8, 'word' => 'stand']];
        $decodes = [['start' => 90.0, 'end' => 110.0, 'words' => $words],
            ['start' => 92.0, 'end' => 112.0, 'words' => $words]];
        $reader = new SongEndSpokenWords;
        $this->assertTrue($reader->nearTalking($decodes, 100.0, null));
        $lyrics = null;
        if ($missing === 'one_window') {
            array_pop($decodes);
        } elseif ($missing === 'duplicate_window') {
            $decodes[1] = $decodes[0];
        } elseif ($missing === 'lyric') {
            $lyrics = 'please stand';
        } else {
            foreach ($decodes as &$decode) {
                if ($missing === 'window_edge') {
                    $decode['end'] = 101.0;
                } elseif ($missing === 'stacked') {
                    $decode['words'] = array_fill(0, 3, $words[0]);
                } else {
                    $decode['words'] = [array_replace($words[0], ['end' => $missing === 'zero_length' ? 100.2 : 102.0])];
                }
            }
            unset($decode);
        }

        $this->assertFalse($reader->nearTalking($decodes, 100.0, $lyrics));
    }

    #[Test]
    public function the_cut_cannot_precede_the_last_sung_sound(): void
    {
        $stems = ['start' => 90.0, 'frame_seconds' => 0.1,
            'voice_db' => [...array_fill(0, 90, -25.0), ...array_fill(0, 10, -90.0), ...array_fill(0, 50, -30.0)]];
        $words = [['start' => 100.2, 'end' => 100.4, 'word' => 'please']];
        $decodes = [['start' => 90.0, 'end' => 110.0, 'words' => $words], ['start' => 92.0, 'end' => 112.0, 'words' => $words]];
        $decision = ['time' => 101.0, 'fade_out' => 3.0, 'last_sound' => 100.1];
        $ending = (new SongEndOutro)->beforeFadeSpeech($stems, $decision, $decodes, null);

        $this->assertSame(100.1, $ending['time']);
        $this->assertNull((new SongEndOutro)->beforeFadeSpeech($stems, $decision, [], null));
        $this->assertSame(0.1, $ending['fade_out']);
        $this->assertNull((new SongEndOutro)->beforeFadeSpeech($stems, [...$decision, 'last_sound' => 101.0], $decodes, null));
    }

    /** @return array<string, mixed> */
    private function fixture(string $key): array
    {
        $path = dirname(__DIR__, 3).'/Fixtures/ChurchService/song-end-fade-speech.json.gz';
        $fixture = json_decode(gzdecode(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
        config($fixture['config']);

        return array_find($fixture['rows'], static fn (array $row): bool => $row['key'] === $key);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function ending(array $row, string $method = 'songEnd'): ?array
    {
        DB::shouldReceive('connection')->never();
        $log = new class extends MediaProcessingLog
        {
            public HasMany $fixtureSections;

            public function serviceSections(): HasMany
            {
                return $this->fixtureSections;
            }
        };
        $log->setRawAttributes($row['attributes']);
        $sections = new Collection(array_map(static fn (array $attributes): ServiceSection => (new ServiceSection)->setRawAttributes($attributes), $row['sections']));
        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('orderBy')->andReturnSelf();
        $relation->shouldReceive('get')->andReturn($sections->sortBy('start_time'));
        $log->fixtureSections = $relation;
        foreach ($row['files'] as $disk => $files) {
            $reader = Mockery::mock();
            foreach ($files as $path => $content) {
                $reader->shouldReceive('get')->with($path)->andReturn($content);
            }
            Storage::shouldReceive('disk')->with($disk)->andReturn($reader);
        }
        $args = $this->timingFloats($row['args']);
        $args[4] = AudioTimeline::fromArray($args[4]);

        return (new ReflectionMethod(CueSafeExtractionPlan::class, $method))->invoke(app(CueSafeExtractionPlan::class), $log, ...$args);
    }

    /** Restore whole-second timing floats, which JSON otherwise reads as integers. */
    private function timingFloats(mixed $value): mixed
    {
        return is_array($value) ? array_map($this->timingFloats(...), $value) : (is_int($value) ? (float) $value : $value);
    }
}
