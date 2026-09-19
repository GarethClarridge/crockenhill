<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricVideoRoundCoverBuilder;
use App\Services\Import\HistoricVideoRoundEvidence;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Deletion trigger: delete after Operation 4's signed round closeout cover has
 * reached long-term custody and historic-import IC8 closes.
 */
class BuildHistoricVideoRoundCoverCommand extends Command
{
    protected $signature = 'historic-import:build-video-round-cover
        {operation : Immutable historic import operation id}
        {directory : Directory containing the reviewed round reports}
        {expectation : Bound historic-video manifest expectation JSON}
        {output : Absolute private output path}
        {--backup-receipt= : Durable backup receipt identifier}
        {--reviewed-by=maintainer : Accountable reviewer}
        {--reviewed-at= : ISO-8601 review timestamp}
        {--round=round-4 : Round identifier}
        {--commit= : Reviewed application commit}
        {--key-id=historic-import-evidence : Signing key identifier}';

    protected $description = 'Build the exact signed or explicitly not-signable historic-video round cover';

    public function handle(HistoricVideoRoundCoverBuilder $builder): int
    {
        try {
            $operation = HistoricImportOperation::query()
                ->where('operation_id', (string) $this->argument('operation'))
                ->first();

            if (! $operation instanceof HistoricImportOperation) {
                throw new RuntimeException('Historic video round cover operation does not exist.');
            }

            $directory = rtrim((string) $this->argument('directory'), DIRECTORY_SEPARATOR);
            $reportPaths = [];
            $fileNames = [
                'video_status' => 'video-status.json',
                'manifest_expectation' => (string) $this->argument('expectation'),
                'membership_census' => 'membership-census.json',
                'asset_audit' => 'asset-audit.json',
                'scripture_settlement' => 'scripture-settlement.json',
                'operation_ledger' => 'operation-ledger.json',
                'cost_duration' => 'cost-duration.json',
            ];

            foreach (HistoricVideoRoundEvidence::ReportKeys as $key) {
                $file = $fileNames[$key];
                $reportPaths[$key] = $key === 'manifest_expectation' ? $file : "{$directory}/{$file}";
            }

            $cover = $builder->build(
                operation: $operation,
                reportPaths: $reportPaths,
                acceptedHoldsPaths: $this->acceptedHoldPaths($directory),
                round: (string) $this->option('round'),
                commit: (string) ($this->option('commit') ?: config('app.release_identifier')),
                backupReceipt: is_string($this->option('backup-receipt')) ? $this->option('backup-receipt') : null,
                reviewedBy: (string) $this->option('reviewed-by'),
                reviewedAt: (string) ($this->option('reviewed-at') ?: now()->utc()->toIso8601String()),
                signingKey: config('media-processing.historic_import.evidence_signing_key'),
                keyId: (string) $this->option('key-id'),
            );
            $this->write((string) $this->argument('output'), $cover);

            if (data_get($cover, 'signing.state') !== 'signed') {
                $this->error('Historic video round cover was written as not signable: '.implode(', ', data_get($cover, 'signing.blockers', [])));

                return self::FAILURE;
            }

            $this->info('Historic video round cover is signed and ready for independent verification.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<string> */
    private function acceptedHoldPaths(string $directory): array
    {
        $paths = glob("{$directory}/*hold-dispositions.json");
        $manualReview = "{$directory}/manual-review-dispositions.json";

        if (! is_array($paths)) {
            $paths = [];
        }

        if (is_file($manualReview)) {
            $paths[] = $manualReview;
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /** @param array<string, mixed> $cover */
    private function write(string $path, array $cover): void
    {
        if (! str_starts_with($path, '/')) {
            throw new RuntimeException('Historic video round cover output path must be absolute.');
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Historic video round cover directory could not be created.');
        }

        if (file_put_contents($path, json_encode($cover, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL) === false
            || ! chmod($path, 0600)) {
            throw new RuntimeException('Historic video round cover could not be written privately.');
        }
    }
}
