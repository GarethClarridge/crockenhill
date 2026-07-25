<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sermon;
use App\Support\MediaAssetPath;
use App\Support\SermonAssetReferences;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Copies thumbnail-family objects that were orphaned when
 * `thumbnail-generation.storage.disk` was repointed at Spaces without the
 * existing objects being moved with it.
 *
 * No database column is written. The stored paths were always correct — a row
 * holding `sermons/thumbnails/sermon_842_…_overlay.webp` becomes right the
 * moment that key exists on the configured disk. This command only puts bytes
 * where the paths already point.
 *
 * Sources are never deleted, so the whole operation is additive and rolls back
 * by deleting whatever it copied. The dry run is the measurement: it reports the
 * per-path overlap between what the audit calls missing and what survives on the
 * source disk, which is the number that sizes the real run.
 */
class RestoreStrandedThumbnailsCommand extends Command
{
    protected $signature = 'media:restore-stranded-thumbnails
        {--source-disk=public : The disk the objects were stranded on when the thumbnail disk was repointed}
        {--sermon=* : Limit to these sermon IDs}
        {--apply : Copy the stranded objects (default: dry-run — nothing is written)}
        {--details : List sermon id + asset kind per outcome. Storage paths are never printed, so this is safe in a shared log}
        {--json : Emit the full report as JSON}';

    protected $description = 'Copy stranded sermon thumbnails onto the disk their stored paths already point at';

    /** @var list<string> */
    private const array OUTCOMES = [
        'already_present',
        'restored',
        'restorable',
        'unrecoverable',
        'size_mismatch',
        'failed',
        'skipped_private',
    ];

    /**
     * Counts of distinct storage objects — what this run actually did to the
     * disks.
     *
     * @var array<string, int>
     */
    private array $outcomeCounts = [];

    /**
     * Counts of asset *references*, the same unit `audit:sermon-assets` reports
     * in. One object reached through two references is counted twice here and
     * once above, which is what makes the two commands' tables comparable row
     * for row while keeping the object tally honest.
     *
     * @var array<string, array<string, int>>
     */
    private array $countsByKind = [];

    /** @var list<array{sermon_id: int, kind: string, outcome: string}> */
    private array $findings = [];

    /**
     * The outcome already determined for each path this run. One object can be
     * referenced more than once — a selected candidate is also the sermon's
     * thumbnail — and copying it twice would waste a transfer and, worse, report
     * the second reference as a separate restoration.
     *
     * @var array<string, string>
     */
    private array $handledPaths = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $sourceDisk = (string) $this->option('source-disk');
        $targetDisk = SermonAssetReferences::thumbnailDisk();

        if ($sourceDisk === $targetDisk) {
            $this->error("The source and target disks are both [{$sourceDisk}]. Nothing can be stranded relative to itself.");

            return self::FAILURE;
        }

        if (! array_key_exists($sourceDisk, (array) config('filesystems.disks', []))) {
            $this->error("Unknown source disk [{$sourceDisk}].");

            return self::FAILURE;
        }

        $this->resetReport();

        /** @var list<string> $sermonIds */
        $sermonIds = (array) $this->option('sermon');

        $sermons = Sermon::query()
            ->select(['id', ...SermonAssetReferences::selectColumns()])
            ->when($sermonIds !== [], fn ($query) => $query->whereIn('id', $sermonIds))
            ->orderBy('id')
            ->cursor();

        foreach ($sermons as $sermon) {
            foreach (SermonAssetReferences::thumbnailsFor($sermon) as ['kind' => $kind, 'path' => $path]) {
                $seen = isset($this->handledPaths[$path]);

                $outcome = $seen
                    ? $this->handledPaths[$path]
                    : $this->restoreAsset($sourceDisk, $targetDisk, $path, $apply);

                $this->handledPaths[$path] = $outcome;

                $this->record($sermon, $kind, $outcome, countsAsObject: ! $seen);
            }
        }

