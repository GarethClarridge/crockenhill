<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
 *  - the ruling: an ending ruled right stays within 0.31 s of the cut the operator heard; one ruled
 *    to clip its last word stays later than that cut.
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
    #[DataProvider('fixtures')]
    public function a_ruled_ending_keeps_its_ruling(string $path): void
    {
        $fixture = json_decode((string) gzdecode((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
        $log = $this->rebuild($fixture);
        [, , $which] = explode('-', $fixture['id'], 3);
        $this->replaySongIntroduction($log, $fixture['song_introduction'] ?? null);

        $cut = $which === 'sermon'
            ? end(app(SermonExtractionPlanResolver::class)->resolve($log)['segments'])['end_time']
            : end(app(CueSafeExtractionPlan::class)->forSection(ServiceSection::query()->findOrFail((int) $which))['segments'])['end_time'];

        $this->assertEqualsWithDelta($fixture['live']['cut'], $cut, self::FIDELITY_TOLERANCE, 'the fixture no longer reproduces its live cut');

        $ruled = (float) $fixture['ruling']['ruled_cut'];

        match ($fixture['ruling']['answer']) {
            'right' => $this->assertEqualsWithDelta($ruled, $cut, self::RIGHT_TOLERANCE, 'an ending ruled right has moved'),
            'clips_word' => $this->assertGreaterThan($ruled + 0.05, $cut, 'an ending ruled to clip its last word is back at the clipped cut'),
        };
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
