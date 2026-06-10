<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Checks;

use App\Services\Media\TempDiskSpace;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Watches free space on the local temp disk — the media pipeline's real
 * bottleneck (every source upload and intermediate FFmpeg artefact stages
 * there). Reads the shared TempDiskSpace helper, the same source as the
 * upload validator and the historic-importer guard, so the alert threshold
 * and the behaviour-gating threshold can never drift apart: this check fails
 * at exactly the floor where uploads start being rejected, and warns at twice
 * that floor to give notice before rejections begin.
 */
class TempDiskSpaceCheck extends Check
{
    public function run(): Result
    {
        $path = TempDiskSpace::path();
        $freeBytes = disk_free_space($path);

        if ($freeBytes === false) {
            return Result::make()
                ->warning("Could not measure free space on the temp disk at {$path}.")
                ->shortSummary('Unmeasurable');
        }

        $minFreeBytes = TempDiskSpace::minFreeBytes();
        $freeGb = round($freeBytes / 1024 ** 3, 1);
        $floorGb = round($minFreeBytes / 1024 ** 3, 1);

        $result = Result::make()
            ->meta(['free_gb' => $freeGb, 'floor_gb' => $floorGb, 'path' => $path])
            ->shortSummary("{$freeGb} GB free");

        if ($freeBytes < $minFreeBytes) {
            return $result->failed("The temp disk has {$freeGb} GB free, below the {$floorGb} GB floor — media uploads are being rejected.");
        }

        if ($freeBytes < $minFreeBytes * 2) {
            return $result->warning("The temp disk has {$freeGb} GB free, approaching the {$floorGb} GB floor at which media uploads are rejected.");
        }

        return $result->ok();
    }
}
