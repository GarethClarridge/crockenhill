<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\ChurchService\SpokenEdgeSentenceCheck;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\ServiceArtifactDisk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Operator-ruled endings as a pipeline regression set (plan §4.5, 2026-10-14).
 *
 * Each fixture in tests/Fixtures/RuledEndings is one ending the operator ruled by ear in the
 * 2026-10 endings listening (storage/scratch/endings-listening/answer-key.json), captured from
 * a settled run: its sections, transcript, audio timeline, sound levels around the section edges
 * (with the threshold the whole recording gives), and the banked edge words and sentence answers.
 * The cut planner is shared by weekly and historic livestream runs, so a fix that moves a ruled
 * edge fails here whichever path it was meant for.
 *
 * Two checks per fixture:
 *  - fidelity: rebuilt from the fixture, the planner gives the cut it gave on the live data at
 *    capture (`live`), so the fixture holds everything the planner reads;
 *  - the ruling, by what it expects (tests/Fixtures/Rulings/edge-rulings.json, plan §4.6): `keep`
 *    stays within 0.31 s of the cut the operator heard; `outward` opens earlier or ends later than
 *    it; `inward` opens later or ends earlier; `at` is within a second of the time in the note.
 *    The first fixtures predate that set and carry only an ending's answer (right or clips_word).
 *
 * A fixture whose capture predates a deliberate change may be re-captured with
 * scripts/ruled-endings/capture.php (sample in manifest.json); the ruling check is what must keep passing.
 */
class RuledEndingsRegressionTest extends TestCase
{
    use DatabaseTransactions;

    /** score.py's line between an ending that stayed and one that moved. */
    private const RIGHT_TOLERANCE = 0.31;

    private const FIDELITY_TOLERANCE = 0.02;

    /** How far an edge must move to count as having moved off a cut ruled wrong. */
    private const MOVED = 0.05;

    /** The operator gives a time to the second or the tenth of a second. */
    private const RULED_TIME_TOLERANCE = 1.0;

    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        $fixtures = [];

        foreach (glob(dirname(__DIR__, 3).'/Fixtures/RuledEndings/*.json.gz') ?: [] as $path) {
            $fixtures[basename($path, '.json.gz')] = [$path];
        }

