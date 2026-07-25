<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ThumbnailMetadata;
use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use App\Support\MediaAssetPath;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Moves children's-talk assets off the local `private/` disk and onto the
 * ordinary sermon disks, under ordinary sermon keys.
 *
 * The `private/` prefix was never the access gate — that is
 * `SermonExposurePolicy::canAccessChildrensCorner()`, driven by
 * `CHILDRENS_TALKS_PUBLIC`, and nothing here touches it. What the prefix did buy
 * was storage on a disk production never persisted, so every talk's media was
 * destroyed at the next deploy.
 *
 * Copying and deleting are deliberately separate invocations: run without
 * `--delete-source` first, confirm with `audit:sermon-assets`, and only then
 * re-run with `--delete-source` to reclaim the private copies. Until that second
 * pass, the private copies are a byte-identical rollback.
 */
class PubliciseChildrensTalkAssetsCommand extends Command
{
    protected $signature = 'media:publicise-childrens-talk-assets
        {--talk=* : Limit to these sermon IDs}
        {--apply : Queue the moves (default: dry-run — nothing is queued)}
        {--delete-source : Delete each private source once its committed copy re-verifies. Only valid after a copy-only pass}';

    protected $description = 'Move children\'s-talk assets from private local storage onto the ordinary sermon disks';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $deleteSource = (bool) $this->option('delete-source');
        /** @var list<string> $talkIds */
        $talkIds = (array) $this->option('talk');

        return $deleteSource
            ? $this->runDeletePass($talkIds, $apply)
            : $this->runCopyPass($talkIds, $apply);
    }

    /**
     * @param  list<string>  $talkIds
     */
    private function runCopyPass(array $talkIds, bool $apply): int
    {
        $talks = $this->childrensTalks($talkIds)
            ->filter(fn (Sermon $talk): bool => $this->privateAssetKinds($talk) !== []);

        if ($talks->isEmpty()) {
            $this->info('No children\'s talks reference private assets. Nothing to copy.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? '<fg=yellow>APPLYING</> — copying the following talks\' assets onto the sermon disks:'
            : '<fg=cyan>DRY RUN</> — the following talks\' assets would be copied (re-run with --apply to queue):');
        $this->newLine();

        $this->renderTalkTable($talks);

        if ($apply) {
            foreach ($talks as $talk) {
                MoveSermonToPrivateStorage::dispatch($talk->id, toPrivate: false, deleteSource: false);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d talk(s), %d private asset(s). Sources are left in place.',
            $apply ? 'Queued' : 'Would queue',
            $talks->count(),
            $talks->sum(fn (Sermon $talk): int => count($this->privateAssetKinds($talk))),
        ));

        $this->newLine();
        $this->comment($apply
            ? 'Once the queue drains, confirm with `audit:sermon-assets`, then re-run with --delete-source.'
            : 'No jobs queued. Re-run with --apply to queue the copies.');

        return self::SUCCESS;
    }

    /**
     * The delete pass verifies each already-committed public copy and then
     * removes its private source. A talk that still references a private path
     * has not been copied yet, and letting it through would collapse the copy
     * and the delete into a single run — the one sequence that has no rollback.
     *
     * @param  list<string>  $talkIds
     */
    private function runDeletePass(array $talkIds, bool $apply): int
    {
        $talks = $this->childrensTalks($talkIds);
        $uncopied = $talks->filter(fn (Sermon $talk): bool => $this->privateAssetKinds($talk) !== []);

        if ($uncopied->isNotEmpty()) {
            $this->error(sprintf(
                '%d talk(s) still reference private assets, so the copy pass has not finished for them.',
                $uncopied->count(),
            ));
            $this->line('Run this command without --delete-source first, then audit, then return here.');

            return self::FAILURE;
        }

        if ($talks->isEmpty()) {
            $this->info('No children\'s talks found. Nothing to delete.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? '<fg=yellow>APPLYING</> — deleting verified private sources for the following talks:'
            : '<fg=cyan>DRY RUN</> — private sources would be deleted for the following talks (re-run with --apply to queue):');
        $this->newLine();

        $this->renderTalkTable($talks);

        if ($apply) {
            foreach ($talks as $talk) {
                MoveSermonToPrivateStorage::dispatch($talk->id, toPrivate: false, deleteSource: true);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d talk(s). Each source is deleted only after its committed copy re-verifies; talks with nothing left to delete are a no-op.',
            $apply ? 'Queued' : 'Would queue',
            $talks->count(),
        ));

        if (! $apply) {
            $this->newLine();
            $this->comment('No jobs queued. Re-run with --apply to queue the deletions.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Sermon>  $talks
     */
    private function renderTalkTable(Collection $talks): void
    {
        $this->table(
            ['sermon', 'slug', 'date', 'private assets', 'kinds'],
            $talks->map(function (Sermon $talk): array {
                $kinds = $this->privateAssetKinds($talk);

                return [
                    (string) $talk->id,
                    (string) $talk->slug,
                    $talk->date->toDateString(),
                    (string) count($kinds),
                    $kinds === [] ? '—' : implode(', ', array_unique($kinds)),
                ];
            })->all(),
        );
    }

    /**
     * @param  list<string>  $talkIds
     * @return Collection<int, Sermon>
     */
    private function childrensTalks(array $talkIds): Collection
    {
        return Sermon::query()
            ->where('content_type', SermonContentType::ChildrensTalk)
            ->when($talkIds !== [], fn ($query) => $query->whereIn('id', $talkIds))
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * The asset kinds this talk still references under `private/`. Mirrors the
     * set `MoveSermonToPrivateStorage` moves, so the report and the job agree on
     * what work there is. Paths are deliberately never returned — this command
     * runs on production and its output must not hint at storage keys.
     *
     * @return list<string>
     */
    private function privateAssetKinds(Sermon $talk): array
    {
        $assets = [
            'audio' => $talk->audio_file_path,
            'video' => $talk->video_file_path,
            'transcript' => $talk->transcript_file_path,
            'thumbnail' => $talk->thumbnail_file_path,
        ];

        $metadata = $talk->thumbnail_metadata;

        if ($metadata instanceof ThumbnailMetadata) {
            $assets['plain_thumbnail'] = $metadata->plainThumbnailPath;
            $assets['card_thumbnail'] = $metadata->cardThumbnailPath;
            $assets['overlay_thumbnail'] = $metadata->overlayThumbnailPath;
        }

        $kinds = [];

        foreach ($assets as $kind => $path) {
            if (MediaAssetPath::isPrivate($path)) {
                $kinds[] = $kind;
            }
        }

        if ($metadata instanceof ThumbnailMetadata) {
            foreach ($metadata->thumbnailCandidates as $candidate) {
                foreach (['plain_path', 'card_path', 'overlay_path'] as $key) {
                    if (MediaAssetPath::isPrivate($candidate[$key] ?? null)) {
                        $kinds[] = 'candidate';
                    }
                }
            }
        }

        return $kinds;
    }
}
