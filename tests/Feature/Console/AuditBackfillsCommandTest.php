<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Support\BackfillAudit;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditBackfillsCommandTest extends TestCase
{
    #[Test]
    public function it_returns_success_for_a_clean_audit_report_without_requerying(): void
    {
        $report = [
            'sermons_missing_scripture_filters' => 0,
            'songs_missing_praise_numbers' => 0,
            'preachers_table_empty' => false,
            'song_link_drift' => 0,
            'media_logs_missing_extracted_identity' => 0,
            'songs_catalogue_missing' => false,
            'speaker_profiles_missing' => false,
            'sermons_missing_preacher' => 0,
            'sermons_missing_scripture_passage' => 0,
        ];

        $this->mock(BackfillAudit::class, function (MockInterface $mock) use ($report): void {
            $mock->shouldReceive('report')->once()->andReturn($report);
            $mock->shouldReceive('hasFailures')->once()->with($report)->andReturn(false);
        });

        $this->artisan('backfill:audit')
            ->expectsOutputToContain('Backfill audit is clean.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_returns_failure_and_json_output_when_backfills_were_missed(): void
    {
        $report = [
            'sermons_missing_scripture_filters' => 42,
            'songs_missing_praise_numbers' => 0,
            'preachers_table_empty' => false,
            'song_link_drift' => 3,
            'media_logs_missing_extracted_identity' => 0,
            'songs_catalogue_missing' => false,
            'speaker_profiles_missing' => true,
            'sermons_missing_preacher' => 5,
            'sermons_missing_scripture_passage' => 120,
        ];

        $this->mock(BackfillAudit::class, function (MockInterface $mock) use ($report): void {
            $mock->shouldReceive('report')->once()->andReturn($report);
            $mock->shouldReceive('hasFailures')->once()->with($report)->andReturn(true);
        });

        $this->artisan('backfill:audit --json')
            ->expectsOutputToContain('"sermons_missing_scripture_filters"')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_lists_the_remedy_command_for_each_failing_check(): void
    {
        $report = [
            'sermons_missing_scripture_filters' => 42,
            'songs_missing_praise_numbers' => 0,
            'preachers_table_empty' => false,
            'song_link_drift' => 0,
            'media_logs_missing_extracted_identity' => 0,
            'songs_catalogue_missing' => false,
            'speaker_profiles_missing' => false,
            'sermons_missing_preacher' => 0,
            'sermons_missing_scripture_passage' => 0,
        ];

        $this->mock(BackfillAudit::class, function (MockInterface $mock) use ($report): void {
            $mock->shouldReceive('report')->once()->andReturn($report);
            $mock->shouldReceive('hasFailures')->once()->with($report)->andReturn(true);
        });

        $this->artisan('backfill:audit')
            ->expectsOutputToContain('sermons:sync-scripture-filters --only-missing')
            ->expectsOutputToContain('Backfill audit found missed runs.')
            ->assertExitCode(1);
    }
}
