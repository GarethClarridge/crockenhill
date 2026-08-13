<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HistoricImportArtifactKind;
use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\HistoricImportReleaseAttempt;
use App\Models\Sermon;
use App\Models\SongUsageReport;
use App\Models\SongVideo;
use App\Services\Import\HistoricImportArtifactWriter;
use App\Services\Import\HistoricImportJournal;
use App\Services\Import\HistoricSermonPublicationService;
use App\Services\Import\HistoricSermonReleaseAuthorisation;
use App\Support\CanonicalJson;
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
                'Records: %d sermons, %d song videos, %d song usage reports',
                $authorisation['declared_counts']['sermons'],
                $authorisation['declared_counts']['song_videos'],
                $authorisation['declared_counts']['song_usage_reports'],
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
                'song_usage_report_ids' => $authorisation['song_usage_report_ids'],
            ]);

            /**
             * The verified authorisation travels with the batch so HIR7's
             * attempt is bound to the exact signed authority and membership it
             * was claimed for, not merely to a list of ids.
             */
            $released = $publication->releaseRecords(
                $operation,
                $authorisation['sermon_ids'],
                $authorisation['song_video_ids'],
                $authorisation['song_usage_report_ids'],
                $authorisation,
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
                    'released_song_usage_report_ids' => array_map(
                        static fn (SongUsageReport $report): int => $report->id,
                        $released['song_usage_reports'],
                    ),
                    /**
                     * HIR7: which attempt owned this batch, and what it owns at
                     * each destination. A later recovery exercise verifies its
                     * object claims against this rather than against a count.
                     */
                    'release_ledger' => $this->releaseLedger($operation, $authorisation),
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
                'Released %d sermons, %d song videos and %d song usage reports; %d sermons, %d song videos and '
                .'%d song usage reports remain quarantined.',
                count($released['sermons']),
                count($released['song_videos']),
                count($released['song_usage_reports']),
                $remaining['sermons'],
                $remaining['song_videos'],
                $remaining['song_usage_reports'],
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * HIR7's ownership record for this batch, as membership rather than counts.
     *
     * The attempt id and its per-destination claims are what a later recovery
     * exercise checks its object receipts against; a total could not say which
     * public path this batch became the owner of.
     *
     * @param  array<string, mixed>  $authorisation
     * @return array<string, mixed>
     */
    private function releaseLedger(HistoricImportOperation $operation, array $authorisation): array
    {
        $attempt = HistoricImportReleaseAttempt::query()
            ->where('historic_import_operation_id', $operation->id)
            ->where('authorisation_id', $authorisation['authorisation_id'])
            ->latest('id')
            ->first();

        if (! $attempt instanceof HistoricImportReleaseAttempt) {
            return ['attempt_id' => null, 'assets' => [], 'assets_sha256' => CanonicalJson::hash([])];
        }

        $assets = [];

        foreach ($attempt->assets()->orderBy('destination_identity')->get() as $asset) {
            $assets[] = [
                'destination_identity' => $asset->destination_identity,
                'destination_disk' => $asset->destination_disk,
                'destination_path' => $asset->destination_path,
                'state' => $asset->state,
                'create_result' => $asset->create_result,
                'size' => $asset->size,
                'sha256' => $asset->sha256,
                'provider_receipt' => $asset->provider_receipt,
                'provider_version_id' => $asset->provider_version_id,
            ];
        }

        return [
            'attempt_id' => $attempt->attempt_id,
            'attempt_state' => $attempt->state,
            'authorisation_hash' => $attempt->authorisation_hash,
            'membership_hash' => $attempt->membership_hash,
            'assets' => $assets,
            'assets_sha256' => CanonicalJson::hash($assets),
        ];
    }

    /**
     * What this operation still holds back, so a partial editorial batch leaves
     * the operator an exact number rather than an assumption.
     *
     * @return array{sermons: int, song_videos: int, song_usage_reports: int}
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
            'song_usage_reports' => SongUsageReport::query()
                ->where('historic_import_operation_id', $operation->id)
                ->where('publication_state', SermonPublicationState::Quarantined)
                ->count(),
        ];
    }
}
