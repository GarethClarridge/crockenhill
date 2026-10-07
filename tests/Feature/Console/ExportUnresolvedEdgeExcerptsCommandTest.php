<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PublicationPlanValidator;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Services\ChurchService\EdgeAnswerBindingTest;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * Codex review, 2026-10-06: an unresolved edge blocks its clip, so the operator must hear the
 * recording around it to decide; an edge only missing decoded words is the edge step's to fix.
 */
class ExportUnresolvedEdgeExcerptsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Process::fake();
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $this->out = storage_path('framework/testing/unresolved-edges-'.getmypid());
        File::deleteDirectory($this->out);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);

        parent::tearDown();
    }

    #[Test]
    public function unresolved_edges_get_source_excerpts_and_missing_words_are_left_to_the_edge_step(): void
    {
        $log = $this->run1311();
        $before = $log->serviceSections()->get()->map->toArray()->all();

        $this->artisan('historic-import:unresolved-edge-excerpts', ['runs' => [$log->id], '--out' => $this->out])
            ->expectsOutputToContain('1 need an operator, 1 need the edge step.')
            ->assertSuccessful();

        $entries = json_decode((string) file_get_contents("{$this->out}/excerpts.json"), true);
        $decision = collect($entries)->firstWhere('kind', 'needs_operator');
        $this->assertSame(CueSafeExtractionPlan::AMBIGUOUS_CUE_ANCHOR, $decision['reason']);
        $this->assertSame('start', $decision['edge']);
        $this->assertSame('missing_edge_evidence', collect($entries)->firstWhere('kind', 'missing_edge_evidence')['kind']);
        Process::assertRan(fn ($process): bool => in_array("{$this->out}/{$decision['clips'][1]['src']}", $process->command, true)
            && in_array((string) ($decision['time'] - 6.0), $process->command, true));
        $this->assertSame($before, $log->serviceSections()->get()->map->toArray()->all(), 'nothing is written');
    }

    /**
     * The answer path: an answer saved against an excerpt reaches the planner and clears exactly
     * that edge's block; another refused edge stays refused, and an answer given on evidence that
     * has since changed is not applied.
     */
    #[Test]
    public function an_answer_to_an_excerpt_clears_exactly_that_edges_block(): void
    {
        $log = $this->run1311();
        $operator = User::factory()->admin()->create();
        $this->artisan('historic-import:unresolved-edge-excerpts', ['runs' => [$log->id], '--out' => $this->out])->assertSuccessful();
        $entries = json_decode((string) file_get_contents("{$this->out}/excerpts.json"), true);
        $decision = collect($entries)->firstWhere('kind', 'needs_operator');
        $talk = ServiceSection::query()->where('media_processing_log_id', $log->id)->orderBy('start_time')->firstOrFail();
        $plans = app(CueSafeExtractionPlan::class);
        $validator = app(PublicationPlanValidator::class);
        $blocked = $plans->forSection($talk);
        $this->assertSame('edge_unresolved', $validator->validate($log, [$talk], $blocked['segments'], $blocked['cue_edge_widening'])[0]['kind']);
        $answers = "{$this->out}/answers.json";
        file_put_contents($answers, json_encode([['id' => $decision['id'], 'choice' => 'cut_at', 'time' => 4139.4, 'note' => 'The talk opens with the first thank you.']]));

        $this->artisan('historic-import:unresolved-edge-answers', ['export' => $this->out, 'answers' => $answers, '--operator' => (string) $operator->id])
            ->expectsOutputToContain('1 ready')
            ->assertSuccessful();
        $this->assertNull($log->fresh()->processing_metadata?->raw[CueSafeExtractionPlan::EDGE_ANSWERS_KEY] ?? null, 'a dry run writes nothing');
        $this->artisan('historic-import:unresolved-edge-answers', ['export' => $this->out, 'answers' => $answers, '--operator' => (string) $operator->id, '--execute' => true])
            ->expectsOutputToContain('1 applied')
            ->assertSuccessful();

        $planned = $plans->forSection($talk->fresh());
        $this->assertSame(4139.4, $planned['segments'][0]['start_time']);
        $this->assertSame([], $validator->validate($log->fresh(), [$talk], $planned['segments'], $planned['cue_edge_widening']));
        $audit = collect($planned['cue_edge_widening'])->firstWhere('reason', CueSafeExtractionPlan::OPERATOR_ANSWERED_EDGE);
        $this->assertSame(CueSafeExtractionPlan::AMBIGUOUS_CUE_ANCHOR, $audit['unresolved_reason']);
        $this->assertSame($operator->id, $audit['answer']['operator_id']);

        $this->artisan('historic-import:unresolved-edge-excerpts', ['runs' => [$log->id], '--out' => $this->out.'-after'])
            ->expectsOutputToContain('0 need an operator, 1 need the edge step.')
            ->assertSuccessful();
        File::deleteDirectory($this->out.'-after');

        // The talk's second line re-transcribed at the same times: the cut and its timestamps are
        // unchanged, but the operator did not hear this evidence, so the block returns.
        $cues = $this->cues();
        $cues[1]['text'] = 'Here in the death of Christ I live.';
        Storage::disk('local')->put('temp/edges.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $stale = $plans->forSection($talk->fresh());
        $this->assertSame([['span_index' => 0, 'edge' => 'start', 'original_time' => 4140.0]], CueSafeExtractionPlan::unresolvedEdges($stale['cue_edge_widening']));
        $this->assertSame($decision['time'], round($stale['cue_edge_widening'][0]['time'], 2), 'the proposed cut did not move');
    }

    /** The recorded answer keeps the key the excerpt was asked on, which names its output ({@see EdgeAnswerBindingTest}). */
    #[Test]
    public function an_answer_settles_only_the_output_it_was_given_for(): void
    {
        $log = $this->run1311();
        $operator = User::factory()->admin()->create();
        $this->artisan('historic-import:unresolved-edge-excerpts', ['runs' => [$log->id], '--out' => $this->out])->assertSuccessful();
        $decision = collect(json_decode((string) file_get_contents("{$this->out}/excerpts.json"), true))->firstWhere('kind', 'needs_operator');
        $answers = "{$this->out}/answers.json";
        file_put_contents($answers, json_encode([['id' => $decision['id'], 'choice' => 'right']]));
        $this->artisan('historic-import:unresolved-edge-answers', ['export' => $this->out, 'answers' => $answers, '--operator' => (string) $operator->id, '--execute' => true])
            ->expectsOutputToContain('1 applied')
            ->assertSuccessful();

        $recorded = $log->fresh()->processing_metadata?->raw[CueSafeExtractionPlan::EDGE_ANSWERS_KEY][0];
        $this->assertSame($decision['key'], $recorded['key']);
        $this->assertStringContainsString('|'.$this->plannedOutput($log).'|', $recorded['key']);
    }

    /** "Can't tell" records nothing, and an id no longer refused is reported, not applied. */
    #[Test]
    public function deferred_and_stale_answers_are_not_recorded(): void
    {
        $log = $this->run1311();
        $operator = User::factory()->admin()->create();
        $this->artisan('historic-import:unresolved-edge-excerpts', ['runs' => [$log->id], '--out' => $this->out])->assertSuccessful();
        $id = collect(json_decode((string) file_get_contents("{$this->out}/excerpts.json"), true))->firstWhere('kind', 'needs_operator')['id'];
        $answers = "{$this->out}/answers.json";
        file_put_contents($answers, json_encode([['id' => $id, 'choice' => 'defer'], ['id' => 'not-an-edge', 'choice' => 'right']]));

        $this->artisan('historic-import:unresolved-edge-answers', ['export' => $this->out, 'answers' => $answers, '--operator' => (string) $operator->id, '--execute' => true])
            ->expectsOutputToContain('0 applied')
            ->assertSuccessful();

        $this->assertSame([], $log->fresh()->processing_metadata?->raw[CueSafeExtractionPlan::EDGE_ANSWERS_KEY] ?? []);
    }

    /** @return list<array{start: float, end: float, text: string}> */
    private function cues(): array
    {
        return [
            ['start' => 4140.0, 'end' => 4141.0, 'text' => 'thank you'],
            ['start' => 4141.0, 'end' => 4170.0, 'text' => 'Here in the power of Christ I stand.'],
            ['start' => 4590.0, 'end' => 4600.0, 'text' => 'And sing the final verse.'],
        ];
    }

    /** The output identity of the run's talk, as its plan records it. */
    private function plannedOutput(MediaProcessingLog $log): string
    {
        $talk = ServiceSection::query()->where('media_processing_log_id', $log->id)->orderBy('start_time')->firstOrFail();

        return (string) collect(app(CueSafeExtractionPlan::class)->forSection($talk)['cue_edge_widening'])->firstWhere('output', '!=', null)['output'];
    }

    /**
     * A talk whose opening "thank you" is heard twice within reach (F03), so its start is refused,
     * plus a song whose end words were never decoded. Song ends at speech fade since 2026-10-07,
     * so a song end is no longer the refused edge here.
     */
    private function run1311(): MediaProcessingLog
    {
        $cues = $this->cues();
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000, 'rms_log_path' => 'service-transcripts/edges.rms.log',
            'audio_timeline_path' => 'service-transcripts/edges.classes.json']);
        $log->putServiceTranscriptPath('temp/edges.json');
        Storage::disk('local')->put('temp/edges.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        Storage::disk('local')->put('service-transcripts/edges.classes.json', AudioTimelineFixture::json([[3900, 4145, 0.7, 0.02], [4145, 4180, 0.21, 0.68]], 5000.0));
        $lines = [];
        for ($tenth = 41000; $tenth < 41800; $tenth++) {
            $time = $tenth / 10;
            $level = $time >= 4145.8 && $time < 4146.6 && ($time < 4146.1 || $time >= 4146.15) ? -90.0 : -25.0;
            $lines[] = sprintf("frame:%d pts:%d pts_time:%.1f\nlavfi.astats.Overall.RMS_level=%.1f", $tenth, $tenth * 800, $time, $level);
        }
        Storage::disk('local')->put('service-transcripts/edges.rms.log', implode("\n", $lines)."\n");
        Storage::disk('local')->put('service-audio/edges.mp3', 'audio');
        $log->writeProcessingMetadata(static function (array $metadata): array {
            $metadata[ServiceArtifactStorage::METADATA_KEY][] = ['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/edges.mp3', 'recorded_at' => now()->toIso8601String()];

            return $metadata;
        });
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::ShortTalk, 'start_time' => 4140.0, 'end_time' => 4170.0]);
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song, 'start_time' => 4400.0, 'end_time' => 4600.0]);
        $log = $log->fresh();
        $evidence = app(OutputEdgeWordTimings::class);
        $opening = [
            ['start' => 4139.5, 'end' => 4139.7, 'word' => ' thank'], ['start' => 4139.7, 'end' => 4139.9, 'word' => ' you'],
            ['start' => 4140.1, 'end' => 4140.3, 'word' => ' thank'], ['start' => 4140.3, 'end' => 4140.5, 'word' => ' you'],
            ['start' => 4141.6, 'end' => 4141.9, 'word' => ' so'],
        ];
        foreach ([[4140.0, $opening], [4170.0, [['start' => 4168.0, 'end' => 4169.5, 'word' => ' stand.']]]] as [$edge, $words]) {
            $window = $evidence->window($evidence->cues($log), $edge, 5000.0);
            app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
                'identity' => $evidence->identity($log, $window), 'words' => $words, 'compute_seconds' => 1.0,
            ]);
        }

        return $log->fresh();
    }
}
