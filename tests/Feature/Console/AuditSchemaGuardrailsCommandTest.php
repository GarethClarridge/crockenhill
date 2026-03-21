<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Support\SchemaGuardrailAudit;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditSchemaGuardrailsCommandTest extends TestCase
{
    #[Test]
    public function it_returns_success_for_a_clean_audit_report_without_requerying(): void
    {
        $report = [
            'speaker_profile_duplicates' => [],
            'speaker_sample_duplicates' => [],
            'orphaned_media_processing_logs' => 0,
            'invalid_service_section_statuses' => [],
            'invalid_service_section_publication_statuses' => [],
            'legacy_livestream_processing_log_rows' => 0,
        ];

        $this->mock(SchemaGuardrailAudit::class, function (MockInterface $mock) use ($report): void {
            $mock->shouldReceive('report')->once()->andReturn($report);
            $mock->shouldReceive('hasFailures')->once()->with($report)->andReturn(false);
        });

        $this->artisan('schema:audit-guardrails')
            ->expectsOutputToContain('Schema guardrail audit is clean.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_returns_failure_and_json_output_when_the_audit_report_has_blockers(): void
    {
        $report = [
            'speaker_profile_duplicates' => [[
                'preacher_id' => 7,
                'provider' => 'resemblyzer',
                'model_version' => 'v1.0',
                'duplicate_count' => 2,
            ]],
            'speaker_sample_duplicates' => [],
            'orphaned_media_processing_logs' => 1,
            'invalid_service_section_statuses' => [],
            'invalid_service_section_publication_statuses' => [],
            'legacy_livestream_processing_log_rows' => 3,
        ];

        $this->mock(SchemaGuardrailAudit::class, function (MockInterface $mock) use ($report): void {
            $mock->shouldReceive('report')->once()->andReturn($report);
            $mock->shouldReceive('hasFailures')->once()->with($report)->andReturn(true);
        });

        $this->artisan('schema:audit-guardrails --json')
            ->expectsOutputToContain('"speaker_profile_duplicates"')
            ->assertExitCode(1);
    }
}
