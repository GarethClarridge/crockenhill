<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Song;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\ServiceArtifactDisk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;
use Throwable;

/** Shared fixture reconstruction and ruling assertions; contains no discoverable tests. */
abstract class RuledEndingsTestCase extends TestCase
{
    use DatabaseTransactions;

    /** score.py's line between an ending that stayed and one that moved. */
    protected const RIGHT_TOLERANCE = 0.31;

    protected const FIDELITY_TOLERANCE = 0.02;

    /** How far an edge must move to count as having moved off a cut ruled wrong. */
    protected const MOVED = 0.05;

    /** Decibels under the speech threshold below which a level counts as silence. */
    protected const SILENCE_BELOW_SPEECH = 20.0;

    /** The operator gives a time to the second or the tenth of a second. */
    protected const RULED_TIME_TOLERANCE = 1.0;

    /** @var list<array<string, mixed>>|null */
    private static ?array $edgeRulings = null;

    /**
     * Check every ruling even if another ruling on the same capture fails. Plans and their
     * failures are local to this capture; they must never survive a database rollback.
     *
     * @param  array<string, mixed>  $capture
     */
    protected function checkCapture(array $capture): void
    {
        $entries = $capture['rulings'] ?? [[]];
        $this->assertNotEmpty($entries, $capture['id'].': capture has no rulings');
        unset($capture['rulings']);
        $log = $this->prepareCapture($capture);
        $plans = [];
        $failures = [];

        foreach ($entries as $entry) {
            $fixture = [...$capture, ...$entry];
            try {
                // These fields affect planner inputs and cannot vary within a shared rebuild.
                $this->assertSame([], array_intersect(array_keys($entry), ['config', 'run', 'files', 'sections', 'rms', 'song_introduction']), $fixture['id'].': ruling overrides shared evidence');
                $ruling = $this->ruling($fixture);
                $key = $ruling['section_id'] === null ? 'sermon' : 'section:'.$ruling['section_id'];
                if (! array_key_exists($key, $plans)) {
                    try {
                        $plans[$key] = $this->planForRuling($log, $ruling['section_id']);
                    } catch (Throwable $exception) {
                        $plans[$key] = $exception;
                    }
                }
                if ($plans[$key] instanceof Throwable) {
                    throw $plans[$key];
                }
                $this->assertRuledEdge($fixture, $ruling, $plans[$key]);
            } catch (Throwable $exception) {
                $failures[] = $fixture['id'].': '.$exception::class.': '.$exception->getMessage();
            }
        }

        if ($failures !== []) {
            throw new AssertionFailedError(implode("\n\n", $failures));
        }
    }

    /** @param array<string, mixed> $fixture */
    protected function prepareCapture(array $fixture): MediaProcessingLog
    {
        $log = $this->rebuild($fixture);
        // Full captures hold the live bank: an obsolete question must remain unanswered.
        if (($fixture['rms']['input_precision'] ?? null) !== 'full') {
            $this->replaySongIntroduction($log, $fixture['song_introduction'] ?? null);
        }

        return $log;
    }

