<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HistoricImportArtifactKind;
use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\Sermon;
use App\Models\SongVideo;
use App\Services\Import\HistoricImportArtifactWriter;
use App\Services\Import\HistoricImportJournal;
use App\Services\Import\HistoricSermonPublicationService;
use App\Services\Import\HistoricSermonReleaseAuthorisation;
use Illuminate\Console\Command;
use Throwable;

/**
 * The operator entry point F29 requires: "a separately authorised release
 * exercise proving that an approved item becomes public without re-importing or
 * changing provenance".
 *
 * Release is not a mode of the import commands on purpose. It runs after exact
 * closeout, under its own signed authority, against an enumerated batch — the
 * plan's "controlled editorial batch with owner, rollback and observation
 * period, not a side effect of import" (§F.6).
 *
 * Delete alongside the rest of the historic import tooling once the acceptance
 * and rollback-retention windows have expired.
 */
class ReleaseHistoricImportBatchCommand extends Command
{
    protected $signature = 'historic-import:release-batch
        {authorisation : Signed release authorisation JSON naming the exact records to publish}
        {--dry-run : Verify the authorisation and report the batch without publishing anything}';

    protected $description = 'Publish an authorised batch of quarantined historic sermons and song recordings';

    public function handle(
        HistoricSermonReleaseAuthorisation $authorisations,
        HistoricSermonPublicationService $publication,
        HistoricImportArtifactWriter $artifacts,
        HistoricImportJournal $journal,
    ): int {
        try {
            $authorisation = $authorisations->authorize(
                (string) $this->argument('authorisation'),
                (string) config('media-processing.historic_import.evidence_signing_key'),
            );
            $operation = $authorisations->operationFor($authorisation['operation_id']);

            $this->line("Operation: {$operation->operation_id}");
            $this->line("Batch: {$authorisation['batch_key']} ({$authorisation['authorisation_id']})");
            $this->line(sprintf(
                'Records: %d sermons, %d song videos',
                $authorisation['declared_counts']['sermons'],
                $authorisation['declared_counts']['song_videos'],
            ));
            $this->line("Release owner: {$authorisation['roles']['release_owner']}");
            $this->line("Rollback owner: {$authorisation['roles']['rollback_owner']} until {$authorisation['observation_ends_at']}");

            if ($this->option('dry-run')) {
                $this->info('Release authorisation is valid. No record was published.');

                return self::SUCCESS;
            }

            $journal->append($operation, 'release_batch_started', [
                'authorisation_id' => $authorisation['authorisation_id'],
                'batch_key' => $authorisation['batch_key'],
                'sermon_ids' => $authorisation['sermon_ids'],
                'song_video_ids' => $authorisation['song_video_ids'],
            ]);

            $released = $publication->releaseRecords(
                $operation,
                $authorisation['sermon_ids'],
                $authorisation['song_video_ids'],
            );
            $remaining = $this->remainingQuarantine($operation);
            $artifact = $artifacts->writeJson(
                operation: $operation,
                artifactKey: "release-batch-{$authorisation['batch_key']}",
                kind: HistoricImportArtifactKind::AcceptanceReport,
                relativePath: "release/{$authorisation['batch_key']}.json.enc",
                payload: [
                    'format' => 'crockenhill-historic-release-batch',
                    'version' => 1,
                    'operation_id' => $operation->operation_id,
                    'target_fingerprint' => $operation->target_fingerprint,
                    'authorisation_id' => $authorisation['authorisation_id'],
                    'batch_key' => $authorisation['batch_key'],
                    'roles' => $authorisation['roles'],
                    'observation_ends_at' => $authorisation['observation_ends_at'],
                    'released_sermon_ids' => array_map(static fn (Sermon $sermon): int => $sermon->id, $released['sermons']),
                    'released_song_video_ids' => array_map(static fn (SongVideo $video): int => $video->id, $released['song_videos']),
                    'remaining_quarantined' => $remaining,
                ],
                redact: false,
            );
            $journal->append($operation, 'release_batch_completed', [
                'authorisation_id' => $authorisation['authorisation_id'],
                'batch_key' => $authorisation['batch_key'],
                'artifact_key' => $artifact->artifact_key,
                'artifact_sha256' => $artifact->sha256,
                'remaining_quarantined' => $remaining,
            ]);

            $this->info(sprintf(
                'Released %d sermons and %d song videos; %d sermons and %d song videos remain quarantined.',
                count($released['sermons']),
                count($released['song_videos']),
                $remaining['sermons'],
                $remaining['song_videos'],
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * What this operation still holds back, so a partial editorial batch leaves
     * the operator an exact number rather than an assumption.
     *
     * @return array{sermons: int, song_videos: int}
     */
    private function remainingQuarantine(HistoricImportOperation $operation): array
    {
        return [
            'sermons' => Sermon::query()
                ->where('historic_import_operation_id', $operation->id)
                ->where('publication_state', SermonPublicationState::Quarantined)
                ->count(),
            'song_videos' => SongVideo::query()
                ->where('historic_import_operation_id', $operation->id)
                ->where('publication_state', SermonPublicationState::Quarantined)
                ->count(),
        ];
    }
}
