<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureDrawExecutor;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleComposer;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleInput;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleRunner;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\ChurchService\Structure\SoundStage;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\ChurchService\Structure\ValidationResult;
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
    public function the_banked_input_names_the_rms_log_by_its_hash_instead_of_copying_it(): void
    {
        Config::set('media-processing.service_structure.detector', 'mock');
        $diskName = ServiceArtifactDisk::name();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $rmsPath = ServiceArtifactDisk::DURABLE_PREFIX.'rms-by-reference-'.$log->processing_id.'.log';
        $rms = "frame:0 pts:0 pts_time:0.0\nlavfi.astats.Overall.RMS_level=-25.0\n";
        Storage::disk($diskName)->put($rmsPath, $rms);
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
            'rms_log' => $rms,
            'source' => ['rms_log_path' => $rmsPath, 'rms_log_hash' => hash('sha256', $rms)],
        ];

        try {
            $result = app(ServiceStructureEnsembleRunner::class)->run($log, $input);
            $banked = json_decode((string) Storage::disk($diskName)->get($result['evidence']['input_path']), true, flags: JSON_THROW_ON_ERROR);

            $this->assertArrayNotHasKey('rms_log', $banked);
            $this->assertSame(hash('sha256', $rms), $banked['source']['rms_log_hash']);
            $this->assertSame($rms, ServiceStructureEnsembleInput::hydrate($banked)['rms_log']);
            $this->assertTrue(app(ServiceStructureEnsembleReplay::class)->replay($result['evidence'])['validation_passed']);

            Storage::disk($diskName)->put($rmsPath, $rms.'lavfi.astats.Overall.RMS_level=-60.0'."\n");

            try {
                app(ServiceStructureEnsembleReplay::class)->replay($result['evidence']);
                $this->fail('A banked input whose RMS log changed must not replay.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('RMS log', $exception->getMessage());
            }
        } finally {
            Storage::disk($diskName)->delete($rmsPath);

            foreach ($log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? [] as $evidence) {
                Storage::disk($diskName)->delete($evidence['input_path']);

                foreach ($evidence['slots'] as $slot) {
                    Storage::disk($diskName)->delete($slot['path']);
                }
            }
        }
    }

    #[Test]
    public function an_input_banked_with_its_rms_log_inside_still_replays_as_it_was(): void
    {
        $embedded = ['rms_log' => 'embedded', 'source' => ['rms_log_path' => 'gone.log', 'rms_log_hash' => 'irrelevant']];
        $none = ['source' => ['rms_log_path' => null, 'rms_log_hash' => null]];

        $this->assertSame('embedded', ServiceStructureEnsembleInput::hydrate($embedded)['rms_log']);
        $this->assertNull(ServiceStructureEnsembleInput::hydrate($none)['rms_log']);
        $this->assertSame($none + ['rms_log' => null], ServiceStructureEnsembleInput::hydrate(ServiceStructureEnsembleInput::forStorage($none + ['rms_log' => null])));
    }

    /**
     * A draw that never answered leaves three voters, and three split two to one where four decide
     * three to one; canary 15's run 1005 lost all four to a transient provider error.
     */
    #[Test]
    public function a_lost_draw_is_retried_once_and_its_first_attempt_is_kept(): void
    {
        Config::set('media-processing.service_structure.detector', 'openai');
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
        $runner = new class(app(ServiceStructureDrawExecutor::class), app(HistoricStagingContextRegistry::class)) extends ServiceStructureEnsembleRunner
        {
            protected function subprocessCommand(array $slot, array $evidence, ?string $encodedContext): array
            {
                if ($slot['slot'] === 0 && ! str_contains($slot['path'], '.retry')) {
                    return [PHP_BINARY, '-r', 'exit(9);'];
                }

                return parent::subprocessCommand($slot, $evidence, $encodedContext);
            }
        };

        try {
            $result = $runner->run($log, $input);

            $this->assertCount(4, $result['draws']);
            $this->assertSame(['valid', 'valid', 'valid', 'valid'], array_column($result['slots'], 'status'));
            $this->assertSame('interrupted', $result['evidence']['slots'][0]['first_attempt']['status']);
            $this->assertTrue(Storage::disk($diskName)->exists($result['evidence']['slots'][0]['first_attempt']['path']));
            $this->assertFalse(app(ServiceStructureEnsembleReplay::class)->replay($result['evidence'])['degraded']);
            $this->assertContains($result['evidence']['slots'][0]['path'], $this->ensembleArtifactPaths($log));
        } finally {
            foreach ($log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? [] as $evidence) {
                Storage::disk($diskName)->delete($evidence['input_path']);

                foreach ($evidence['slots'] as $slot) {
                    Storage::disk($diskName)->delete($slot['path']);
                    Storage::disk($diskName)->delete($slot['first_attempt']['path'] ?? '');
                }
            }
        }
    }

    /**
     * Full pass 2026-10-10, run 930: a draw killed at the deadline banks nothing, and the durable
     * artifact disk throws on a missing file rather than answering null, so the lost draw failed
     * the whole detection instead of being retried.
     */
    #[Test]
    public function a_draw_that_banked_nothing_is_retried_on_a_disk_that_throws_on_missing_files(): void
    {
        Config::set('media-processing.service_structure.detector', 'openai');
        Config::set('media-processing.storage.service_artifact_disk', 'service_artifacts');
        $this->assertTrue((bool) config('filesystems.disks.service_artifacts.throw'));
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
        $runner = new class(app(ServiceStructureDrawExecutor::class), app(HistoricStagingContextRegistry::class)) extends ServiceStructureEnsembleRunner
        {
            protected function subprocessCommand(array $slot, array $evidence, ?string $encodedContext): array
            {
                if ($slot['slot'] === 0 && ! str_contains($slot['path'], '.retry')) {
                    return [PHP_BINARY, '-r', 'exit(9);'];
                }

                return parent::subprocessCommand($slot, $evidence, $encodedContext);
            }
        };

        try {
            $result = $runner->run($log, $input);

            $this->assertSame('interrupted', $result['evidence']['slots'][0]['first_attempt']['status']);
            $this->assertStringEndsWith('.slot-0.retry-1.json', $result['evidence']['slots'][0]['path']);
            $this->assertTrue(Storage::disk($diskName)->exists($result['evidence']['slots'][0]['first_attempt']['path']));
        } finally {
            $disk = Storage::disk($diskName);

            foreach ($log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? [] as $evidence) {
                $paths = [$evidence['input_path'], ...array_column($evidence['slots'], 'path'), ...array_column(array_column($evidence['slots'], 'first_attempt'), 'path')];

                foreach (array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== '' && $disk->exists($path)) as $path) {
                    $disk->delete($path);
                }
            }
        }
    }

    /**
     * A retry that answers but fails validation is still an answer: replay revalidates invalid
     * first-attempt draws after a deterministic rule fix, so it must be able to revalidate this one.
     */
    #[Test]
    public function a_retried_draw_that_fails_validation_is_kept_with_its_first_attempt(): void
    {
        Config::set('media-processing.service_structure.detector', 'mock');
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
        $executor = new class(app(ServiceStructureInterface::class), app(SilenceSnapService::class), app(SoundStage::class), app(ServiceStructureValidator::class)) extends ServiceStructureDrawExecutor
        {
            private int $calls = 0;

            public function execute(array $input, string $model): array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new RuntimeException('cURL error 52: Empty reply from server');
                }

                $draw = parent::execute($input, $model);

                if ($this->calls === 5) {
                    $draw['validation'] = new ValidationResult($draw['validation']->structure, [['code' => 'zero_length_section', 'message' => 'Rule since fixed.']]);
                }

                return $draw;
            }
        };
        $runner = new ServiceStructureEnsembleRunner($executor, app(HistoricStagingContextRegistry::class));

        try {
            $result = $runner->run($log, $input);
            $slot = $result['evidence']['slots'][0];

            $this->assertStringEndsWith('.slot-0.retry-1.json', $slot['path']);
            $this->assertSame('unavailable', $slot['first_attempt']['status']);
            $this->assertSame(['invalid', 'valid', 'valid', 'valid'], array_column($result['slots'], 'status'));
            $this->assertCount(4, $result['draws']);
            $this->assertContains($slot['path'], $this->ensembleArtifactPaths($log));
            $this->assertContains($slot['first_attempt']['path'], $this->ensembleArtifactPaths($log));
            $this->assertFalse(app(ServiceStructureEnsembleReplay::class)->replay($result['evidence'])['degraded']);
        } finally {
            foreach ($log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? [] as $evidence) {
                Storage::disk($diskName)->delete($evidence['input_path']);

                foreach ($evidence['slots'] as $slot) {
                    Storage::disk($diskName)->delete($slot['path']);
                    Storage::disk($diskName)->delete($slot['first_attempt']['path'] ?? '');
                }
            }
        }
    }

    /** @return list<string> */
    private function ensembleArtifactPaths(MediaProcessingLog $log): array
    {
        $artifacts = $log->fresh()?->processing_metadata?->toArray()['service_artifacts'] ?? [];

        return array_column(array_filter($artifacts, static fn (array $artifact): bool => $artifact['kind'] === ServiceStructureEnsembleRunner::ARTIFACT_KIND), 'path');
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
                    Storage::disk($diskName)->delete($slot['first_attempt']['path'] ?? '');
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
