<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ThumbnailMetadata;
use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Support\MediaAssetPath;
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

    protected $signature = 'audit:sermon-assets
        {--json : Emit the full audit report as JSON}
        {--details : List sermon id + asset kind per finding. Output can hint at guessable storage paths, so keep this off when the output leaves the server (e.g. public CI logs)}';

    protected $description = 'Read-only audit that every referenced sermon asset exists on its expected disk and that children\'s-talk assets sit under private storage';

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
                'check_errors' => 0,
                'childrens_talk_public' => 0,
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

        $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
        $transcriptDisk = (string) config('media-processing.storage.transcript_disk', $sermonDisk);
        $thumbnailDisk = (string) config('thumbnail-generation.storage.disk', 'public');

        $sermons = Sermon::query()
            ->select(['id', 'content_type', 'audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path', 'thumbnail_metadata'])
            ->orderBy('id')
            ->cursor();

        foreach ($sermons as $sermon) {
            $isChildrensTalk = $sermon->content_type === SermonContentType::ChildrensTalk;

            if ($isChildrensTalk) {
                $this->childrensTalkCounts['total']++;
            }

            $hasPrivateAsset = false;
            $hasMissingPrivateAsset = false;

            foreach ($this->referencedAssets($sermon, $sermonDisk, $transcriptDisk, $thumbnailDisk) as [$kind, $kindDisk, $path]) {
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

        $this->error('Sermon asset audit found problems (missing assets, storage errors, or publicly placed children\'s-talk assets).');

        if (! (bool) $this->option('details')) {
            $this->comment('Re-run with --details on the server to see which sermons are affected. Storage paths are never printed.');
        }

        return self::FAILURE;
    }

    /**
     * Every asset reference on the sermon row, mirroring the placement contract
     * used by MoveSermonToPrivateStorage.
     *
     * @return list<array{string, string, string}> [kind, kindDisk, path]
     */
    private function referencedAssets(Sermon $sermon, string $sermonDisk, string $transcriptDisk, string $thumbnailDisk): array
    {
        $assets = [
            ['audio', $sermonDisk, $sermon->audio_file_path],
            ['video', $sermonDisk, $sermon->video_file_path],
            ['transcript', $transcriptDisk, $sermon->transcript_file_path],
            ['thumbnail', $thumbnailDisk, $sermon->thumbnail_file_path],
        ];

        $metadata = $sermon->thumbnail_metadata;

        if ($metadata instanceof ThumbnailMetadata) {
            $assets[] = ['plain_thumbnail', $thumbnailDisk, $metadata->plainThumbnailPath];
            $assets[] = ['card_thumbnail', $thumbnailDisk, $metadata->cardThumbnailPath];
            $assets[] = ['overlay_thumbnail', $thumbnailDisk, $metadata->overlayThumbnailPath];

            foreach ($metadata->thumbnailCandidates as $candidate) {
                $assets[] = ['candidate_plain', $thumbnailDisk, $candidate['plain_path']];
                $assets[] = ['candidate_card', $thumbnailDisk, $candidate['card_path'] ?? null];
                $assets[] = ['candidate_overlay', $thumbnailDisk, $candidate['overlay_path'] ?? null];
            }
        }

        return array_values(array_filter(
            $assets,
            fn (array $asset): bool => is_string($asset[2]) && $asset[2] !== '',
        ));
    }

    /**
     * @return 'present'|'missing'|'check_error'
     */
    private function auditAsset(Sermon $sermon, string $kind, string $kindDisk, string $path): string
    {
        $this->countsByKind[$kind]['referenced']++;

        $isPrivate = MediaAssetPath::isPrivate($path);

        if ($isPrivate) {
            $this->countsByKind[$kind]['private_referenced']++;
        }

        if ($sermon->content_type === SermonContentType::ChildrensTalk && ! $isPrivate) {
            $this->countsByKind[$kind]['childrens_talk_public']++;
            $this->findings[] = ['sermon_id' => $sermon->id, 'kind' => $kind, 'issue' => 'childrens_talk_public'];
        }

        // Private paths live on the local disk regardless of asset kind; this is
        // the same contract MoveSermonToPrivateStorage commits to.
        $expectedDisk = $isPrivate ? 'local' : $kindDisk;

        try {
            $exists = Storage::disk($expectedDisk)->exists($path);
        } catch (Throwable) {
            $this->countsByKind[$kind]['check_errors']++;
            $this->findings[] = ['sermon_id' => $sermon->id, 'kind' => $kind, 'issue' => 'check_error'];

            return 'check_error';
        }

        if (! $exists) {
            $this->countsByKind[$kind]['missing']++;

            if ($isPrivate) {
                $this->countsByKind[$kind]['private_missing']++;
            }

            $this->findings[] = ['sermon_id' => $sermon->id, 'kind' => $kind, 'issue' => 'missing'];

            return 'missing';
        }

        $this->countsByKind[$kind]['present']++;

        return 'present';
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

    private function hasFailures(): bool
    {
        foreach ($this->countsByKind as $counts) {
            if ($counts['missing'] > 0 || $counts['check_errors'] > 0 || $counts['childrens_talk_public'] > 0) {
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
            'check_errors' => 0,
            'childrens_talk_public' => 0,
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
                'Check errors',
                "Children's-talk public",
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
            (string) $counts['check_errors'],
            (string) $counts['childrens_talk_public'],
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
