<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RecomputeSoundStageFlagsCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    /**
     * Run 1089, a rehearsal duplicate, was flagged by a named pass and left out by `--all`,
     * which read as a defect in the harness until its exclusion was found.
     */
    #[Test]
    public function named_runs_obey_the_same_eligibility_as_all(): void
    {
        $eligible = $this->historicRun();
        $excluded = $this->historicRun(['exclusion' => ['reason' => MediaProcessingLog::EXCLUSION_REASON_REHEARSAL_DUPLICATE]]);
        $failed = $this->historicRun(attributes: ['status' => 'failed']);

        $named = $this->report(['--run' => [$eligible->id, $excluded->id, $failed->id]]);
        $all = $this->report(['--all' => true]);

        self::assertSame(1, $named['population']);
        self::assertSame([$excluded->id, $failed->id], $named['skipped_ineligible_run_ids']);
        self::assertSame(1, $all['population']);
    }

    #[Test]
    public function it_names_the_runs_it_skipped(): void
    {
        $excluded = $this->historicRun(['exclusion' => ['reason' => MediaProcessingLog::EXCLUSION_REASON_REHEARSAL_DUPLICATE]]);

        $this->artisan('structure:recompute-sound-stage', ['--run' => [$excluded->id]])
            ->expectsOutputToContain("Skipped runs {$excluded->id}: excluded or not completed")
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function report(array $options): array
    {
        self::assertSame(0, Artisan::call('structure:recompute-sound-stage', [...$options, '--json' => true]));

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
            ...$attributes,
        ]);
    }
}