        return $this->report($apply, $sourceDisk, $targetDisk);
    }

    /**
     * @return value-of<self::OUTCOMES>
     */
    private function restoreAsset(string $sourceDisk, string $targetDisk, string $path, bool $apply): string
    {
        // Legacy `private/` rows resolve to the local disk, not the thumbnail
        // disk, so their key is not the key this command would write. They are
        // the children's-talk plan's territory and are reported rather than
        // guessed at.
        if (MediaAssetPath::isPrivate($path)) {
            return 'skipped_private';
        }

        try {
            $source = Storage::disk($sourceDisk);
            $target = Storage::disk($targetDisk);

            if ($target->exists($path)) {
                return $this->verifyExistingTarget($source, $target, $path);
            }

            if (! $source->exists($path)) {
                return 'unrecoverable';
            }

            if (! $apply) {
                return 'restorable';
            }

            $this->copyAndVerify($sourceDisk, $targetDisk, $path);

            return 'restored';
        } catch (Throwable $exception) {
            // One unreadable object must not abandon the rest of the archive.
            Log::error('media:restore-stranded-thumbnails: asset restore failed', [
                'source_disk' => $sourceDisk,
                'target_disk' => $targetDisk,
                'path' => $path,
                'exception' => $exception,
            ]);

            return 'failed';
        }
    }

    /**
     * An object already on the target is left alone — this command never
     * overwrites a live key. A size disagreement with a surviving source is
     * still worth surfacing: it is the signature of a truncated upload, and
     * silently calling it "already present" would hide it.
     *
     * @return value-of<self::OUTCOMES>
     */
    private function verifyExistingTarget(Filesystem $source, Filesystem $target, string $path): string
    {
        if (! $source->exists($path)) {
            return 'already_present';
        }

        return $source->size($path) === $target->size($path)
            ? 'already_present'
            : 'size_mismatch';
    }

    /**
     * Streams the object across and verifies it landed at the same size before
     * the copy is considered done. A target that fails verification is deleted,
     * because this run created it and a half-written object under a referenced
     * key is worse than no object at all.
     */
    private function copyAndVerify(string $sourceDisk, string $targetDisk, string $path): void
    {
        $source = Storage::disk($sourceDisk);
        $target = Storage::disk($targetDisk);

        $stream = $source->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read stranded source: [{$sourceDisk}] {$path}");
        }

        try {
            $written = $target->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }

        if ($written !== true) {
            $target->delete($path);

            throw new RuntimeException("Unable to write restored thumbnail: [{$targetDisk}] {$path}");
        }

        if (! $target->exists($path) || $source->size($path) !== $target->size($path)) {
            $target->delete($path);

            throw new RuntimeException("Restored thumbnail failed verification: [{$targetDisk}] {$path}");
        }

        Log::info('media:restore-stranded-thumbnails: thumbnail restored', [
            'source_disk' => $sourceDisk,
            'target_disk' => $targetDisk,
            'path' => $path,
        ]);
    }

    private function record(Sermon $sermon, string $kind, string $outcome, bool $countsAsObject): void
    {
        if ($countsAsObject) {
            $this->outcomeCounts[$outcome]++;
        }

        $this->countsByKind[$kind][$outcome]++;

        if ($outcome === 'already_present') {
            return;
        }

        $this->findings[] = ['sermon_id' => $sermon->id, 'kind' => $kind, 'outcome' => $outcome];
    }

    private function resetReport(): void
    {
        // Artisan reuses the resolved command instance, so a second invocation
        // in one process must not inherit the first run's tallies.
        $this->findings = [];
        $this->handledPaths = [];
        $this->outcomeCounts = array_fill_keys(self::OUTCOMES, 0);
        $this->countsByKind = array_fill_keys(
            SermonAssetReferences::THUMBNAIL_KINDS,
            array_fill_keys(self::OUTCOMES, 0),
        );
    }

    private function report(bool $apply, string $sourceDisk, string $targetDisk): int
    {
        $needsAttention = $this->outcomeCounts['failed'] > 0 || $this->outcomeCounts['size_mismatch'] > 0;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($this->jsonReport($apply, $sourceDisk, $targetDisk), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $needsAttention ? self::FAILURE : self::SUCCESS;
        }

        $this->line($apply
            ? "<fg=yellow>APPLYING</> — copying stranded thumbnails from [{$sourceDisk}] to [{$targetDisk}]. Sources are left in place."
            : "<fg=cyan>DRY RUN</> — measuring what could be copied from [{$sourceDisk}] to [{$targetDisk}] (re-run with --apply to copy):");
        $this->newLine();

        $this->renderCountTable();

        if ((bool) $this->option('details') && $this->findings !== []) {
            $this->table(
                ['Sermon', 'Asset kind', 'Outcome'],
                array_map(
                    fn (array $finding): array => [(string) $finding['sermon_id'], $finding['kind'], $finding['outcome']],
                    $this->findings,
                ),
            );
        }

        $this->newLine();
        $this->renderSummary($apply);

        return $needsAttention ? self::FAILURE : self::SUCCESS;
    }

    private function renderCountTable(): void
    {
        $rows = [];
        $totals = array_fill_keys(self::OUTCOMES, 0);

        foreach ($this->countsByKind as $kind => $counts) {
            $rows[] = [$kind, ...array_map(strval(...), array_values($counts))];

            foreach ($totals as $outcome => $total) {
                $totals[$outcome] = $total + $counts[$outcome];
            }
        }

        $rows[] = ['total', ...array_map(strval(...), array_values($totals))];

        $this->table(
            ['Asset kind', 'Already present', 'Restored', 'Restorable', 'Unrecoverable', 'Size mismatch', 'Failed', 'Skipped (private)'],
            $rows,
        );

        $this->line('<fg=gray>Counted per asset reference, matching `audit:sermon-assets`. One object referenced twice appears twice here and is copied once.</>');
    }

    private function renderSummary(bool $apply): void
    {
        if ($apply) {
            $this->info(sprintf(
                'Restored %d distinct object(s). %d were already present and %d could not be found on either disk.',
                $this->outcomeCounts['restored'],
                $this->outcomeCounts['already_present'],
                $this->outcomeCounts['unrecoverable'],
            ));
        } else {
            $this->info(sprintf(
                '%d distinct object(s) can be restored. %d are already present and %d are absent from both disks.',
                $this->outcomeCounts['restorable'],
                $this->outcomeCounts['already_present'],
                $this->outcomeCounts['unrecoverable'],
            ));
        }

        if ($this->outcomeCounts['unrecoverable'] > 0) {
            $this->comment('Unrecoverable objects are genuinely gone from both disks and need regenerating, not copying.');
        }

        if ($this->outcomeCounts['size_mismatch'] > 0) {
            $this->error(sprintf(
                '%d object(s) exist on both disks at different sizes. Nothing was overwritten; these need a human.',
                $this->outcomeCounts['size_mismatch'],
            ));
        }

        if ($this->outcomeCounts['failed'] > 0) {
            $this->error(sprintf('%d object(s) failed to copy. See the log for each path.', $this->outcomeCounts['failed']));
        }

        if (! $apply) {
            $this->comment('Nothing was written. Re-run with --apply to copy, then confirm with `audit:sermon-assets`.');

            return;
        }

        $this->comment('Sources were left in place, so this run is undone by deleting the copied objects.');
    }

    /**
     * `objects` counts distinct storage keys — what this run did to the disks.
     * `references` counts asset references per kind, the unit
     * `audit:sermon-assets` reports in, so the two reports can be compared
     * directly.
     *
     * @return array{
     *     apply: bool,
     *     source_disk: string,
     *     target_disk: string,
     *     objects: array<string, int>,
     *     references: array<string, array<string, int>>,
     *     findings?: list<array{sermon_id: int, kind: string, outcome: string}>
     * }
     */
    private function jsonReport(bool $apply, string $sourceDisk, string $targetDisk): array
    {
        $report = [
            'apply' => $apply,
            'source_disk' => $sourceDisk,
            'target_disk' => $targetDisk,
            'objects' => $this->outcomeCounts,
            'references' => $this->countsByKind,
        ];

        if ((bool) $this->option('details')) {
            $report['findings'] = $this->findings;
        }

        return $report;
    }
}
