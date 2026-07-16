<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ThumbnailMetadata;
use App\Enums\SermonContentType;
use App\Models\Sermon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuditSermonAssetsCommand extends Command
{
    private const string PRIVATE_PREFIX = 'private/';

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

    /** @var list<array{sermon_id: int, kind: string, issue: string}> */
    private array $findings = [];

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
            ];
        }

        $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');
        $transcriptDisk = (string) config('media-processing.storage.transcript_disk', $sermonDisk);
        $thumbnailDisk = (string) config('thumbnail-generation.storage.disk', 'public');

        $sermons = Sermon::query()
            ->select(['id', 'content_type', 'audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path', 'thumbnail_metadata'])
            ->orderBy('id')
            ->cursor();

        foreach ($sermons as $sermon) {
            foreach ($this->referencedAssets($sermon, $sermonDisk, $transcriptDisk, $thumbnailDisk) as [$kind, $kindDisk, $path]) {
                $this->auditAsset($sermon, $kind, $kindDisk, $path);
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

    private function auditAsset(Sermon $sermon, string $kind, string $kindDisk, string $path): void
    {
        $this->countsByKind[$kind]['referenced']++;

        $isPrivate = str_starts_with($path, self::PRIVATE_PREFIX);

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

            return;
        }

        if (! $exists) {
            $this->countsByKind[$kind]['missing']++;
            $this->findings[] = ['sermon_id' => $sermon->id, 'kind' => $kind, 'issue' => 'missing'];

            return;
        }

        $this->countsByKind[$kind]['present']++;
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
        $totals = ['referenced' => 0, 'present' => 0, 'missing' => 0, 'check_errors' => 0, 'childrens_talk_public' => 0];

        foreach ($this->countsByKind as $kind => $counts) {
            $rows[] = [
                $kind,
                (string) $counts['referenced'],
                (string) $counts['present'],
                (string) $counts['missing'],
                (string) $counts['check_errors'],
                (string) $counts['childrens_talk_public'],
            ];

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $counts[$key];
            }
        }

        $rows[] = [
            'total',
            (string) $totals['referenced'],
            (string) $totals['present'],
            (string) $totals['missing'],
            (string) $totals['check_errors'],
            (string) $totals['childrens_talk_public'],
        ];

        $this->table(
            ['Asset kind', 'Referenced', 'Present', 'Missing', 'Check errors', "Children's-talk public"],
            $rows,
        );
    }

    /** @return array{kinds: array<string, array<string, int>>, findings?: list<array{sermon_id: int, kind: string, issue: string}>} */
    private function jsonReport(): array
    {
        $report = ['kinds' => $this->countsByKind];

        if ((bool) $this->option('details')) {
            $report['findings'] = $this->findings;
        }

        return $report;
    }
}
