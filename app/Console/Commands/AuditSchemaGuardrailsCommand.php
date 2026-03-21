<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SchemaGuardrailAudit;
use Illuminate\Console\Command;

class AuditSchemaGuardrailsCommand extends Command
{
    protected $signature = 'schema:audit-guardrails {--json : Emit the full audit report as JSON}';

    protected $description = 'Audit schema guardrail prerequisites before tightening constraints';

    public function handle(SchemaGuardrailAudit $audit): int
    {
        $report = $audit->report();
        $hasFailures = $audit->hasFailures($report);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $hasFailures ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['Audit', 'Result'],
            [
                ['Speaker profile duplicate groups', (string) count($report['speaker_profile_duplicates'])],
                ['Speaker sample duplicate groups', (string) count($report['speaker_sample_duplicates'])],
                ['Orphaned media processing logs', (string) $report['orphaned_media_processing_logs']],
                ['Invalid service section statuses', (string) count($report['invalid_service_section_statuses'])],
                ['Invalid publication statuses', (string) count($report['invalid_service_section_publication_statuses'])],
                ['Legacy livestream_processing_logs rows', (string) $report['legacy_livestream_processing_log_rows']],
            ]
        );

        $this->renderDetailTable(
            'Speaker profile duplicates',
            $report['speaker_profile_duplicates'],
            ['preacher_id', 'provider', 'model_version', 'duplicate_count']
        );
        $this->renderDetailTable(
            'Speaker sample duplicates',
            $report['speaker_sample_duplicates'],
            ['speaker_profile_id', 'sermon_id', 'source', 'duplicate_count']
        );
        $this->renderDetailTable(
            'Invalid service section statuses',
            $report['invalid_service_section_statuses'],
            ['status', 'row_count']
        );
        $this->renderDetailTable(
            'Invalid publication statuses',
            $report['invalid_service_section_publication_statuses'],
            ['publication_status', 'row_count']
        );

        if ($hasFailures) {
            $this->error('Schema guardrail audit found blocking issues. Resolve them before applying tighter constraints.');

            return self::FAILURE;
        }

        $this->info('Schema guardrail audit is clean.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, int|string|null>>  $rows
     * @param  array<int, string>  $headers
     */
    private function renderDetailTable(string $title, array $rows, array $headers): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->line($title);
        $this->table($headers, $rows);
    }
}
