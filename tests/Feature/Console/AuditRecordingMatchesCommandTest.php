<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Support\RecordingMatchAudit;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditRecordingMatchesCommandTest extends TestCase
{
    #[Test]
    public function it_returns_success_for_a_clean_report(): void
    {
        $report = $this->report();

        $this->mock(RecordingMatchAudit::class, function (MockInterface $mock) use ($report): void {
            $mock->shouldReceive('report')->once()->andReturn($report);
            $mock->shouldReceive('hasFindings')->once()->with($report)->andReturn(false);
        });

        $this->artisan('audit:recording-matches')
            ->expectsOutputToContain('Recording match audit is clean.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_returns_failure_and_json_when_findings_exist(): void
    {
        $report = $this->report([
            'latent_matches' => [[
                'id' => 42,
                'processing_id' => 'run-42',
                'status' => 'completed',
                'filename' => 'random.mp4',
                'identity' => '2026-08-16 morning',
                'identity_service_id' => null,
                'linked_service_id' => null,
                'duration_seconds' => 30.0,
                'file_size' => 1_000,
                'file_hash' => 'hash',
                'section_count' => 0,
                'owned_sermon_count' => 0,
                'superseded' => false,
                'created_at' => '2026-07-26T12:00:00+00:00',
                'signals' => ['latent_match', 'short_duration', 'no_useful_outputs'],
            ]],
        ]);
        $report['summary']['latent_matches'] = 1;

        $this->mock(RecordingMatchAudit::class, function (MockInterface $mock) use ($report): void {
            $mock->shouldReceive('report')->once()->andReturn($report);
            $mock->shouldReceive('hasFindings')->once()->with($report)->andReturn(true);
        });

        $this->artisan('audit:recording-matches --json')
            ->expectsOutputToContain('"run-42"')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function report(array $overrides = []): array
    {
        return array_replace([
            'summary' => [
                'scanned_runs' => 0,
                'latent_matches' => 0,
                'identity_only_matches' => 0,
                'identity_collision_groups' => 0,
                'duplicate_hash_groups' => 0,
                'suspicious_runs' => 0,
                'link_mismatches' => 0,
                'superseded_attachable_runs' => 0,
            ],
            'latent_matches' => [],
            'identity_only_matches' => [],
            'identity_collisions' => [],
            'duplicate_hashes' => [],
            'suspicious_runs' => [],
            'link_mismatches' => [],
            'superseded_attachable_runs' => [],
        ], $overrides);
    }
}
