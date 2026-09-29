<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureDrawExecutor;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleComposer;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRunner;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Support\ServiceArtifactDisk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class ServiceStructureEnsembleRunnerTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function banked_evidence_is_private_even_on_a_publicly_visible_disk(): void
    {
        Storage::fake('public');
        Config::set('media-processing.storage.service_artifact_disk', 'public');
        Config::set('media-processing.service_structure.detector', 'mock');
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 100.0, 'text' => 'Welcome and Bible reading.'],
            ['start' => 100.0, 'end' => 500.0, 'text' => 'Turn with me to John.'],
        ], 500.0, ChurchServiceTranscript::SOURCE_MOCK);

        $result = app(ServiceStructureEnsembleRunner::class)->run($log, [
            'processing_id' => $log->processing_id,
            'transcript' => $transcript->toArray(),
            'audio_timeline' => AudioTimelineFixture::payload([], 500.0),
            'oos_items' => [],
            'validation_context' => ServiceStructureDrawExecutor::contextSnapshot(ValidationContext::for($transcript)),
            'rms_log' => null,
        ]);

        $this->assertSame('private', Storage::disk('public')->getVisibility($result['evidence']['input_path']));

        foreach ($result['evidence']['slots'] as $slot) {
            $this->assertSame('private', Storage::disk('public')->getVisibility($slot['path']));
        }
    }

    #[Test]
    public function a_real_timed_out_or_abrupt_child_keeps_three_sibling_votes_and_attempt_history(): void
    {
        Config::set('media-processing.service_structure.detector', 'openai');
        Config::set('media-processing.service_structure.ensemble.draw_timeout_seconds', 5);
        $diskName = ServiceArtifactDisk::name();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 100.0, 'text' => 'Welcome and Bible reading.'],
            ['start' => 100.0, 'end' => 500.0, 'text' => 'Turn with me to John.'],
        ], 500.0, ChurchServiceTranscript::SOURCE_MOCK);
        $input = [
            'processing_id' => $log->processing_id,
            'transcript' => $transcript->toArray(),
            'audio_timeline' => AudioTimelineFixture::payload([], 500.0),
            'oos_items' => [],
            'validation_context' => ServiceStructureDrawExecutor::contextSnapshot(ValidationContext::for($transcript)),
            'rms_log' => null,
        ];

        try {
            foreach (['timeout', 'abrupt'] as $failure) {
                $runner = new class(app(ServiceStructureDrawExecutor::class), app(HistoricStagingContextRegistry::class), $failure) extends ServiceStructureEnsembleRunner
                {
                    public function __construct(
                        ServiceStructureDrawExecutor $executor,
                        HistoricStagingContextRegistry $registry,
                        private readonly string $failure,
                    ) {
                        parent::__construct($executor, $registry);
                    }

                    protected function subprocessCommand(array $slot, array $evidence, ?string $encodedContext): array
                    {
                        if ($slot['slot'] === 0) {
                            return [PHP_BINARY, '-r', $this->failure === 'timeout' ? 'sleep(10);' : 'exit(9);'];
                        }

                        return parent::subprocessCommand($slot, $evidence, $encodedContext);
                    }
                };

                $result = $runner->run($log, $input);
                $this->assertSame('interrupted', $result['slots'][0]['status']);
                $this->assertCount(3, $result['draws']);
                $this->assertSame(['valid', 'valid', 'valid'], array_column(array_slice($result['slots'], 1), 'status'));

                $first = json_decode((string) Storage::disk($diskName)->get($result['slots'][0]['path']), true);
                $this->assertSame('unknown', $first['usage']);
                $replayed = app(ServiceStructureEnsembleReplay::class)->replay($result['evidence']);
                $this->assertTrue($replayed['degraded']);
                $this->assertSame('interrupted', $replayed['slots'][0]['status_after']);

                if ($failure === 'abrupt') {
                    $this->assertSame(9, $first['exit_code']);
                }
            }

            $this->assertCount(2, $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? []);
        } finally {
            foreach ($log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? [] as $evidence) {
                Storage::disk($diskName)->delete($evidence['input_path']);

                foreach ($evidence['slots'] as $slot) {
                    Storage::disk($diskName)->delete($slot['path']);
                }
            }
        }
    }

    #[Test]
    public function four_real_subprocesses_bank_separate_results_against_one_snapshot(): void
    {
        Config::set('media-processing.service_structure.detector', 'openai');
        $artifactDisk = ServiceArtifactDisk::name();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 60.0, 'text' => 'Welcome to church.'],
            ['start' => 60.0, 'end' => 100.0, 'text' => 'Our reading is from John.'],
            ['start' => 100.0, 'end' => 500.0, 'text' => 'Turn with me to John.'],
            ['start' => 500.0, 'end' => 600.0, 'text' => 'Let us sing a hymn.'],
        ], 600.0, ChurchServiceTranscript::SOURCE_MOCK);
        $input = [
            'processing_id' => $log->processing_id,
            'transcript' => $transcript->toArray(),
            'audio_timeline' => AudioTimelineFixture::payload([], 600.0),
            'oos_items' => [],
            'validation_context' => ServiceStructureDrawExecutor::contextSnapshot(ValidationContext::for($transcript)),
            'rms_log' => null,
        ];

        $runner = new ServiceStructureEnsembleRunner(
            app(ServiceStructureDrawExecutor::class),
            app(HistoricStagingContextRegistry::class),
        );

        try {
            $result = $runner->run($log, $input);

            $this->assertCount(4, $result['slots']);
            $this->assertCount(4, $result['draws'], json_encode(array_map(
                static fn (array $slot): mixed => json_decode((string) Storage::disk($artifactDisk)->get($slot['path']), true),
                $result['slots'],
            )));
            $this->assertSame(1, count(array_unique(array_column($result['slots'], 'status'))));
            $this->assertSame('valid', $result['slots'][0]['status']);
            $this->assertSame(hash('sha256', (string) Storage::disk($artifactDisk)->get($result['evidence']['input_path'])), $result['evidence']['input_hash']);
            $this->assertCount(4, $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'][0]['outcomes'] ?? []);

            $replayed = app(ServiceStructureEnsembleReplay::class)->replay($result['evidence']);
            $this->assertSame($replayed, app(ServiceStructureEnsembleReplay::class)->replay($result['evidence']));
            $expected = app(ServiceStructureEnsembleComposer::class)->compose($result['draws']);
            $this->assertSame($expected->structure->toArray(), $replayed['structure']);
            $this->assertTrue($replayed['validation_passed']);
            $this->assertCount(4, $replayed['slots']);

            $reportPath = storage_path('scratch/ensemble-replay-test-'.$log->id.'.json');
            $before = $log->fresh()?->getAttributes();
            $this->assertSame(0, Artisan::call('structure:ensemble-replay', [
                'processingId' => $log->processing_id,
                '--report' => $reportPath,
            ]));
            $this->assertSame($before, $log->fresh()?->getAttributes());
            $this->assertEquals($expected->structure->toArray(), json_decode((string) file_get_contents($reportPath), true)['structure']);
            unlink($reportPath);

            $slotPath = $result['evidence']['slots'][0]['path'];
            $originalSlot = Storage::disk($artifactDisk)->get($slotPath);
            Storage::disk($artifactDisk)->put($slotPath, $originalSlot.' ');

            try {
                app(ServiceStructureEnsembleReplay::class)->replay($result['evidence']);
                $this->fail('Changed draw evidence must not replay.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('missing or changed', $exception->getMessage());
            } finally {
                Storage::disk($artifactDisk)->put($slotPath, $originalSlot);
            }

            $bankedInput = json_decode((string) Storage::disk($artifactDisk)->get($result['evidence']['input_path']), true, flags: JSON_THROW_ON_ERROR);
            $bankedInput['evidence_version']['rule_version'] = 'changed-after-banking';
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Ensemble prompt, schema, policy or code changed');
            app(ServiceStructureDrawExecutor::class)->execute($bankedInput, 'gpt-5.6-luna');
        } finally {
            $evidence = $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? [];

            foreach ($evidence['slots'] ?? [] as $slot) {
                Storage::disk($artifactDisk)->delete($slot['path']);
            }

            if (is_string($evidence['input_path'] ?? null)) {
                Storage::disk($artifactDisk)->delete($evidence['input_path']);
            }
        }
    }
}
