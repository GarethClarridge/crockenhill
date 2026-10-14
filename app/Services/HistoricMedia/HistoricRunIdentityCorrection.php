<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\ChurchService\ProcessingRunSupersessionService;
use App\Services\Processing\MediaProcessingIdentityResolver;
use App\Services\Processing\ProcessingNotificationRouter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Correct the service a historic run was imported under, when the archive filed
 * its recording on the wrong date — and, when the right service already has a
 * poorer copy of the same recording, set that copy aside for this one.
 *
 * Ruled 2026-10-14 for the three deferred duplicate pairs (873/1045, 969/1307,
 * 1296/1297): one performance recorded once and imported twice, the better master
 * misdated. The real service keeps its identity; the better file becomes its
 * recording.
 *
 * A run's service is its extracted date and slot ({@see MediaProcessingIdentityResolver}),
 * so correcting those is the whole move: the next re-detection projects the run
 * onto the real service. A re-cut finds its sermon by run, not by date
 * (`SubmitToProcessing`), so this run's own sermon rows are re-dated in place and
 * keep refreshing. The poorer copy's rows on the real service are withdrawn (their
 * bytes kept under `superseded/`), the copy is excluded as a duplicate recording
 * naming this run, and supersession, which never lets an excluded run win, settles
 * the service. The service only this run describes, which the misdating
 * manufactured, is removed.
 *
 * The manifest item key is not changed: it records where the file came from.
 *
 * Deletion trigger: Delete once the historic import operation is closed out and
 * no further identity corrections can be recorded against it.
 */
class HistoricRunIdentityCorrection
{
    public function __construct(
        private readonly HistoricRunExclusion $exclusion,
        private readonly HistoricSermonWithdrawal $sermonWithdrawal,
        private readonly ProcessingRunSupersessionService $supersession,
        private readonly MediaProcessingIdentityResolver $identityResolver,
        private readonly ProcessingNotificationRouter $notificationRouter,
    ) {}

    /**
     * Report what the correction would do, without writing anything or moving a byte.
     *
     * @return array{
     *     run: MediaProcessingLog,
     *     item_key: string,
     *     from: array{date: string, service: string},
     *     to: array{date: string, service: string},
     *     already_corrected: bool,
     *     duplicate: ?MediaProcessingLog,
     *     duplicate_operation: ?HistoricImportOperation,
     *     duplicate_exclusion: list<array<string, mixed>>,
     *     target_service: ?ChurchService,
     *     removes_service: ?ChurchService,
     *     keeps_service_because: ?string,
     *     redated_sermons: list<Sermon>,
     *     withdrawn_sermons: list<array{sermon: Sermon, assets: list<array{role:string, disk:string, path:string, bytes:int}>}>
     * }
     */
    public function inspect(
        HistoricImportOperation $operation,
        string $processingId,
        string $date,
        string $service,
        ?string $duplicateProcessingId,
    ): array {
        $to = $this->target($date, $service);
        $run = $this->operationRun($operation, $processingId);
        $record = $run->identityCorrectionRecord();
        $current = $this->identityResolver->resolve($run);
        $from = $record['from'] ?? [
            'date' => $current['date'] ?? '(none)',
            'service' => $current['service']->value ?? '(none)',
        ];

        $alreadyCorrected = $record !== null
            && ($record['to'] ?? null) === $to
            && $this->identityMatches($run, $to);

        if (! $alreadyCorrected) {
            $this->guardCorrectable($run, $record, $to);
        }

        $duplicate = $duplicateProcessingId === null
            ? null
            : $this->duplicateRun($run, $duplicateProcessingId, $to);

        $duplicateOperation = $duplicate instanceof MediaProcessingLog
            ? HistoricImportOperation::query()->findOrFail($duplicate->historic_import_operation_id)
            : null;

        $duplicateExclusion = $duplicate instanceof MediaProcessingLog
            && $duplicateOperation instanceof HistoricImportOperation
            && ! $duplicate->isExcluded()
            ? $this->exclusion->inspect(
                $duplicateOperation,
                [$duplicate->processing_id],
                MediaProcessingLog::EXCLUSION_REASON_DUPLICATE_RECORDING,
                $run->processing_id,
            )
            : [];

        ['service' => $ownService, 'refusal' => $keepsServiceBecause] = $alreadyCorrected
            ? ['service' => null, 'refusal' => null]
            : $this->exclusion->serviceOnlyThisRunDescribes($run);

        // Never the service the run is moving to, whatever describes it.
        if ($ownService instanceof ChurchService
            && $ownService->date->toDateString() === $to['date']
            && $ownService->service->value === $to['service']) {
            $ownService = null;
        }

        $redatedSermons = $alreadyCorrected ? [] : $this->sermonsToRedate($run, $to);
        $withdrawnSermons = $alreadyCorrected ? [] : array_map(
            fn (Sermon $sermon): array => [
                'sermon' => $sermon,
                'assets' => $this->sermonWithdrawal->assetsFor($sermon),
            ],
            $this->sermonsToWithdraw($duplicate, $to),
        );

        foreach ($withdrawnSermons as $withdrawn) {
            $this->sermonWithdrawal->guardWithdrawable($withdrawn['sermon']);
        }

        if (! $alreadyCorrected) {
            $this->guardNoOtherSermonHolds($run, $duplicate, $redatedSermons, $to);
        }

        return [
            'run' => $run,
            'item_key' => (string) (data_get($run->processing_metadata?->toArray(), 'historic_import.manifest_item_key') ?? '(unknown)'),
            'from' => $from,
            'to' => $to,
            'already_corrected' => $alreadyCorrected,
            'duplicate' => $duplicate,
            'duplicate_operation' => $duplicateOperation,
            'duplicate_exclusion' => $duplicateExclusion,
            'target_service' => ChurchService::query()
                ->whereDate('date', $to['date'])
                ->where('service', $to['service'])
                ->first(),
            'removes_service' => $ownService,
            'keeps_service_because' => $keepsServiceBecause,
            'redated_sermons' => $redatedSermons,
            'withdrawn_sermons' => $withdrawnSermons,
        ];
    }