        return $fixtures;
    }

    #[Test]
    public function a_k16_sermon_end_beside_a_song_is_left_to_the_song_rule(): void
    {
        // Withdrawn 10-19: asking here moved 11 and held 24 of 232 edges ruled right, and made
        // 1115 itself worse (storage/scratch/edge-gate-20261019).
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-edges-20261018-1115-end.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        OpenAI::fake(array_fill(0, 40, CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'decision' => 'extend', 'words_to_move' => 'stand and sing it together this evening.',
            'reason' => 'The invitation completes the sentence already included.',
        ], JSON_THROW_ON_ERROR)]]]])));

        app(SpokenEdgeSentenceCheck::class)->prepare($log, $log->processing_metadata->raw['sermon_composition']['selected_section_ids']);
        $plan = app(SermonExtractionPlanResolver::class)->resolve($log->fresh());

        $this->assertFalse(array_any($this->askedEdges($log, 'end'), static fn (float $time): bool => abs($time - 1490.74) < 0.01), 'the K16 end is not a sentence question');
        $this->assertEqualsWithDelta($fixture['live']['cut'], end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE);
    }

    /**
     * Withdrawn 10-19 with the K16 question: an end with no next section and an opening without
     * a spoken neighbour (928's openings, 1053's) were asked too. Only an opening the dead-feed
     * rule exposes is asked without one (957).
     */
    #[Test]
    #[TestWith(['listening-edges-20261018-928-end'])]
    #[TestWith(['listening-edges2-20261018-1053-end'])]
    #[TestWith(['listening-edges-20261018-1115-end'])]
    public function only_edges_between_two_spoken_items_are_asked(string $name): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/'.$name.'.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        OpenAI::fake(array_fill(0, 40, CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'decision' => 'keep', 'words_to_move' => '', 'reason' => 'A test response, not an operator ruling.',
        ], JSON_THROW_ON_ERROR)]]]])));
        $check = app(SpokenEdgeSentenceCheck::class);

        $selected = $log->processing_metadata->raw['sermon_composition']['selected_section_ids'];
        $banked = $this->askedEdges($log);
        $summary = $check->prepare($log, $selected);
        $log->refresh();
        $deadFeedOpenings = array_column(array_filter($check->outputEdges($log, $log->serviceSections()->orderBy('start_time')->get()->all(), $selected),
            static fn (array $cut): bool => ($cut[2] ?? null) === 'dead_feed_opening'), 1);

        $asked = array_diff_key($this->askedEdges($log), $banked);
        $this->assertCount($summary['asked'], $asked);
        foreach ($asked as $time) {
            $this->assertTrue($check->spokenSides($log, $time) !== null || array_any($deadFeedOpenings, static fn (float $opening): bool => abs($opening - $time) < 0.001),
                "{$name}: the edge at {$time}s has no spoken neighbour and is no dead-feed opening");
        }
    }

    #[Test]
    public function conflicting_sentence_answers_never_hold_a_k16_end(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-edges-20261018-1115-end.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        OpenAI::fake(array_map(static fn (string $decision): CreateResponse => CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'decision' => $decision, 'words_to_move' => 'stand and sing it together this evening.', 'reason' => 'Disputed thought.',
        ], JSON_THROW_ON_ERROR)]]]]), array_merge(...array_fill(0, 20, ['extend', 'shrink']))));
        app(SpokenEdgeSentenceCheck::class)->prepare($log, $log->processing_metadata->raw['sermon_composition']['selected_section_ids']);

        $plan = app(SermonExtractionPlanResolver::class)->resolve($log->fresh());

        $this->assertNotSame('sermon_edge_unresolved', $plan['metadata']['reason']);
        $this->assertEqualsWithDelta($fixture['live']['cut'], end($plan['segments'])['end_time'], self::FIDELITY_TOLERANCE);
    }

    #[Test]
    public function a_smeared_prayer_opening_does_not_pull_a_song_end_into_its_outro(): void
    {
        // Heard blind 10-20: 3833.43 s is right; bounding the song at the smeared "Our" (3830.46 s)
        // cut the song, as it did 12 of the 20 song ends that rule moved.
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-edges3-20261018-954-s901-end.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        $plan = app(CueSafeExtractionPlan::class)->forSection($log->serviceSections()->findOrFail(901));

        $this->assertEqualsWithDelta(3833.43, end($plan['segments'])['end_time'], self::RIGHT_TOLERANCE, json_encode($plan['cue_edge_widening'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_reading_after_a_song_does_not_use_its_last_lyric_as_the_speech_onset(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-last-20261019-1291-start.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);

        $edges = app(CueSafeExtractionPlan::class)->songEndSpeechEdges($log, [['start_time' => 950.494, 'end_time' => 1115.54]]);

        $this->assertSame([1119.86], $edges, 'decode the reading cue, rather than anchoring it on the preceding sung lyric');
    }

    #[Test]
    public function an_opening_mic_check_can_be_removed_without_losing_the_sermon_introduction(): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/RuledEndings/listening-edges-20261018-957-start.json.gz')), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        OpenAI::fake(array_fill(0, 20, CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'decision' => 'shrink', 'words_to_move' => 'Is that a bit better?', 'reason' => 'The microphone check precedes the introduction.',
        ], JSON_THROW_ON_ERROR)]]]])));
        $summary = app(SpokenEdgeSentenceCheck::class)->prepare($log, $log->processing_metadata->raw['sermon_composition']['selected_section_ids']);
        $plan = app(SermonExtractionPlanResolver::class)->resolve($log->fresh());

        $this->assertGreaterThan(0, $summary['agreed_moves']);
        $this->assertGreaterThanOrEqual(30.98, $plan['segments'][0]['start_time']);
        $this->assertLessThanOrEqual(32.0, $plan['segments'][0]['start_time'], 'keep "Let me begin"');
        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => str_contains($parameters['messages'][0]['content'], 'An opening check also removes complete microphone checks'));
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function a_ruled_edge_keeps_its_ruling(string $path): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
        $ruling = $this->ruling($fixture);
        $log = $this->rebuild($fixture);
        $this->replaySongIntroduction($log, $fixture['song_introduction'] ?? null);

        $plan = $ruling['section_id'] === null
            ? app(SermonExtractionPlanResolver::class)->resolve($log)
            : app(CueSafeExtractionPlan::class)->forSection(ServiceSection::query()->findOrFail($ruling['section_id']));
        $segments = $plan['segments'];
        if (isset($fixture['expected_refusal'])) {
            $this->assertSame([], $segments);
            $this->assertSame($fixture['expected_refusal'], $plan['metadata']['reason'] ?? null);
            $this->assertSame([], $plan['metadata']['trailing_section_ids']);

            return;
        }
        $this->assertNotEmpty($segments, $fixture['id'].': '.json_encode($plan['metadata'] ?? []));
        $cut = $ruling['edge'] === 'start' ? reset($segments)['start_time'] : end($segments)['end_time'];
        if (isset($fixture['expected_range'])) {
            $this->assertGreaterThanOrEqual($fixture['expected_range'][0], $cut);
            $this->assertLessThanOrEqual($fixture['expected_range'][1], $cut);

            return;
        }

        $this->assertEqualsWithDelta($fixture['live']['cut'], $cut, self::FIDELITY_TOLERANCE, 'the fixture no longer reproduces its live cut');

        $heard = $ruling['heard'];
        if ($fixture['open_defect'] ?? false) {
            // Keep the failing cut reproducible until its own red-to-green regression lands.
            $this->assertNotSame('keep', $ruling['expect']);

            return;
        }
        // Later in the recording, for an end; earlier, for a start.
        $outward = $ruling['edge'] === 'end' ? $cut - $heard : $heard - $cut;

        match ($ruling['expect']) {
            'keep' => $this->assertEqualsWithDelta($heard, $cut, self::RIGHT_TOLERANCE, 'an edge ruled right has moved'),
            'outward' => $this->assertGreaterThan(self::MOVED, $outward, 'an edge ruled to clip its item is back at the clipped cut'),
            'inward' => $this->assertLessThan(-self::MOVED, $outward, 'an edge ruled to take in too much is back at that cut'),
            'at' => $this->assertEqualsWithDelta($ruling['ruled_time'], $cut, self::RULED_TIME_TOLERANCE, 'an edge is not at the time the operator gave'),
        };
    }

    /**
     * The ruling in the shape of edge-rulings.json; the first fixtures carry an ending's answer
     * and the cut heard, with the output in their id (end-<run>-<sermon|section id>).
     *
     * @param  array<string, mixed>  $fixture
     * @return array{section_id: int|null, edge: string, heard: float, expect: string, ruled_time: float|null}
     */
    private function ruling(array $fixture): array
    {
        $ruling = $fixture['ruling'];

        if (isset($ruling['expect'])) {
            return [
                'section_id' => $ruling['output'] === 'sermon' ? null : (int) $ruling['section_id'],
                'edge' => $ruling['edge'],
                'heard' => (float) $ruling['heard'],
                'expect' => $ruling['expect'],
                'ruled_time' => isset($ruling['ruled_time']) ? (float) $ruling['ruled_time'] : null,
            ];
        }

        [, , $which] = explode('-', $fixture['id'], 3);

        return [
            'section_id' => $which === 'sermon' ? null : (int) $which,
            'edge' => 'end',
            'heard' => (float) $ruling['ruled_cut'],
            'expect' => ['right' => 'keep', 'clips_word' => 'outward'][$ruling['answer']],
            'ruled_time' => null,
        ];
    }

    /** @param  array<string, mixed>  $fixture */
    private function rebuild(array $fixture): MediaProcessingLog
    {
        config($fixture['config']);
        // The trimmed sound levels would give a different adaptive threshold; pin the one the
        // whole recording gave.
        config([
            'media-processing.segmentation.adaptive_thresholds.enabled' => false,
            'media-processing.segmentation.rms_threshold' => $fixture['rms']['threshold'],
        ]);

        $disks = array_unique([...array_column($fixture['files'], 'disk'), $fixture['rms']['disk']]);

        // Point artifact and temp paths at the disks the files were read from.
        foreach ([...$fixture['files'], ['disk' => $fixture['rms']['disk'], 'path' => $fixture['run']['rms_log_path']]] as $file) {
            config([ServiceArtifactDisk::isArtifactPath($file['path'])
                ? 'media-processing.storage.service_artifact_disk'
                : 'media-processing.storage.temp_disk' => $file['disk']]);
        }

        foreach ($disks as $disk) {
            Storage::fake($disk);
        }

        foreach ($fixture['files'] as $file) {
            Storage::disk($file['disk'])->put($file['path'], $file['content']);
        }

        Storage::disk($fixture['rms']['disk'])->put($fixture['run']['rms_log_path'], $this->rmsLog($fixture['rms']['samples']));

        $run = $fixture['run'];
        $log = MediaProcessingLog::factory()->livestream()->make();
        $log->forceFill([
            'id' => $run['id'],
            'processing_id' => $run['processing_id'],
            'status' => $run['status'],
            'duration' => $run['duration'],
            'extracted_date' => $run['extracted_date'],
            'extracted_service' => $run['extracted_service'],
            'audio_timeline_path' => $run['audio_timeline_path'],
            'rms_log_path' => $run['rms_log_path'],
            'processing_metadata' => $run['processing_metadata'],
            'church_service_id' => null,
            'sermon_id' => null,
        ])->save();

        // Publication is not captured (the planner does not read it, and the schema ties a
        // published or approved section to media this fixture does not hold).
        DB::table('service_sections')->insert(array_map(static fn (array $section): array => [
            ...$section,
            'publication_status' => 'not_applicable',
            'media_processing_log_id' => $log->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $fixture['sections']));

        return $log->fresh() ?? $log;
    }

    /**
     * The edge step's K16 answer, replayed from the model's recorded answers to the recorded
     * question ({@see SermonSongIntroductionCheck}): the question rebuilt from the fixture must be
     * the one the model answered.
     *
     * @param  array{prompt: string, calls: list<array<string, mixed>>, edge_words?: list<array{time: float, words: list<array{start: float, end: float, word: string}>}>}|null  $recorded
     */
    private function replaySongIntroduction(MediaProcessingLog $log, ?array $recorded): void
    {
        if ($recorded === null) {
            return;
        }

        OpenAI::fake(array_map(static fn (array $call): CreateResponse => CreateResponse::fake(['choices' => [['message' => [
            'content' => json_encode($call, JSON_THROW_ON_ERROR),
        ]]]]), $recorded['calls']));
        $selected = $log->processing_metadata?->raw['sermon_composition']['selected_section_ids'] ?? [];
        $spans = array_values($log->serviceSections()->whereIn('id', $selected)->orderBy('start_time')->get()
            ->map(static fn (ServiceSection $section): array => ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time])->all());

        app(SermonSongIntroductionCheck::class)->prepare($log, $spans);

        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => $parameters['messages'][1]['content'] === $recorded['prompt']);

        // The words at the end the answer moves to, which the edge step decodes after asking.
        $evidence = app(OutputEdgeWordTimings::class);
        foreach ($recorded['edge_words'] ?? [] as $edge) {
            $window = $evidence->window($evidence->cues($log), (float) $edge['time'], $log->duration) ?? throw new \LogicException('No edge window at '.$edge['time']);
            $identity = $evidence->identity($log, $window);
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($identity), ['identity' => $identity, 'words' => $edge['words'], 'compute_seconds' => 0.0]);
        }
        $log->refresh();
    }

    /**
     * The cuts the sentence check has banked an answer for, by artifact kind.
     *
     * @return array<string, float>
     */
    private function askedEdges(MediaProcessingLog $log, ?string $edge = null): array
    {
        $asked = [];
        foreach (ServiceArtifactStorage::recordedFor($log->fresh() ?? $log) as $entry) {
            if (str_starts_with($entry['kind'], 'edge-sentence-')) {
                $identity = json_decode((string) Storage::disk($entry['disk'])->get($entry['path']), true)['identity'];
                if ($edge === null || $identity['edge'] === $edge) {
                    $asked[$entry['kind']] = (float) $identity['original'];
                }
            }
        }

        return $asked;
    }

    /** @param  list<array{0: float, 1: float}>  $samples */
    private function rmsLog(array $samples): string
    {
        $lines = [];

        foreach ($samples as $index => [$time, $level]) {
            $lines[] = sprintf('frame:%d pts:0 pts_time:%s', $index, $time);
            $lines[] = 'lavfi.astats.Overall.RMS_level='.($level <= -999.0 ? '-inf' : $level);
        }

        return implode("\n", $lines)."\n";
    }
}
