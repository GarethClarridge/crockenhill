<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\HistoricImportOperation;
use RuntimeException;

final class HistoricVideoRoundEvidenceCustody
{
    /** @return array<string, string> */
    public function retain(HistoricImportOperation $operation, string $sourceDirectory, string $expectationPath): array
    {
        $sources = [
            'video-status.json' => "{$sourceDirectory}/video-status.json",
            'manifest-expectation.json' => $expectationPath,
            'membership-census.json' => "{$sourceDirectory}/membership-census.json",
            'asset-audit.json' => "{$sourceDirectory}/asset-audit.json",
            'scripture-settlement.json' => "{$sourceDirectory}/scripture-settlement.json",
            'operation-ledger.json' => "{$sourceDirectory}/operation-ledger.json",
            'cost-duration.json' => "{$sourceDirectory}/cost-duration.json",
            'manual-review-dispositions.json' => "{$sourceDirectory}/manual-review-dispositions.json",
            'prior-accepted-hold-dispositions.json' => "{$sourceDirectory}/prior-accepted-hold-dispositions.json",
        ];
        $directory = storage_path("app/private/historic-import/{$operation->operation_id}/closeout/video-round-evidence");

        $this->ensureDirectory($directory);
        $retained = [];

        foreach ($sources as $name => $source) {
            $target = "{$directory}/{$name}";
            $this->retainFile($source, $target);
            $retained[$name] = $target;
        }

        return $retained;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_link($directory)
            || (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory))
            || ! chmod($directory, 0700)) {
            throw new RuntimeException('Historic video round evidence custody directory is unsafe.');
        }
    }

    private function retainFile(string $source, string $target): void
    {
        if (! is_file($source) || is_link($source) || ! is_readable($source)) {
            throw new RuntimeException("Historic video round custody source is missing or unsafe: {$source}");
        }

        if (is_file($target)) {
            if (! hash_equals((string) hash_file('sha256', $source), (string) hash_file('sha256', $target))) {
                throw new RuntimeException("Historic video round retained evidence has drifted: {$target}");
            }

            return;
        }

        $contents = file_get_contents($source);
        $handle = fopen($target, 'x+b');
        if (! is_string($contents) || $handle === false) {
            throw new RuntimeException("Historic video round evidence could not be retained: {$target}");
        }

        try {
            if (! chmod($target, 0600) || fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException("Historic video round evidence retention was incomplete: {$target}");
            }
        } finally {
            fclose($handle);
        }
    }
}
