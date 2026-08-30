<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Services\Media\TempDiskSpace;

/**
 * Reports what a historic pass needs of the staging drive, and whether this
 * process is in any position to say how much is there.
 *
 * The pilot-to-bulk plan was written around "about 30 GB available of 461 GB".
 * That figure came from `df` inside the container, which reports the host's boot
 * volume rather than the bind-mounted drive: the drive is 1.8 TiB with 444 GiB
 * free. {@see TempDiskSpace::checksDisabled()} already documents why no check can
 * detect this from the inside, and the operator has already declared the volume
 * unmeasurable, so every gate correctly stands down — silently. That silence is
 * what let a wrong number reach a plan.
 *
 * Nothing here decides anything. It states the requirement, states plainly
 * whether the free space is knowable from here, and names the command that
 * answers it from the host.
 */
class HistoricStagingHeadroom
{
    /**
     * @param  list<array<string, mixed>>  $workItems  Curation-plan work items; only `bytes` is read
     * @return array{
     *     measurable: bool,
     *     process_reported_free_bytes: int|null,
     *     host_command: string,
     *     item_count: int,
     *     selected_source_bytes: int,
     *     largest_source_bytes: int,
     *     concurrent_working_bytes: int,
     *     minimum_free_bytes: int,
     *     required_free_bytes: int
     * }
     */
    public function report(array $workItems, int $parallel, int $minimumFreeGb): array
    {
        $sizes = array_map(static fn (array $item): int => (int) ($item['bytes'] ?? 0), $workItems);
        rsort($sizes);
        $minimumFreeBytes = $minimumFreeGb * 1024 ** 3;

        /**
         * FFmpeg reads a source and writes beside it, so an item in flight
         * occupies about twice its source — the same rule the importer's own
         * per-item guard applies. The pass needs that much for every job running
         * at once, on top of the floor.
         */
        $concurrent = 2 * array_sum(array_slice($sizes, 0, max(1, $parallel)));

        return [
            'measurable' => ! TempDiskSpace::checksDisabled(),
            'process_reported_free_bytes' => $this->processReportedFreeBytes(),
            'host_command' => 'df -h "$CBC_HISTORIC_WORK_PATH"',
            'item_count' => count($workItems),
            'selected_source_bytes' => array_sum($sizes),
            'largest_source_bytes' => $sizes === [] ? 0 : $sizes[0],
            'concurrent_working_bytes' => $concurrent,
            'minimum_free_bytes' => $minimumFreeBytes,
            'required_free_bytes' => $minimumFreeBytes + $concurrent,
        ];
    }

    /**
     * What `disk_free_space()` says, which is not necessarily true.
     *
     * Reported so an operator comparing it against the host figure can see the
     * discrepancy for themselves rather than trusting either in isolation.
     */
    private function processReportedFreeBytes(): ?int
    {
        $free = @disk_free_space(TempDiskSpace::path());

        return $free === false ? null : (int) $free;
    }
}
