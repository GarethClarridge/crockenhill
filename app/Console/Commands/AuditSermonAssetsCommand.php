<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Support\MediaAssetPath;
use App\Support\SermonAssetReferences;
use App\Support\SourceMediaPresence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuditSermonAssetsCommand extends Command
{
    /** @var list<string> */
    private const array ASSET_KINDS = [
        'audio',
        'video',
        'transcript',
        'thumbnail',
        'plain_thumbnail',
        'card_thumbnail',
        'overlay_thumbnail',
        'candidate_plain',
        'candidate_card',
        'candidate_overlay',
    ];

    /**
     * Disks probed when a referenced asset is absent from the one its kind is
     * configured to use. A hit means the bytes survived a disk repointing that
     * moved the configuration without moving the objects — the fault that
     * orphaned the thumbnail family, and which read as plain data loss until
     * somebody probed a key by hand.
     *
     * Only the two local disks are named here — the previous homes of every
     * asset family. Every remote disk reaches the probe set by being a
     * *configured* kind disk, which both keeps a future disk covered without
     * editing this list and stops the audit building an S3 client for a bucket
     * this installation does not use.
     *
     * @var list<string>
     */
    private const array STRANDED_PROBE_DISKS = ['public', 'local'];

    protected $signature = 'audit:sermon-assets
        {--json : Emit the full audit report as JSON}
        {--details : List sermon id + asset kind per finding. Output can hint at guessable storage paths, so keep this off when the output leaves the server (e.g. public CI logs)}';

    protected $description = 'Read-only audit that every referenced sermon asset exists on its expected disk';

    /** @var array<string, array<string, int>> */
    private array $countsByKind = [];

    /** @var array<string, int> */
    private array $childrensTalkCounts = [];

    /** @var list<array{sermon_id: int, kind: string, issue: string}> */
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
                'stranded' => 0,
                'check_errors' => 0,
                'private_referenced' => 0,
                'private_missing' => 0,
            ];
        }

        $this->childrensTalkCounts = [
            'total' => 0,
            'with_private_assets' => 0,
            'with_missing_private_assets' => 0,
            // Re-derivability of the talks in with_missing_private_assets. These
            // three are a partition of it, so they sum back to it.
            'missing_and_source_media_present' => 0,
            'missing_and_source_media_gone' => 0,
            'missing_and_no_source_reference' => 0,
        ];

        $sermons = Sermon::query()
            ->select(['id', 'content_type', ...SermonAssetReferences::selectColumns()])
            ->orderBy('id')
            ->cursor();

        foreach ($sermons as $sermon) {
            $isChildrensTalk = $sermon->content_type === SermonContentType::ChildrensTalk;

            if ($isChildrensTalk) {
                $this->childrensTalkCounts['total']++;
            }

            $hasPrivateAsset = false;
            $hasMissingPrivateAsset = false;

            foreach (SermonAssetReferences::for($sermon) as ['kind' => $kind, 'disk' => $kindDisk, 'path' => $path]) {
                $outcome = $this->auditAsset($sermon, $kind, $kindDisk, $path);

                if (! MediaAssetPath::isPrivate($path)) {
                    continue;
                }

                $hasPrivateAsset = true;
                $hasMissingPrivateAsset = $hasMissingPrivateAsset || $outcome !== 'present';
            }

            if (! $isChildrensTalk || ! $hasPrivateAsset) {
                continue;
            }

            $this->childrensTalkCounts['with_private_assets']++;

            if ($hasMissingPrivateAsset) {
                $this->childrensTalkCounts['with_missing_private_assets']++;
                $this->childrensTalkCounts[$this->recoverabilityKey($sermon)]++;
            }
        }

        $hasFailures = $this->hasFailures();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($this->jsonReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $hasFailures ? self::FAILURE : self::SUCCESS;
        }

        $this->renderTable();

        if ((bool) $this->option('details') && $this->findings !== []) {
            $this->table(
                ['Sermon', 'Asset kind', 'Issue'],
                array_map(
                    fn (array $finding): array => [(string) $finding['sermon_id'], $finding['kind'], $finding['issue']],
                    $this->findings,
                ),
            );
        }

        if (! $hasFailures) {
            $this->info('Sermon asset audit is clean: every referenced asset exists on its expected disk.');

            return self::SUCCESS;
        }

        $this->error('Sermon asset audit found problems (missing assets, stranded assets, or storage errors).');

        if ($this->strandedTotal() > 0) {
            $this->comment('Stranded assets exist on another disk under the same key — their disk was repointed without the objects being moved. `media:restore-stranded-thumbnails` recovers the thumbnail family.');
        }

        if (! (bool) $this->option('details')) {
            $this->comment('Re-run with --details on the server to see which sermons are affected. Storage paths are never printed.');
        }

        return self::FAILURE;
    }

    /**
     * @return 'present'|'missing'|'stranded'|'check_error'
     */
    private function auditAsset(Sermon $sermon, string $kind, string $kindDisk, string $path): string
    {
        $this->countsByKind[$kind]['referenced']++;

        $isPrivate = MediaAssetPath::isPrivate($path);

        if ($isPrivate) {
            $this->countsByKind[$kind]['private_referenced']++;
        }

        // Private paths live on the local disk regardless of asset kind. No sermon
        // asset should be private any more, but the check is kept so a regression
        // is reported rather than silently audited against the wrong disk.
        $expectedDisk = $isPrivate ? 'local' : $kindDisk;

        try {
            $exists = Storage::disk($expectedDisk)->exists($path);
        } catch (Throwable) {
            $this->countsByKind[$kind]['check_errors']++;
            $this->findings[] = ['sermon_id' => $sermon->id, 'kind' => $kind, 'issue' => 'check_error'];

            return 'check_error';
        }

        if ($exists) {
            $this->countsByKind[$kind]['present']++;

            return 'present';
        }

        if ($isPrivate) {
            $this->countsByKind[$kind]['private_missing']++;
        }

        $strandedOn = $this->strandedDisk($expectedDisk, $path);

        if ($strandedOn !== null) {
            $this->countsByKind[$kind]['stranded']++;
            $this->findings[] = ['sermon_id' => $sermon->id, 'kind' => $kind, 'issue' => "stranded on {$strandedOn}"];

            return 'stranded';
        }

        $this->countsByKind[$kind]['missing']++;
        $this->findings[] = ['sermon_id' => $sermon->id, 'kind' => $kind, 'issue' => 'missing'];

        return 'missing';
    }

    /**
     * The disk holding this exact key, when it is absent from the one the asset
     * is configured to use. Null when the bytes are genuinely gone.
     *
     * A probe that throws is treated as "not here" rather than as a check error:
     * the expected disk answered successfully, so the asset's status is known,
     * and an unreachable secondary disk should not turn a definite `missing`
     * into a `check_error`. Probes only run for assets already known to be
     * absent, so a clean audit costs nothing.
     */
    private function strandedDisk(string $expectedDisk, string $path): ?string
    {
        foreach ($this->probeDisks($expectedDisk) as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return $disk;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function probeDisks(string $expectedDisk): array
    {
        $configured = (array) config('filesystems.disks', []);

        $candidates = [
            ...self::STRANDED_PROBE_DISKS,
            SermonAssetReferences::sermonDisk(),
            SermonAssetReferences::transcriptDisk(),
            SermonAssetReferences::thumbnailDisk(),
        ];

        return array_values(array_filter(
            array_unique($candidates),
            fn (string $disk): bool => $disk !== $expectedDisk && array_key_exists($disk, $configured),
        ));
    }

    /**
     * Which recoverability bucket a talk with missing private assets falls into.
     * A talk is re-derivable only while the source recording behind its
     * processing run survives — see WP1 of the children's-talk storage plan.
     *
     * @return 'missing_and_source_media_present'|'missing_and_source_media_gone'|'missing_and_no_source_reference'
     */
    private function recoverabilityKey(Sermon $sermon): string
    {
        $sourcePath = $this->sourceFilePathFor($sermon);

        if ($sourcePath === null) {
            return 'missing_and_no_source_reference';
        }

        return $this->sourceMedia->exists($sourcePath)
            ? 'missing_and_source_media_present'
            : 'missing_and_source_media_gone';
    }

    /**
     * A sermon reaches its processing run either directly (`sermon_id` on the
     * log) or through the service section that published it; older and manually
     * created talks have neither.
     */
    private function sourceFilePathFor(Sermon $sermon): ?string
    {
        $log = $sermon->latestProcessingLog ?? $sermon->publishedServiceSection?->processingLog;
        $path = $log?->source_file_path;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Stranded assets count as failures. The bytes existing somewhere is a
     * recovery opportunity, not a working site: every reader resolves the
     * configured disk, so a visitor still gets a 404.
     */
    private function strandedTotal(): int
    {
        return array_sum(array_column($this->countsByKind, 'stranded'));
    }

    private function hasFailures(): bool
    {
        foreach ($this->countsByKind as $counts) {
            if ($counts['missing'] > 0 || $counts['stranded'] > 0 || $counts['check_errors'] > 0) {
                return true;
            }
        }

        return false;
    }

    private function renderTable(): void
    {
        $rows = [];
        $totals = [
            'referenced' => 0,
            'present' => 0,
            'missing' => 0,
            'stranded' => 0,
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
            [
                'Asset kind',
                'Referenced',
                'Present',
                'Missing',
                'Stranded',
                'Check errors',
                'Private referenced',
                'Private missing',
            ],
            $rows,
        );

        $this->renderChildrensTalkSummary();
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
            (string) $counts['stranded'],
            (string) $counts['check_errors'],
            (string) $counts['private_referenced'],
            (string) $counts['private_missing'],
        ];
    }

    /**
     * Per-talk rather than per-asset, because recoverability is decided one talk
     * at a time: a talk missing only its thumbnail is a different problem from a
     * talk missing its audio.
     */
    private function renderChildrensTalkSummary(): void
    {
        $this->table(
            ["Children's talks", 'Count'],
            [
                ['total', (string) $this->childrensTalkCounts['total']],
                ['with private assets referenced', (string) $this->childrensTalkCounts['with_private_assets']],
                ['with at least one private asset missing', (string) $this->childrensTalkCounts['with_missing_private_assets']],
                ['  └ re-derivable (source recording present)', (string) $this->childrensTalkCounts['missing_and_source_media_present']],
                ['  └ unrecoverable (source recording gone)', (string) $this->childrensTalkCounts['missing_and_source_media_gone']],
                ['  └ unrecoverable (no source recorded)', (string) $this->childrensTalkCounts['missing_and_no_source_reference']],
            ],
        );
    }

    /** @return array{kinds: array<string, array<string, int>>, childrens_talks: array<string, int>, findings?: list<array{sermon_id: int, kind: string, issue: string}>} */
    private function jsonReport(): array
    {
        $report = [
            'kinds' => $this->countsByKind,
            'childrens_talks' => $this->childrensTalkCounts,
        ];

        if ((bool) $this->option('details')) {
            $report['findings'] = $this->findings;
        }

        return $report;
    }
}
