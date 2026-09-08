<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Jobs\AssessSermonVideoQuality;
use App\Services\HistoricMedia\HistoricStagingReachability;
use Throwable;

/**
 * Can this process read the disk that owns a stored media asset right now?
 *
 * A local disk is a mount, and a mount can go away. When it does, every read
 * against it answers exactly as it would for a file that was deliberately
 * deleted: `exists()` returns false. The historic pipeline learned this the
 * expensive way -- an unreachable drive is indistinguishable from reaped media
 * unless something checks the disk root first.
 *
 * That ambiguity matters most where a read failure is written down as a
 * judgement. {@see AssessSermonVideoQuality} recorded 81 sermons as
 * `unassessed`/`missing_video_file` whose videos were present and nonempty the
 * whole time; the job had looked on the configured sermon disk rather than the
 * disk each sermon records in `asset_disk`. Correcting the disk removes that
 * particular cause, but not the class: a detached drive would still convert a
 * whole batch of good assets into missing-file verdicts.
 *
 * So callers ask here before treating an absent file as evidence. A missing
 * root is an evidence-access failure -- retry it when the volume returns. Only
 * a reachable root makes "the file is not there" a fact worth persisting.
 *
 * Deliberately a read probe, unlike {@see HistoricStagingReachability},
 * which writes: a worker about to produce output must prove the volume accepts
 * writes, whereas an assessment only ever reads, and probing with a write would
 * refuse read-only archive mounts that serve their files perfectly well.
 */
final class MediaDiskReachability
{
    /**
     * True when the disk is configured and its storage is available for reads.
     */
    public function isReachable(string $disk): bool
    {
        return $this->unreachableReason($disk) === null;
    }

    /**
     * Why the disk cannot be read from, or null when it can.
     *
     * Never throws: an unconfigured or unresolvable disk is reported as
     * unreachable, because a caller that cannot establish where an asset lives
     * must not conclude that the asset is gone.
     */
    public function unreachableReason(string $disk): ?string
    {
        if ($disk === '') {
            return 'No disk was named.';
        }

        $configuration = config("filesystems.disks.{$disk}");

        if (! is_array($configuration)) {
            return "Disk [{$disk}] has no configuration.";
        }

        /**
         * Only a local disk has a mount that can disappear underneath a running
         * process. A remote driver reports its own per-operation failures, and
         * treating it as unreachable here would stall work that would have
         * succeeded, so it is reported reachable.
         */
        if (($configuration['driver'] ?? null) !== 'local') {
            return null;
        }

        $root = $configuration['root'] ?? null;

        if (! is_string($root) || $root === '') {
            return "Disk [{$disk}] has no root path.";
        }

        try {
            if (! is_dir($root)) {
                return "Disk [{$disk}] root [{$root}] is not a directory; the volume is not mounted.";
            }

            if (! is_readable($root)) {
                return "Disk [{$disk}] root [{$root}] is not readable.";
            }
        } catch (Throwable $exception) {
            return "Disk [{$disk}] root [{$root}] could not be inspected: ".$exception->getMessage();
        }

        return null;
    }
}