    /** @return array<string, mixed> */
    protected function planForRuling(MediaProcessingLog $log, ?int $sectionId): array
    {
        // Sermon composition writes metadata and section review flags. Roll those writes back
        // before another output is planned, while retaining the expensive fixture rebuild.
        $connection = $log->getConnection();
        $connection->beginTransaction();
        try {
            return $sectionId === null
                ? app(SermonExtractionPlanResolver::class)->resolve($log->fresh())
                : app(CueSafeExtractionPlan::class)->forSection($log->serviceSections()->findOrFail($sectionId));
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array{section_id: int|null, edge: string, heard: float, expect: string, ruled_time: float|null, part: array{section_id: int}|null}  $ruling
     * @param  array<string, mixed>  $plan
     */
    protected function assertRuledEdge(array $fixture, array $ruling, array $plan): void
    {
        $segments = $plan['segments'];
        if (isset($fixture['expected_refusal'])) {
            $this->assertSame([], $segments);
            $this->assertSame($fixture['expected_refusal'], $plan['metadata']['reason'] ?? null);
            $this->assertSame([], $plan['metadata']['trailing_section_ids']);

            return;
        }
        $this->assertNotEmpty($segments, $fixture['id'].': '.json_encode($plan['metadata'] ?? []));
        $cut = match (true) {
            $ruling['part'] !== null => $segments[SermonVideoPart::segmentIndex($segments, SermonVideoPart::section($fixture['sections'], $ruling['part']['section_id']))]['start_time'],
            $ruling['edge'] === 'start' => reset($segments)['start_time'],
            default => end($segments)['end_time'],
        };
        if (isset($fixture['expected_range'])) {
            $this->assertGreaterThanOrEqual($fixture['expected_range'][0], $cut);
            $this->assertLessThanOrEqual($fixture['expected_range'][1], $cut);

            return;
        }

        $this->assertEqualsWithDelta($fixture['live']['cut'], $cut, self::FIDELITY_TOLERANCE, $fixture['id'].': the fixture no longer reproduces its live cut');
        if (isset($fixture['wide_edge_revision'])) {
            $this->assertArrayHasKey('segments', $fixture['live']);
            $this->assertCount(count($fixture['live']['segments']), $segments, $fixture['id'].': the fixture no longer reproduces its live segment count: '.json_encode($segments));
            foreach ($fixture['live']['segments'] as $index => $expectedSegment) {
                foreach (['start_time', 'end_time'] as $boundary) {
                    $this->assertEqualsWithDelta($expectedSegment[$boundary], $segments[$index][$boundary], self::FIDELITY_TOLERANCE,
                        $fixture['id'].': the fixture no longer reproduces its live segment '.$index.' '.$boundary);
                }
            }
        }

        // A cut the sentence rule (10-26) makes against this older ruling, with its source: heard
        // right again on 10-26, or (1130 §2066) an open miss heard wrong whose ruling still stands.
        if (isset($fixture['song_start_sentence_policy'])) {
            $this->assertSame('start', $ruling['edge']);
            $this->assertEqualsWithDelta($fixture['song_start_sentence_policy']['time'], $cut, self::FIDELITY_TOLERANCE);
            $entry = array_values(array_filter($plan['cue_edge_widening'], static fn (array $entry): bool => $entry['edge'] === 'start'))[0];
            $this->assertSame('song_start_sentence', $entry['reason']);

            return;
        }

        // A cut the clipped-opening rule (10-26, E22) moves from this older ruling, heard again
        // against it the same day and found as good or better.
        if (isset($fixture['clipped_opening_relistened'])) {
            $this->assertEqualsWithDelta($fixture['clipped_opening_relistened']['time'], $cut, self::FIDELITY_TOLERANCE);

            return;
        }

        if ($fixture['open_defect'] ?? false) {
            // Keep the failing cut reproducible until its own red-to-green regression lands.
            $this->assertNotSame('keep', $ruling['expect']);

            return;
        }

        // A later listening heard a later cut of the same edge, so its ruling is the one to keep.
        $rulings = $this->latestRulings($fixture, $ruling);
        $heardRight = array_column(array_filter($rulings, static fn (array $latest): bool => $latest['expect'] === 'keep'), 'heard');
        if (count($rulings) > 1 && count($heardRight) === count($rulings)) {
            // Two cuts heard right the same day: either is right (1250 §3127), not the stretch between.
            $rulings = [['id' => implode(' + ', array_column($rulings, 'id')), 'edge' => $ruling['edge'], 'heard' => $heardRight[0],
                'expect' => 'either', 'ruled_time' => null, 'points' => $heardRight]];
        }

        foreach ($rulings as $latest) {
            $heard = $latest['heard'];
            // Later in the recording, for an end; earlier, for a start.
            $outward = $latest['edge'] === 'end' ? $cut - $heard : $heard - $cut;
            $message = $fixture['id'].' against '.$latest['id'];

            match ($latest['expect']) {
                'keep' => $this->assertTrue(abs($cut - $heard) <= self::RIGHT_TOLERANCE || $this->onlySilenceBetween($fixture, $heard, $cut),
                    "{$message}: an edge ruled right has moved from {$heard} to {$cut}, across sound"),
                // Either cut was heard right; a cut between them may split a sentence nobody heard.
                'either' => $this->assertTrue(array_any($latest['points'], fn (float $point): bool => abs($cut - $point) <= self::RIGHT_TOLERANCE
                    || $this->onlySilenceBetween($fixture, $point, $cut)),
                    "{$message}: {$cut} is at neither cut ruled right, ".implode(' or ', $latest['points'])),
                'outward' => $this->assertGreaterThan(self::MOVED, $outward, $message.': an edge ruled to clip its item is back at the clipped cut'),
                'inward' => $this->assertLessThan(-self::MOVED, $outward, $message.': an edge ruled to take in too much is back at that cut'),
                'at' => $this->assertEqualsWithDelta($latest['ruled_time'], $cut, self::RULED_TIME_TOLERANCE, $message.': an edge is not at the time the operator gave'),
            };
        }
    }

    /**
     * Whether an edge moved only through silence: every level between the two times, which the
     * fixture's windows must cover, is at least SILENCE_BELOW_SPEECH under the recording's speech
     * threshold. A move across a dead feed changes nothing anyone hears (1012's start, 10-22).
     *
     * @param  array<string, mixed>  $fixture
     */
    protected function onlySilenceBetween(array $fixture, float $from, float $to): bool
    {
        [$from, $to] = [min($from, $to), max($from, $to)];
        $levels = array_values(array_filter($fixture['rms']['samples'], static fn (array $sample): bool => $sample[0] >= $from && $sample[0] <= $to));

        return $levels !== []
            && $levels[0][0] - $from <= 0.5
            && $to - end($levels)[0] <= 0.5
            && array_all($levels, static fn (array $sample): bool => $sample[1] <= $fixture['rms']['threshold'] - self::SILENCE_BELOW_SPEECH);
    }

    /**
     * The newest rulings on the fixture's edge in tests/Fixtures/Rulings/edge-rulings.json made by the
     * day it was captured: a later listening heard a later cut, which data captured before it may not
     * reproduce. A fixture without a capture date keeps its own ruling. Newest is by the answer's time,
     * so a decision recorded after a listening the same day replaces it. A ruling on a later part of a
     * sermon video ({@see SermonVideoPart}) and one on its opening never stand for each other. A section ruling without a
     * section id takes the run's section of its type whose edge is nearest the cut heard, as capture does.
     *
     * @param  array<string, mixed>  $fixture
     * @param  array{section_id: int|null, edge: string, heard: float, expect: string, ruled_time: float|null, part: array{section_id: int}|null}  $ruling
     * @return list<array{id: string, edge: string, heard: float, expect: string, ruled_time: float|null, points?: list<float>}>
     */
    protected function latestRulings(array $fixture, array $ruling): array
    {
        $own = [['id' => 'the fixture\'s ruling', ...$ruling]];
        $capturedOn = $fixture['captured_on'] ?? null;
        if ($capturedOn === null) {
            return $own;
        }

        $column = $ruling['edge'] === 'start' ? 'start_time' : 'end_time';
        $sectionOf = static function (array $candidate) use ($fixture, $column): ?int {
            if ($candidate['output'] === 'sermon' || $candidate['section_id'] !== null) {
                return $candidate['output'] === 'sermon' ? null : $candidate['section_id'];
            }
            $sections = array_filter($fixture['sections'], static fn (array $section): bool => $candidate['section_type'] === null || $section['section_type'] === $candidate['section_type']);
            usort($sections, static fn (array $a, array $b): int => abs((float) $a[$column] - $candidate['heard']) <=> abs((float) $b[$column] - $candidate['heard']));

            return $sections === [] ? null : (int) $sections[0]['id'];
        };

        $onEdge = array_filter($this->recordedRulings(), static fn (array $candidate): bool => $candidate['run'] === (int) $fixture['run']['id']
            && $candidate['edge'] === $ruling['edge']
            && $candidate['ruled_on'] <= $capturedOn
            && ($candidate['output'] === 'sermon') === ($ruling['section_id'] === null)
            && ($candidate['part'] ?? null) === $ruling['part']
            && $sectionOf($candidate) === $ruling['section_id']);
        if ($onEdge === []) {
            return $own;
        }

        $newest = max(array_column($onEdge, 'ruled_at'));

        return array_values(array_filter($onEdge, static fn (array $candidate): bool => $candidate['ruled_at'] === $newest));
    }

    /**
     * The operator's rulings, as gathered into tests/Fixtures/Rulings/edge-rulings.json.
     *
     * @return list<array<string, mixed>>
     */
    protected function recordedRulings(): array
    {
        return self::$edgeRulings ??= json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/Rulings/edge-rulings.json'), true, flags: JSON_THROW_ON_ERROR)['rulings'];
    }

    /**
     * The ruling in the shape of edge-rulings.json; the first fixtures carry an ending's answer
     * and the cut heard, with the output in their id (end-<run>-<sermon|section id>).
     *
     * @param  array<string, mixed>  $fixture
     * @return array{section_id: int|null, edge: string, heard: float, expect: string, ruled_time: float|null, part: array{section_id: int}|null}
     */
    protected function ruling(array $fixture): array
    {
        $ruling = $fixture['ruling'];

        if (isset($ruling['expect'])) {
            return [
                'section_id' => $ruling['output'] === 'sermon' ? null : (int) $ruling['section_id'],
                'edge' => $ruling['edge'],
                'heard' => (float) $ruling['heard'],
                'expect' => $ruling['expect'],
                'ruled_time' => isset($ruling['ruled_time']) ? (float) $ruling['ruled_time'] : null,
                'part' => isset($ruling['part']) ? ['section_id' => (int) $ruling['part']['section_id']] : null,
            ];
        }

        [, , $which] = explode('-', $fixture['id'], 3);

        return [
            'section_id' => $which === 'sermon' ? null : (int) $which,
            'edge' => 'end',
            'heard' => (float) $ruling['ruled_cut'],
            'expect' => ['right' => 'keep', 'clips_word' => 'outward'][$ruling['answer']],
            'ruled_time' => null,
            'part' => null,
        ];
    }

    /**
     * The fixture without its recorded sentence-check answers, so the question is asked again. These tests
     * check the question; the refreshed capture (10-27) carries the live run's own answer to it.
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    protected function withoutSentenceAnswers(array $fixture): array
    {
        $isAnswer = static fn (array $entry): bool => str_contains($entry['path'] ?? '', '.edge-sentence-') || str_starts_with($entry['kind'] ?? '', 'edge-sentence-');
        $metadata = is_string($fixture['run']['processing_metadata']) ? json_decode($fixture['run']['processing_metadata'], true, flags: JSON_THROW_ON_ERROR) : $fixture['run']['processing_metadata'];
        $metadata['service_artifacts'] = array_values(array_filter($metadata['service_artifacts'] ?? [], static fn (array $entry): bool => ! $isAnswer($entry)));
        $fixture['run']['processing_metadata'] = is_string($fixture['run']['processing_metadata']) ? json_encode($metadata, JSON_THROW_ON_ERROR) : $metadata;
        $fixture['files'] = array_values(array_filter($fixture['files'], static fn (array $file): bool => ! $isAnswer($file)));

        return $fixture;
    }

    /** @param  array<string, mixed>  $fixture */
    protected function rebuild(array $fixture): MediaProcessingLog
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

        Storage::disk($fixture['rms']['disk'])->put($fixture['run']['rms_log_path'], $fixture['rms']['raw_content'] ?? $this->rmsLog($fixture['rms']['samples']));

        $run = $fixture['run'];
        $source = $run['source_identity'] ?? null;
        $log = MediaProcessingLog::factory()->livestream()->make();
        $log->forceFill([
            'id' => $run['id'],
            'processing_id' => $run['processing_id'],
            ...($source === null ? [] : ['source_file_path' => $source['path'], 'file_size' => $source['size'], 'file_hash' => $source['sha256']]),
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

        $this->restoreServiceSongs($log, $fixture['service_songs'] ?? null);

        return $log->fresh() ?? $log;
    }

    /**
     * The service's songs as the capture found them: catalogue titles and the order-of-service items
     * that tie sections to them. Captures before 10-29 hold none, and their runs replay without a service.
     *
     * @param  array{songs: list<array{id: int, title: string|null, alternate_title: string|null}>, items: list<array{song_id: int, section_ids: list<int>}>}|null  $serviceSongs
     */
    protected function restoreServiceSongs(MediaProcessingLog $log, ?array $serviceSongs): void
    {
        if ($serviceSongs === null || $serviceSongs['items'] === []) {
            return;
        }
        $service = ChurchService::factory()->create();
        $log->forceFill(['church_service_id' => $service->id])->save();
        foreach ($serviceSongs['songs'] as $song) {
            Song::factory()->create($song);
        }
        foreach ($serviceSongs['items'] as $item) {
            $itemId = ChurchServiceItem::factory()->create(['church_service_id' => $service->id, 'song_id' => $item['song_id']])->id;
            DB::table('service_sections')->whereIn('id', $item['section_ids'])->update(['church_service_item_id' => $itemId]);
        }
    }

    /**
     * The edge step's K16 answer, replayed from the model's recorded answers to the recorded
     * question ({@see SermonSongIntroductionCheck}): the question rebuilt from the fixture must be
     * the one the model answered.
     *
     * @param  array{prompt: string, calls: list<array<string, mixed>>, edge_words?: list<array{time: float, words: list<array{start: float, end: float, word: string}>}>}|null  $recorded
     */
    protected function replaySongIntroduction(MediaProcessingLog $log, ?array $recorded): void
    {
        if ($recorded === null) {
            return;
        }

        $selected = $log->processing_metadata?->raw['sermon_composition']['selected_section_ids'] ?? [];
        $spans = array_values($log->serviceSections()->whereIn('id', $selected)->orderBy('start_time')->get()
            ->map(static fn (ServiceSection $section): array => ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time])->all());
        // Fresh captures already contain the current bound answer; replay only older captures.
        if (app(SermonSongIntroductionCheck::class)->read($log, $spans) !== null) {
            return;
        }

        OpenAI::fake(array_map(static fn (array $call): CreateResponse => CreateResponse::fake(['choices' => [['message' => [
            'content' => json_encode($call, JSON_THROW_ON_ERROR),
        ]]]]), $recorded['calls']));
        app(SermonSongIntroductionCheck::class)->prepare($log, $spans);

        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => $parameters['messages'][1]['content'] === $recorded['prompt']);

        // The words at the end the answer moves to, which the edge step decodes after asking.
        $evidence = app(OutputEdgeWordTimings::class);
        foreach ($recorded['edge_words'] ?? [] as $edge) {
            $window = $evidence->timingWindow($evidence->cues($log), (float) $edge['time'], $log->duration) ?? throw new \LogicException('No edge window at '.$edge['time']);
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
    protected function askedEdges(MediaProcessingLog $log, ?string $edge = null): array
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
    protected function rmsLog(array $samples): string
    {
        $lines = [];

        foreach ($samples as $index => [$time, $level]) {
            $lines[] = sprintf('frame:%d pts:0 pts_time:%s', $index, $time);
            $lines[] = 'lavfi.astats.Overall.RMS_level='.($level <= -999.0 ? '-inf' : $level);
        }

        return implode("\n", $lines)."\n";
    }
}
