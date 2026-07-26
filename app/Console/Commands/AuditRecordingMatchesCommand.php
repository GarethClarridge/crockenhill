<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RecordingMatchAudit;
use Illuminate\Console\Command;

/**
 * Recurring read-only diagnostic for recording-to-service matching risks.
 */
class AuditRecordingMatchesCommand extends Command
{
    protected $signature = 'audit:recording-matches
        {--json : Emit the full audit report as JSON}';

    protected $description = 'Audit livestream recordings that may match church services unexpectedly';

    public function handle(RecordingMatchAudit $audit): int
    {
        $report = $audit->report();
        $hasFindings = $audit->hasFindings($report);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $hasFindings ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['Audit', 'Findings'],
            [
                ['Livestream runs scanned', (string) $report['summary']['scanned_runs']],
                ['Latent matches', (string) $report['summary']['latent_matches']],
                ['Identity-only matches', (string) $report['summary']['identity_only_matches']],
                ['Identity collision groups', (string) $report['summary']['identity_collision_groups']],
                ['Duplicate hash groups', (string) $report['summary']['duplicate_hash_groups']],
                ['Suspicious runs', (string) $report['summary']['suspicious_runs']],
                ['Link mismatches', (string) $report['summary']['link_mismatches']],
                ['Superseded but attachable', (string) $report['summary']['superseded_attachable_runs']],
            ],
        );

        $this->renderRuns('Latent matches', $report['latent_matches']);
        $this->renderRuns('Identity-only matches', $report['identity_only_matches']);
        $this->renderGroups('Identity collisions', $report['identity_collisions']);
        $this->renderGroups('Duplicate file hashes', $report['duplicate_hashes']);
        $this->renderRuns('Suspicious runs', $report['suspicious_runs']);
        $this->renderRuns('Linked identity mismatches', $report['link_mismatches']);
        $this->renderRuns('Superseded but still attachable', $report['superseded_attachable_runs']);

        if (! $hasFindings) {
            $this->info('Recording match audit is clean.');

            return self::SUCCESS;
        }

        $this->error('Recording match audit found records that require review.');

        return self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function renderRuns(string $title, array $runs): void
    {
        if ($runs === []) {
            return;
        }

        $this->newLine();
        $this->line($title);
        $this->table(
            ['ID', 'Status', 'Filename', 'Identity', 'Linked service', 'Duration', 'Sections', 'Sermons', 'Signals'],
            array_map(fn (array $run): array => $this->runRow($run), $runs),
        );
    }

    /**
     * @param  list<array{key: string, runs: list<array<string, mixed>>}>  $groups
     */
    private function renderGroups(string $title, array $groups): void
    {
        if ($groups === []) {
            return;
        }

        $this->newLine();
        $this->line($title);
        $this->table(
            ['Group', 'Run IDs', 'Filenames'],
            array_map(
                fn (array $group): array => [
                    $group['key'],
                    implode(', ', array_column($group['runs'], 'id')),
                    implode(', ', array_unique(array_column($group['runs'], 'filename'))),
                ],
                $groups,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $run
     * @return list<float|int|string>
     */
    private function runRow(array $run): array
    {
        $linkedServiceId = $run['linked_service_id'];
        $signals = $run['signals'];

        return [
            (int) $run['id'],
            (string) $run['status'],
            (string) $run['filename'],
            (string) $run['identity'],
            is_int($linkedServiceId) ? $linkedServiceId : '—',
            is_numeric($run['duration_seconds']) ? round((float) $run['duration_seconds'], 1) : '—',
            (int) $run['section_count'],
            (int) $run['owned_sermon_count'],
            is_array($signals) ? implode(', ', $signals) : '',
        ];
    }
}
