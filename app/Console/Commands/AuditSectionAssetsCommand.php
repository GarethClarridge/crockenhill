<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServiceSection;
use App\Support\MediaAssetPath;
use App\Support\SourceMediaPresence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only counterpart to audit:sermon-assets for the second asset population:
 * section-publication preview candidates written by
 * PrepareSectionPublicationCandidates.
 *
 * Candidates now live on the sermon disk. The private_* columns count legacy
 * rows still naming a `private/` path — those files went with the local
 * directory, so they are audited against the sermon disk and report missing,
 * which is the same "needs re-extraction" signal the review screen already gives.
 *
 * A referenced path with no file behind it is unambiguous loss rather than
 * expiry. CleanupUnpublishedSectionAssetsCommand nulls both path columns inside
 * its transaction and deletes the files only afterCommit, so a row that still
 * names a path was never cleaned up.
 */
class AuditSectionAssetsCommand extends Command
{
    /** @var list<string> */
    private const array ASSET_KINDS = [
        'extracted_video',
        'extracted_audio',
    ];

    protected $signature = 'audit:section-assets
        {--json : Emit the full audit report as JSON}
        {--details : List section id + asset kind per finding. Output can hint at guessable storage paths, so keep this off when the output leaves the server (e.g. public CI logs)}';

    protected $description = 'Read-only audit that every referenced service-section publication candidate exists on its expected disk';

    /** @var array<string, array<string, int>> */
    private array $countsByKind = [];

    /** @var array<string, int> */
    private array $sectionCounts = [];

    /** @var list<array{section_id: int, kind: string, issue: string}> */
    private array $findings = [];

    public function __construct(private readonly SourceMediaPresence $sourceMedia)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Artisan reuses the resolved command instance, so repeated invocations
        // in one process must not accumulate the previous run's results.
        $this->findings = [];

        foreach (self::ASSET_KINDS as $kind) {
            $this->countsByKind[$kind] = [
                'referenced' => 0,
                'present' => 0,
                'missing' => 0,
                'check_errors' => 0,
                'private_referenced' => 0,
                'private_missing' => 0,
            ];
        }

        $this->sectionCounts = [
            'with_referenced_assets' => 0,
            'with_missing_assets' => 0,
            // Re-derivability of the sections in with_missing_assets: candidates
            // are re-extracted from the run's source recording, so they are
            // regenerable exactly while it survives. Partition of the above.
            'missing_and_source_media_present' => 0,
            'missing_and_source_media_gone' => 0,
            'missing_and_no_source_reference' => 0,
        ];

        $sections = ServiceSection::query()
            ->select(['id', 'media_processing_log_id', 'extracted_video_path', 'extracted_audio_path'])
            ->where(function ($query): void {
                $query->whereNotNull('extracted_video_path')
                    ->orWhereNotNull('extracted_audio_path');
            })
            ->orderBy('id')
            ->cursor();

        foreach ($sections as $section) {
            $referenced = false;
            $missing = false;

            foreach ($this->referencedAssets($section) as [$kind, $path]) {
                $referenced = true;
                $missing = $this->auditAsset($section, $kind, $path) !== 'present' || $missing;
            }

            if (! $referenced) {
                continue;
            }

            $this->sectionCounts['with_referenced_assets']++;

            if ($missing) {
                $this->sectionCounts['with_missing_assets']++;
                $this->sectionCounts[$this->recoverabilityKey($section)]++;
            }
        }

        $hasFailures = $this->hasFailures();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($this->jsonReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $hasFailures ? self::FAILURE : self::SUCCESS;
        }

        $this->renderTables();

        if ((bool) $this->option('details') && $this->findings !== []) {
            $this->table(
                ['Section', 'Asset kind', 'Issue'],
                array_map(
                    fn (array $finding): array => [(string) $finding['section_id'], $finding['kind'], $finding['issue']],
                    $this->findings,
                ),
            );
        }

        if (! $hasFailures) {
            $this->info('Section asset audit is clean: every referenced publication candidate exists on its expected disk.');

            return self::SUCCESS;
        }

        $this->error('Section asset audit found problems (missing candidates or storage errors).');

        if (! (bool) $this->option('details')) {
            $this->comment('Re-run with --details on the server to see which sections are affected. Storage paths are never printed.');
        }

