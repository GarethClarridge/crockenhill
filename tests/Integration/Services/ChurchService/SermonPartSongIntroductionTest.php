<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * Pre-freeze review 2026-10-31: K16 asked only where the sermon output's last part ends, so an
 * earlier part with a song after it ran on into the song's announcement. 1154's reading section
 * took in "We're going to stand and sing again / Prepare our hearts oh God" before the song that
 * comes ahead of the sermon; 1073's and 1268's split sermons (D21) kept the announcement of the
 * mid-sermon song. A sermon's media is its reading, the sermon and a closing prayer as named
 * sections (K1): an earlier part ends at its own last line too (operator 2026-11-01, "Fix before the round").
 */
class SermonPartSongIntroductionTest extends TestCase
{
    use DatabaseTransactions;

    private const READING = ['start_time' => 1490.16, 'end_time' => 1626.67];

    private const SERMON = ['start_time' => 1910.0, 'end_time' => 3000.0];

    #[Test]
    public function a_reading_before_the_song_ahead_of_the_sermon_ends_at_its_own_last_line(): void
    {
        $log = $this->service();
        $check = app(SermonSongIntroductionCheck::class);
        $this->assertSame([0], $check->innerParts($log, $this->spans()));
        $line = $this->lineNumber($log, 'We thank God for the reading of his word');
        $this->answers([$line, false, $line], [$line, false, $line]);

        $result = $check->prepare($log, $this->spans(), 0);

        $this->assertTrue($result['asked']);
        $this->assertSame(1623.4, $result['end'], 'before "We\'re going to stand and sing again"');
        $this->assertSame(1623.4, $check->read($log->fresh(), $this->spans(), 0)['end'] ?? null);
        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => str_starts_with($parameters['messages'][0]['content'], 'You read the end of a Bible reading or prayer')
            && str_starts_with($parameters['messages'][1]['content'], 'Bible reading: "Jonah 3"'));
    }

    /**
     * The output's own end is asked exactly as before, whatever its section, so its banked answers
     * (and a closing prayer's, asked as the sermon's end since 10-16) still stand.
     */
    #[Test]
    public function the_output_end_question_is_unchanged_by_the_part_question(): void
    {
        $log = $this->service();

        $whole = app(SermonSongIntroductionCheck::class)->question($log, [self::READING]);
        $part = app(SermonSongIntroductionCheck::class)->question($log, $this->spans(), 0);

        $this->assertArrayNotHasKey('part', $whole['identity'] ?? []);
        $this->assertStringStartsWith('Sermon: ', $whole['prompt'] ?? '');
        $this->assertSame('bible_reading', $part['identity']['part'] ?? null);
    }

    /** 1233: a prayer part between the reading and the song; the reading's next part comes first. */
    #[Test]
    public function only_a_part_with_the_song_before_the_next_part_is_asked(): void
    {
        $log = $this->service(sections: [
            ['bible_reading', 1490.16, 1600.0], ['prayer', 1600.0, 1626.67], ['song', 1648.46, 1900.0], ['sermon', 1910.0, 3000.0],
        ]);
        $spans = [['start_time' => 1490.16, 'end_time' => 1600.0], ['start_time' => 1600.0, 'end_time' => 1626.67], self::SERMON];

        $this->assertSame([1], app(SermonSongIntroductionCheck::class)->innerParts($log, $spans));
        $this->assertSame('prayer', app(SermonSongIntroductionCheck::class)->question($log, $spans, 1)['identity']['part'] ?? null);
    }

    /** 1073 and 1268 (D21): a split sermon's first half before the mid-sermon song is asked as a sermon (K16). */
    #[Test]
    public function a_split_sermons_first_half_is_asked_as_a_sermon(): void
    {
        $log = $this->service(sections: [['sermon', 1490.16, 1626.67], ['song', 1648.46, 1900.0], ['other', 1910.0, 3000.0]]);
        $this->answers([5, false, 5], [5, false, 5]);

        app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans(), 0);

        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => str_starts_with($parameters['messages'][0]['content'], 'You read the end of a sermon')
            && str_starts_with($parameters['messages'][1]['content'], 'Sermon: '));
        $this->assertSame('sermon', app(SermonSongIntroductionCheck::class)->question($log, $this->spans(), 0)['identity']['part'] ?? null);
    }

    /**
     * 1245: "…we thank God for his word to us" | "this day." splits a sentence; the part steps back to the
     * reading's own last line. 1140's shape (a line that finishes a sentence, then starts the next) stays.
     */
    #[Test]
    #[TestWith(['We thank God for his word to us', 'this day.', 1618.46])]
    #[TestWith(['We thank God for his word. And now', 'stand and sing.', 1623.4])]
    public function a_line_whose_sentence_the_next_line_finishes_steps_back(string $chosen, string $next, float $expected): void
    {
        $log = $this->service();
        $this->rewriteCue($log, 1618.46, $chosen);
        $this->rewriteCue($log, 1623.4, $next);
        $line = $this->lineNumber($log, $chosen);
        $this->answers([$line, false, $line], [$line, false, $line]);

        $this->assertSame($expected, app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans(), 0)['end']);
    }

    /** Never asked is evidence not yet gathered, as for the output's end. */
    #[Test]
    public function an_earlier_part_beside_a_song_needs_an_answer_before_planning(): void
    {
        $log = $this->service();
        OpenAI::fake([]);
        $this->expectException(OutputEdgeTimingsMissing::class);
        $this->expectExceptionMessage('has no answer on where its sermon ends');

        app(CueSafeExtractionPlan::class)->forSpans($log, $this->spans(), sermonEnd: true);
    }

    #[Test]
    public function the_planner_ends_an_earlier_part_where_its_answer_says(): void
    {
        $log = $this->service();
        $line = $this->lineNumber($log, 'We thank God for the reading of his word');
        $this->answers([$line, false, $line], [$line, false, $line]);
        app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans(), 0);
        $this->bank($log->fresh(), 1623.4, [
            ['start' => 1621.9, 'end' => 1622.3, 'word' => ' his'],
            ['start' => 1622.3, 'end' => 1622.9, 'word' => ' word.'],
            ['start' => 1623.9, 'end' => 1624.2, 'word' => " We're"],
            ['start' => 1624.2, 'end' => 1624.5, 'word' => ' going'],
        ]);
        $this->bank($log->fresh(), 1626.67, [
            ['start' => 1626.42, 'end' => 1626.9, 'word' => ' Prepare'],
        ]);

        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), $this->spans(), sermonEnd: true);

        $this->assertGreaterThanOrEqual(1622.9, $plan['segments'][0]['end_time']);
        $this->assertLessThan(1623.9, $plan['segments'][0]['end_time'], 'nothing of "We\'re going to stand and sing again"');
        $this->assertSame(self::SERMON['end_time'], $plan['segments'][1]['end_time'], 'the output\'s own end is untouched');
    }

    /** @return list<array{start_time: float, end_time: float}> */
    private function spans(): array
    {
        return [self::READING, self::SERMON];
    }

    private function lineNumber(MediaProcessingLog $log, string $text): int
    {
        return collect(app(SermonSongIntroductionCheck::class)->question($log, $this->spans(), 0)['lines'] ?? [])->firstWhere('text', $text)['n'];
    }

    /**
     * 1154's reading, the announcement inside its section, and the song (2023-01-29 morning).
     *
     * @param  list<array{0: string, 1: float, 2: float}>  $sections
     */
    private function service(array $sections = [['bible_reading', 1490.16, 1626.67], ['song', 1648.46, 1900.0], ['sermon', 1910.0, 3000.0]]): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000, 'audio_timeline_path' => 'service-transcripts/part.classes.json']);
        Storage::disk('local')->put('service-transcripts/part.classes.json', AudioTimelineFixture::json([[1550, 1648, 0.01, 0.8], [1648, 1900, 0.8, 0.02]], 5000.0));
        $log->putServiceTranscriptPath('temp/part.json');
        Storage::disk('local')->put('temp/part.json', json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 1606.2, 'end' => 1611.46, 'text' => 'When God saw what they did and how they turned from their'],
            ['start' => 1611.46, 'end' => 1613.14, 'text' => 'evil ways he relented'],
            ['start' => 1613.14, 'end' => 1618.46, 'text' => 'And did not bring on them the destruction he had threatened'],
            ['start' => 1618.46, 'end' => 1623.4, 'text' => 'We thank God for the reading of his word'],
            ['start' => 1623.4, 'end' => 1626.42, 'text' => "We're going to stand and sing again"],
            ['start' => 1626.42, 'end' => 1629.0, 'text' => 'Prepare our hearts oh God'],
            ['start' => 1629.0, 'end' => 1631.1, 'text' => "We've been singing this one over the last few weeks"],
            ['start' => 1643.68, 'end' => 1645.52, 'text' => "So let's stand and sing"],
            ['start' => 1648.46, 'end' => 1672.5, 'text' => 'Prepare our hearts oh God'],
        ], 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        foreach ($sections as [$type, $start, $end]) {
            ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::from($type),
                'start_time' => $start, 'end_time' => $end, 'title' => $type === 'bible_reading' ? 'Jonah 3' : ucfirst($type)]);
        }

        return $log->fresh();
    }

    private function rewriteCue(MediaProcessingLog $log, float $start, string $text): void
    {
        $transcript = json_decode((string) Storage::disk('local')->get('temp/part.json'), true, flags: JSON_THROW_ON_ERROR);
        $cues = array_map(static fn (array $cue): array => abs($cue['start'] - $start) < 0.001 ? [...$cue, 'text' => $text] : $cue,
            ChurchServiceTranscript::fromArray($transcript)->cues);
        Storage::disk('local')->put('temp/part.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @param list<array{start: float, end: float, word: string}> $words */
    private function bank(MediaProcessingLog $log, float $edge, array $words): void
    {
        $evidence = app(OutputEdgeWordTimings::class);
        foreach ([$evidence->timingWindow($evidence->cues($log), $edge, $log->duration), $evidence->window($evidence->cues($log), $edge, $log->duration, $log)] as $window) {
            if ($window === null) {
                continue;
            }
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
                'identity' => $evidence->identity($log, $window), 'words' => $words, 'compute_seconds' => 1.0,
            ]);
        }
        $log->refresh();
    }

    /** @param array{0: int, 1: bool, 2: int} ...$answers sermon_last_line, ties_to_sermon, video_last_line */
    private function answers(array ...$answers): void
    {
        OpenAI::fake(array_map(static fn (array $answer): CreateResponse => CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'sermon_last_line' => $answer[0], 'ties_to_sermon' => $answer[1], 'video_last_line' => $answer[2], 'reason' => 'test',
        ], JSON_THROW_ON_ERROR)]]]]), $answers));
    }
}
