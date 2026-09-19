<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricVideoRoundMembership;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Deletion trigger: delete with the historic-video importer after Operation 4's
 * reviewed round evidence has reached long-term custody and IC8 closes.
 */
class ReportHistoricVideoRoundMembershipCommand extends Command
{
    protected $signature = 'historic-import:report-video-round-membership
        {operation : Immutable historic import operation id}
        {expectation : Bound historic-video import plan JSON}
        {report : Absolute output path for the private membership report}';

    protected $description = 'Reconcile exact historic-video manifest membership across operation and pre-existing runs';

    public function handle(HistoricVideoRoundMembership $membership): int
    {
        try {
            $operation = HistoricImportOperation::query()
                ->where('operation_id', (string) $this->argument('operation'))
                ->first();

            if (! $operation instanceof HistoricImportOperation) {
                throw new RuntimeException('Historic video membership operation does not exist.');
            }

            $path = (string) $this->argument('report');

            if (! str_starts_with($path, '/')) {
                throw new RuntimeException('Historic video membership report path must be absolute.');
            }

            $report = $membership->report(
                $operation,
                $this->read((string) $this->argument('expectation')),
            );
            $this->write($path, $report);

            $counts = collect(is_array($report['counts'] ?? null) ? $report['counts'] : [])
                ->map(static fn (int $count, string $disposition): string => "{$disposition}: {$count}")
                ->implode(', ');
            $this->info("Historic video round membership written — {$counts}.");

            if ($report['unresolved'] !== []) {
                $this->error('Membership remains unresolved: '.implode(', ', $report['unresolved']));

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Historic video membership expectation is missing.');
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Historic video membership expectation is invalid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Historic video membership expectation must be a JSON object.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $report */
    private function write(string $path, array $report): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create historic video membership directory: {$directory}");
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json.PHP_EOL) === false || ! chmod($path, 0600)) {
            throw new RuntimeException("Unable to write historic video membership report: {$path}");
        }
    }
}
