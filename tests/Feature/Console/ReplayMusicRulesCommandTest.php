<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class ReplayMusicRulesCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    /**
     * §8 replays current structure: a superseded run's structure was replaced by its successor's,
     * and the timeline backfill left superseded runs out (943, 1189, 1249, 1377, 1378).
     */
    #[Test]
    public function named_runs_obey_the_same_eligibility_as_all(): void
    {
        $eligible = $this->historicRun();
        $excluded = $this->historicRun(['exclusion' => ['reason' => MediaProcessingLog::EXCLUSION_REASON_REHEARSAL_DUPLICATE]]);
        $failed = $this->historicRun(attributes: ['status' => 'failed']);
        $superseded = $this->historicRun(attributes: ['superseded_at' => now()]);

        $named = $this->report(['--run' => [$eligible->id, $excluded->id, $failed->id, $superseded->id]]);
        $all = $this->report(['--all' => true]);

        self::assertSame(1, $named['population']);
        self::assertSame([$excluded->id, $failed->id, $superseded->id], $named['skipped_ineligible_run_ids']);
        self::assertSame(1, $all['population']);
    }

    /** A run with nothing banked is named as unassessable, never counted as a run with no actions. */
    #[Test]
    public function it_names_each_unassessable_run_and_what_it_lacks(): void
    {
        $run = $this->historicRun();

        $this->artisan('structure:replay-music-rules', ['--run' => [$run->id]])
            ->expectsOutputToContain('0 runs assessed, 1 unassessable')
            ->expectsOutputToContain("Run {$run->id} is unassessable: no readable service_structure, rms_log, audio_timeline")
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_to_run_without_a_population(): void
    {
        $this->artisan('structure:replay-music-rules')->assertFailed();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function report(array $options): array
    {
        self::assertSame(0, Artisan::call('structure:replay-music-rules', [...$options, '--json' => true]));

        /** @var array<string, mixed> $report */
        $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attributes
     */
    private function historicRun(array $metadata = [], array $attributes = []): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $this->createHistoricImportOperation()->id,
            'processing_metadata' => $metadata,
            'audio_timeline_path' => null,
            ...$attributes,
        ]);
    }
}