    /**
     * Apply an inspected correction. Assets move before the database changes and
     * are moved back if the transaction fails, as for a retirement.
     *
     * @param  array<string, mixed>  $entry  as returned by {@see inspect()}
     * @return array{corrected: bool, sermons_withdrawn: int, assets_relocated: int}
     */
    public function apply(HistoricImportOperation $operation, array $entry, string $note): array
    {
        if (trim($note) === '') {
            throw new RuntimeException('An identity correction must carry the operator note that justifies it.');
        }

        if ($entry['already_corrected']) {
            return ['corrected' => false, 'sermons_withdrawn' => 0, 'assets_relocated' => 0];
        }

        /** @var MediaProcessingLog $run */
        $run = $entry['run'];
        $moved = [];

        try {
            foreach ($entry['withdrawn_sermons'] as $withdrawn) {
                $moved[$withdrawn['sermon']->id] = $this->sermonWithdrawal->relocate($withdrawn['sermon'], $operation, $withdrawn['assets']);
            }

            DB::transaction(function () use ($operation, $entry, $note, $run, $moved): void {
                if ($entry['duplicate_exclusion'] !== []) {
                    $this->exclusion->apply(
                        $entry['duplicate_operation'],
                        $entry['duplicate_exclusion'],
                        MediaProcessingLog::EXCLUSION_REASON_DUPLICATE_RECORDING,
                        $note,
                    );
                }

                $run->putIdentityCorrection([
                    'recorded_by' => 'operator',
                    'note' => $note,
                    'manifest_item_key' => $entry['item_key'],
                    'from' => $entry['from'],
                    'to' => $entry['to'],
                    'duplicate_processing_id' => $entry['duplicate']?->processing_id,
                    'removed_service' => $entry['removes_service'] instanceof ChurchService ? [
                        'id' => $entry['removes_service']->id,
                        'date' => $entry['removes_service']->date->toDateString(),
                        'service' => $entry['removes_service']->service->value,
                        'items' => $entry['removes_service']->items()->count(),
                    ] : null,
                    'kept_service_because' => $entry['keeps_service_because'],
                    'redated_sermons' => array_map(static fn (Sermon $sermon): array => [
                        'id' => $sermon->id,
                        'from' => $sermon->date->toDateString().' '.self::serviceValue($sermon),
                    ], $entry['redated_sermons']),
                    'withdrawn_sermons' => array_map(static fn (array $withdrawn): array => [
                        'id' => $withdrawn['sermon']->id,
                        'slug' => $withdrawn['sermon']->slug,
                        'title' => $withdrawn['sermon']->title,
                        'livestream_processing_id' => $withdrawn['sermon']->livestream_processing_id,
                        'publication_state' => $withdrawn['sermon']->publication_state->value,
                        'assets' => $moved[$withdrawn['sermon']->id] ?? [],
                    ], $entry['withdrawn_sermons']),
                ]);

                foreach ($entry['withdrawn_sermons'] as $withdrawn) {
                    $withdrawn['sermon']->delete();
                }

                foreach ($entry['redated_sermons'] as $sermon) {
                    $sermon->forceFill(['date' => $entry['to']['date'], 'service' => $entry['to']['service']])->save();
                }

                // The foreign key clears the run's service link, and the cascade
                // takes the items and source records the misdating manufactured.
                $entry['removes_service']?->delete();

                $run->refresh()->forceFill([
                    'extracted_date' => $entry['to']['date'],
                    'extracted_service' => $entry['to']['service'],
                    'church_service_id' => $entry['target_service']?->id,
                ])->save();

                if ($entry['target_service'] instanceof ChurchService) {
                    $this->supersession->reconcile($entry['target_service']);
                }

                $this->notificationRouter->suppressIfHistoric(
                    $run->fresh() ?? $run,
                    'identity_corrected',
                    'warning',
                    [
                        'reason' => $note,
                        'manifest_item_key' => $entry['item_key'],
                        'operation_id' => $operation->operation_id,
                        'from' => $entry['from'],
                        'to' => $entry['to'],
                    ],
                );
            });
        } catch (Throwable $exception) {
            foreach ($moved as $assets) {
                $this->sermonWithdrawal->rollBack($assets);
            }

            throw $exception;
        }

        foreach ($entry['withdrawn_sermons'] as $withdrawn) {
            $this->sermonWithdrawal->writeInventory(
                $entry['duplicate_operation'] ?? $operation,
                $entry['duplicate'] ?? $run,
                $withdrawn['sermon'],
                $moved[$withdrawn['sermon']->id] ?? [],
                'identity-correction.json',
                ['withdrawn_at' => now()->toIso8601String(), 'corrected_to' => $entry['to'], 'note' => $note],
            );
        }

        return [
            'corrected' => true,
            'sermons_withdrawn' => count($entry['withdrawn_sermons']),
            'assets_relocated' => array_sum(array_map('count', $moved)),
        ];
    }