        return self::FAILURE;
    }

    /**
     * @return list<array{string, string}> [kind, path]
     */
    private function referencedAssets(ServiceSection $section): array
    {
        $assets = [
            ['extracted_video', $section->extracted_video_path],
            ['extracted_audio', $section->extracted_audio_path],
        ];

        return array_values(array_filter(
            $assets,
            fn (array $asset): bool => is_string($asset[1]) && $asset[1] !== '',
        ));
    }

    /**
     * @return 'present'|'missing'|'check_error'
     */
    private function auditAsset(ServiceSection $section, string $kind, string $path): string
    {
        $this->countsByKind[$kind]['referenced']++;

        $isPrivate = MediaAssetPath::isPrivate($path);

        if ($isPrivate) {
            $this->countsByKind[$kind]['private_referenced']++;
        }

        try {
            $exists = Storage::disk($section->extractedAssetDisk())->exists($path);
        } catch (Throwable) {
            $this->countsByKind[$kind]['check_errors']++;
            $this->findings[] = ['section_id' => $section->id, 'kind' => $kind, 'issue' => 'check_error'];

            return 'check_error';
        }

        if (! $exists) {
            $this->countsByKind[$kind]['missing']++;

            if ($isPrivate) {
                $this->countsByKind[$kind]['private_missing']++;
            }

            $this->findings[] = ['section_id' => $section->id, 'kind' => $kind, 'issue' => 'missing'];

            return 'missing';
        }

        $this->countsByKind[$kind]['present']++;

        return 'present';
    }

    /**
     * @return 'missing_and_source_media_present'|'missing_and_source_media_gone'|'missing_and_no_source_reference'
     */
    private function recoverabilityKey(ServiceSection $section): string
    {
        // media_processing_log_id is non-nullable with a cascading FK, so the run
        // always exists; only its source_file_path can be absent.
        $path = $section->processingLog->source_file_path;

        if (! is_string($path) || $path === '') {
            return 'missing_and_no_source_reference';
        }

        return $this->sourceMedia->exists($path)
            ? 'missing_and_source_media_present'
            : 'missing_and_source_media_gone';
    }

    private function hasFailures(): bool
    {
        foreach ($this->countsByKind as $counts) {
            if ($counts['missing'] > 0 || $counts['check_errors'] > 0) {
                return true;
            }
        }

        return false;
    }

    private function renderTables(): void
    {
        $rows = [];
        $totals = [
            'referenced' => 0,
            'present' => 0,
            'missing' => 0,
            'check_errors' => 0,
            'private_referenced' => 0,
            'private_missing' => 0,
        ];

        foreach ($this->countsByKind as $kind => $counts) {
            $rows[] = $this->countRow($kind, $counts);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $counts[$key];
            }
        }

        $rows[] = $this->countRow('total', $totals);

        $this->table(
            ['Asset kind', 'Referenced', 'Present', 'Missing', 'Check errors', 'Private referenced', 'Private missing'],
            $rows,
        );

        $this->table(
            ['Service sections', 'Count'],
            [
                ['with referenced candidate assets', (string) $this->sectionCounts['with_referenced_assets']],
                ['with at least one candidate asset missing', (string) $this->sectionCounts['with_missing_assets']],
                ['  └ re-extractable (source recording present)', (string) $this->sectionCounts['missing_and_source_media_present']],
                ['  └ unrecoverable (source recording gone)', (string) $this->sectionCounts['missing_and_source_media_gone']],
                ['  └ unrecoverable (no source recorded)', (string) $this->sectionCounts['missing_and_no_source_reference']],
            ],
        );
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private function countRow(string $label, array $counts): array
    {
        return [
            $label,
            (string) $counts['referenced'],
            (string) $counts['present'],
            (string) $counts['missing'],
            (string) $counts['check_errors'],
            (string) $counts['private_referenced'],
            (string) $counts['private_missing'],
        ];
    }

    /** @return array{kinds: array<string, array<string, int>>, sections: array<string, int>, findings?: list<array{section_id: int, kind: string, issue: string}>} */
    private function jsonReport(): array
    {
        $report = [
            'kinds' => $this->countsByKind,
            'sections' => $this->sectionCounts,
        ];

        if ((bool) $this->option('details')) {
            $report['findings'] = $this->findings;
        }

        return $report;
    }
}