    /** @return array{date: string, service: string} */
    private function target(string $date, string $service): array
    {
        $parsedDate = $this->identityResolver->parseDate($date);
        $parsedService = $this->identityResolver->parseService($service);

        if ($parsedDate === null) {
            throw new RuntimeException("--date [{$date}] is not a Y-m-d date.");
        }

        if (! $parsedService instanceof SermonService) {
            throw new RuntimeException("--service [{$service}] is not a service slot.");
        }

        return ['date' => $parsedDate, 'service' => $parsedService->value];
    }

    private function operationRun(HistoricImportOperation $operation, string $processingId): MediaProcessingLog
    {
        $run = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

        if (! $run instanceof MediaProcessingLog) {
            throw new RuntimeException("No processing run exists for [{$processingId}].");
        }

        if ($run->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException("Run [{$processingId}] does not belong to operation [{$operation->operation_id}].");
        }

        // Moving live work is a race: its jobs still hold the run and will keep
        // writing to the service and sermon this is about to withdraw.
        if (in_array($run->status, [ProcessingStatus::Pending, ProcessingStatus::Started, ProcessingStatus::Processing], true)) {
            throw new RuntimeException("Run [{$processingId}] is still in flight ({$run->status->value}); stop it first.");
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @param  array{date: string, service: string}  $to
     */
    private function guardCorrectable(MediaProcessingLog $run, ?array $record, array $to): void
    {
        if ($run->isExcluded()) {
            throw new RuntimeException("Run [{$run->processing_id}] is excluded ({$run->exclusionReason()}); an excluded run cannot be the kept copy.");
        }

        if ($record !== null) {
            throw new RuntimeException("Run [{$run->processing_id}] was already corrected to a different identity; review that correction first.");
        }

        if ($this->identityMatches($run, $to)) {
            throw new RuntimeException("Run [{$run->processing_id}] is already {$to['date']} {$to['service']}; there is nothing to correct.");
        }
    }

    /** @param  array{date: string, service: string}  $to */
    private function duplicateRun(
        MediaProcessingLog $run,
        string $processingId,
        array $to,
    ): MediaProcessingLog {
        if ($processingId === $run->processing_id) {
            throw new RuntimeException("Run [{$processingId}] cannot be both the kept run and its duplicate.");
        }

        // The poorer copy may have come in with an earlier operation than this run.
        $operationId = MediaProcessingLog::query()->where('processing_id', $processingId)->value('historic_import_operation_id');
        $duplicateOperation = $operationId === null ? null : HistoricImportOperation::query()->find($operationId);

        if (! $duplicateOperation instanceof HistoricImportOperation) {
            throw new RuntimeException("Duplicate [{$processingId}] is not a historic run.");
        }

        $duplicate = $this->operationRun($duplicateOperation, $processingId);

        if (! $this->identityMatches($duplicate, $to)) {
            $identity = $this->identityResolver->resolve($duplicate);

            throw new RuntimeException(sprintf(
                'Duplicate [%s] is %s %s, not the corrected %s %s; only a copy already on the real service can be set aside for this run.',
                $processingId,
                $identity['date'] ?? '(no date)',
                $identity['service']->value ?? '(no service)',
                $to['date'],
                $to['service'],
            ));
        }

        if ($duplicate->isExcluded()
            && ($duplicate->exclusionReason() !== MediaProcessingLog::EXCLUSION_REASON_DUPLICATE_RECORDING
                || ($duplicate->exclusionEvidence()['duplicates_processing_id'] ?? null) !== $run->processing_id)) {
            throw new RuntimeException("Duplicate [{$processingId}] is already excluded for another reason ({$duplicate->exclusionReason()}).");
        }

        return $duplicate;
    }

    /** @param  array{date: string, service: string}  $identity */
    private function identityMatches(MediaProcessingLog $run, array $identity): bool
    {
        return $this->identityResolver->matchesService($run, $identity['date'], SermonService::from($identity['service']));
    }

    /**
     * This run's sermon rows (talks included) not yet on the corrected identity. A
     * re-cut finds them by run, so they move with it rather than being replaced.
     *
     * @param  array{date: string, service: string}  $to
     * @return list<Sermon>
     */
    private function sermonsToRedate(MediaProcessingLog $run, array $to): array
    {
        return array_values($this->sermonsOf($run)
            ->reject(static fn (Sermon $sermon): bool => self::sermonIsOn($sermon, $to))
            ->all());
    }

    /**
     * The duplicate's sermon rows on the real service: the poorer copy's cut, which
     * the kept run's re-dated rows replace.
     *
     * @param  array{date: string, service: string}  $to
     * @return list<Sermon>
     */
    private function sermonsToWithdraw(?MediaProcessingLog $duplicate, array $to): array
    {
        if (! $duplicate instanceof MediaProcessingLog) {
            return [];
        }

        return array_values($this->sermonsOf($duplicate)
            ->filter(static fn (Sermon $sermon): bool => self::sermonIsOn($sermon, $to))
            ->all());
    }

    /**
     * Refuse a correction that would leave two rows of one kind on the real
     * service: a row there that neither run owns must be dealt with first.
     *
     * @param  list<Sermon>  $redatedSermons
     * @param  array{date: string, service: string}  $to
     */
    private function guardNoOtherSermonHolds(MediaProcessingLog $run, ?MediaProcessingLog $duplicate, array $redatedSermons, array $to): void
    {
        $owned = $this->sermonsOf($run)->modelKeys();
        $duplicateOwned = $duplicate instanceof MediaProcessingLog ? $this->sermonsOf($duplicate)->modelKeys() : [];

        foreach ($redatedSermons as $sermon) {
            $occupant = Sermon::query()
                ->whereDate('date', $to['date'])
                ->where('service', $to['service'])
                ->where('content_type', $sermon->content_type)
                ->whereKeyNot([...$owned, ...$duplicateOwned])
                ->first();

            if ($occupant instanceof Sermon) {
                throw new RuntimeException(sprintf(
                    'Sermon [%d] already holds %s %s and belongs to neither run; name its run as --duplicate or resolve it first.',
                    $occupant->id,
                    $to['date'],
                    $to['service'],
                ));
            }
        }
    }

    /** @return Collection<int, Sermon> */
    private function sermonsOf(MediaProcessingLog $run): Collection
    {
        return Sermon::query()
            ->where(fn ($query) => $query
                ->where('livestream_processing_id', $run->processing_id)
                ->when($run->sermon_id !== null, fn ($query) => $query->orWhere('id', $run->sermon_id)))
            ->orderBy('id')
            ->get();
    }

    /** @param  array{date: string, service: string}  $identity */
    private static function sermonIsOn(Sermon $sermon, array $identity): bool
    {
        return $sermon->date->toDateString() === $identity['date'] && self::serviceValue($sermon) === $identity['service'];
    }

    private static function serviceValue(Sermon $sermon): string
    {
        return $sermon->service instanceof SermonService ? $sermon->service->value : (string) $sermon->service;
    }
}
