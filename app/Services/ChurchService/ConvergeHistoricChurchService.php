<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceConvergenceImportPlan;
use App\Data\ChurchServiceSourceRevision;
use App\Data\HistoricConvergenceBatchAdmission;
use App\Data\HistoricConvergenceBatchResult;
use App\Data\HistoricConvergenceOperationPlan;
use App\Data\HistoricProcessingResultImportPlan;
use App\Enums\ChurchServiceSource;
use App\Enums\HistoricImportClassification;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Models\User;
use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use App\Services\HistoricMedia\HistoricScripturePassageRequirements;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ConvergeHistoricChurchService
{
    /**
     * The apply phases, recorded in the ledger so a stopped batch explains
     * itself without carrying the failure text. Every phase name is a fixed
     * token authored here, never derived from an exception message.
     */
    private const PHASE_PREFLIGHT = 'preflight';

    private const PHASE_LOCK_SERVICE = 'lock_service';

    private const PHASE_PERSIST_MEDIA = 'persist_media_graph';

    private const PHASE_LINK_RUN = 'link_run_to_service';

    private const PHASE_RESOLVE_SECTIONS = 'resolve_portable_sections';

    private const PHASE_INGEST_LIVESTREAM = 'ingest_livestream_revision';

    private const PHASE_PERSIST_CONVERGENCE = 'persist_convergence';

    private const PHASE_LINK_SECTIONS = 'link_sections_and_song_videos';

    private const PHASE_VERIFY_GRAPH = 'verify_media_graph';

    private const PHASE_COMMIT = 'commit';

    public function __construct(
        private readonly HistoricProcessingResultBundle $mediaBundles,
        private readonly ChurchServiceConvergenceBundle $convergenceBundles,
        private readonly HistoricProcessingResultBundleImporter $mediaImporter,
        private readonly ChurchServiceConvergenceBundleImporter $convergenceImporter,
        private readonly HistoricProcessingResultAssetTransfer $assets,
        private readonly HistoricProcessingResultInventory $inventory,
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
        private readonly ?HistoricConvergenceLedger $ledger = null,
        private readonly ?ChurchServiceProposalIdentity $proposalIdentity = null,
        private readonly ?HistoricConvergenceDispatchGuard $dispatchGuard = null,
        private readonly ?HistoricConvergenceAdmission $admission = null,
        private readonly ?HistoricScripturePassageRequirements $scriptureRequirements = null,
    ) {}

    /**
     * Prepare one service without changing the database or destination media.
     * The returned operation plan is the only state an apply is allowed to use.
     *
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     */
    public function prepare(
        array $mediaBundle,
        array $convergenceBundle,
        int $mediaServiceIndex = 0,
        int $convergenceServiceIndex = 0,
        ?string $operationId = null,
        ?string $expiresAt = null,
    ): HistoricConvergenceOperationPlan {
        $preparationStartedAt = hrtime(true);
        $mediaBundle = $this->mediaBundles->validate($mediaBundle);
        $convergenceBundle = $this->convergenceBundles->validate($convergenceBundle);
        $this->assertBundleIdentity($mediaBundle, $convergenceBundle);
        $this->assertServiceIdentity($mediaBundle, $convergenceBundle, $mediaServiceIndex, $convergenceServiceIndex);
        $mediaPayload = $this->servicePayload($mediaBundle, $mediaServiceIndex, 'media');
        $convergencePayload = $this->servicePayload($convergenceBundle, $convergenceServiceIndex, 'convergence');
        $mediaPlan = $this->mediaImporter->prepareService(
            $mediaBundle,
            $mediaServiceIndex,
            $this->historicOperation($operationId),
        );
        $convergencePlan = $this->convergenceImporter->prepareServiceForHistoricImport(
            $convergenceBundle,
            $convergenceServiceIndex,
            $mediaPayload['livestream_source_revision'],
            $mediaPayload['media_graph']['processing_key'],
        );
        $binding = $this->bindingState($mediaPlan, $convergencePlan, $mediaPayload, $convergencePayload);
        $operationId ??= $this->operationId($mediaBundle, $convergenceBundle, $mediaServiceIndex, $convergenceServiceIndex);
        $expiry = $expiresAt === null ? new DateTimeImmutable('+1 hour') : new DateTimeImmutable($expiresAt);
        $summary = [
            ...$this->serviceSummary($binding, $mediaPlan, $convergencePlan),
            'media_index' => $mediaServiceIndex,
            'convergence_index' => $convergenceServiceIndex,
        ];
        $storage = $this->assets->storageIdentity();
        $content = [
            'format' => 'crockenhill-historic-convergence-operation',
            'version' => 1,
            'operation_id' => $operationId,
            'batch_hash' => $mediaBundle['batch_hash'],
            'media_bundle_hash' => $mediaBundle['bundle_hash'],
            'convergence_bundle_hash' => $convergenceBundle['bundle_hash'],
            'processing_fingerprint' => $mediaBundle['processing_fingerprint'],
            'storage' => $storage,
            'deployment_identifier' => $this->deploymentIdentifier(),
            'services' => [$summary],
        ];
        $contentHash = CanonicalJson::hash($content);
        $planHash = CanonicalJson::hash([...$content, 'expires_at' => $expiry->format(DATE_ATOM)]);
        $plan = new HistoricConvergenceOperationPlan(
            operationId: $operationId,
            planHash: $planHash,
            contentHash: $contentHash,
            batchHash: $mediaBundle['batch_hash'],
            mediaBundleHash: $mediaBundle['bundle_hash'],
            convergenceBundleHash: $convergenceBundle['bundle_hash'],
            processingFingerprint: $mediaBundle['processing_fingerprint'],
            storageIdentity: $storage,
            expiresAt: $expiry,
            services: [[
                'media_index' => $mediaServiceIndex,
                'convergence_index' => $convergenceServiceIndex,
                'identity' => "{$mediaPayload['date']}|{$mediaPayload['service']}",
                'media_plan' => $mediaPlan,
                'convergence_plan' => $convergencePlan,
                'binding' => $binding,
                'summary' => $summary,
            ]],
            summary: ['services' => [$summary], 'service_count' => 1],
        );
        $this->ledger()->recordPrepared($plan, $this->elapsedSince($preparationStartedAt));

        return $plan;
    }

    /**
     * Prepare every matching service in deterministic Bundle A order.
     *
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     */
    public function prepareBatch(
        array $mediaBundle,
        array $convergenceBundle,
        ?string $operationId = null,
        ?string $expiresAt = null,
    ): HistoricConvergenceOperationPlan {
        $preparationStartedAt = hrtime(true);
        $mediaBundle = $this->mediaBundles->validate($mediaBundle);
        $convergenceBundle = $this->convergenceBundles->validate($convergenceBundle);
        $this->assertBundleIdentity($mediaBundle, $convergenceBundle);
        $convergenceIndexes = [];

        foreach ($convergenceBundle['services'] as $index => $payload) {
            if (is_array($payload)) {
                $convergenceIndexes["{$payload['date']}|{$payload['service']}"] = $index;
            }
        }

        $services = [];
        $summaries = [];

        foreach ($mediaBundle['services'] as $mediaIndex => $mediaPayload) {
            $identity = "{$mediaPayload['date']}|{$mediaPayload['service']}";
            $convergenceIndex = $convergenceIndexes[$identity] ?? null;

            if (! is_int($convergenceIndex)) {
                throw new RuntimeException("No convergence service matches {$identity}.");
            }

            $mediaPlan = $this->mediaImporter->prepareService(
                $mediaBundle,
                $mediaIndex,
                $this->historicOperation($operationId),
            );
            $convergencePlan = $this->convergenceImporter->prepareServiceForHistoricImport(
                $convergenceBundle,
                $convergenceIndex,
                $mediaPayload['livestream_source_revision'],
                $mediaPayload['media_graph']['processing_key'],
            );
            $convergencePayload = $this->servicePayload($convergenceBundle, $convergenceIndex, 'convergence');
            $binding = $this->bindingState($mediaPlan, $convergencePlan, $mediaPayload, $convergencePayload);
            $summary = [
                ...$this->serviceSummary($binding, $mediaPlan, $convergencePlan),
                'media_index' => $mediaIndex,
                'convergence_index' => $convergenceIndex,
            ];
            $services[] = [
                'media_index' => $mediaIndex,
                'convergence_index' => $convergenceIndex,
                'identity' => $identity,
                'media_plan' => $mediaPlan,
                'convergence_plan' => $convergencePlan,
                'binding' => $binding,
                'summary' => $summary,
            ];
            $summaries[] = $summary;
        }

        if (count($convergenceIndexes) !== count($services)) {
            throw new RuntimeException('Bundle A and Bundle B do not contain the same service identities.');
        }

        $operationId ??= substr(CanonicalJson::hash([
            'batch_hash' => $mediaBundle['batch_hash'],
            'media_bundle_hash' => $mediaBundle['bundle_hash'],
            'convergence_bundle_hash' => $convergenceBundle['bundle_hash'],
            'services' => array_column($summaries, 'identity'),
        ]), 0, 32);
        $expiry = $expiresAt === null ? new DateTimeImmutable('+1 hour') : new DateTimeImmutable($expiresAt);
        $storage = $this->assets->storageIdentity();
        $content = [
            'format' => 'crockenhill-historic-convergence-operation',
            'version' => 1,
            'operation_id' => $operationId,
            'batch_hash' => $mediaBundle['batch_hash'],
            'media_bundle_hash' => $mediaBundle['bundle_hash'],
            'convergence_bundle_hash' => $convergenceBundle['bundle_hash'],
            'processing_fingerprint' => $mediaBundle['processing_fingerprint'],
            'storage' => $storage,
            'deployment_identifier' => $this->deploymentIdentifier(),
            'services' => $summaries,
        ];
        $contentHash = CanonicalJson::hash($content);
        $planHash = CanonicalJson::hash([...$content, 'expires_at' => $expiry->format(DATE_ATOM)]);
        $plan = new HistoricConvergenceOperationPlan(
            operationId: $operationId,
            planHash: $planHash,
            contentHash: $contentHash,
            batchHash: $mediaBundle['batch_hash'],
            mediaBundleHash: $mediaBundle['bundle_hash'],
            convergenceBundleHash: $convergenceBundle['bundle_hash'],
            processingFingerprint: $mediaBundle['processing_fingerprint'],
            storageIdentity: $storage,
            expiresAt: $expiry,
            services: $services,
            summary: ['services' => $summaries, 'service_count' => count($services)],
        );
        $this->ledger()->recordPrepared($plan, $this->elapsedSince($preparationStartedAt));

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     * @return array{church_service: ChurchService, processing_log: MediaProcessingLog, created_assets: list<string>}
     */
    public function execute(
        array $mediaBundle,
        array $convergenceBundle,
        int $mediaServiceIndex = 0,
        int $convergenceServiceIndex = 0,
        ?string $expectedPlanHash = null,
        ?HistoricConvergenceOperationPlan $prepared = null,
    ): array {
        $prepared ??= $this->prepare(
            $mediaBundle,
            $convergenceBundle,
            $mediaServiceIndex,
            $convergenceServiceIndex,
        );

        if ($prepared->isExpired()) {
            throw new RuntimeException('Historic convergence plan has expired; run a new dry run.');
        }

        if ($expectedPlanHash !== null && ! hash_equals($prepared->planHash, $expectedPlanHash)) {
            throw new RuntimeException('Historic convergence plan hash does not match the approved dry run.');
        }

        $revalidated = $this->prepare(
            $mediaBundle,
            $convergenceBundle,
            $mediaServiceIndex,
            $convergenceServiceIndex,
            $prepared->operationId,
            $prepared->expiresAt->format(DATE_ATOM),
        );

        if (! hash_equals($prepared->planHash, $revalidated->planHash)) {
            throw new RuntimeException('Historic convergence plan changed before apply; no records were committed.');
        }

        $servicePlan = $revalidated->services[0] ?? null;

        if (! is_array($servicePlan)) {
            throw new RuntimeException('Historic convergence plan contains no service.');
        }

        $this->assertPlanApplicable($revalidated);

        return Model::withoutEvents(
            fn (): array => $this->applyPreparedService($revalidated, $servicePlan, $mediaBundle, $convergenceBundle),
        );
    }

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     */
    public function executeBatch(
        array $mediaBundle,
        array $convergenceBundle,
        ?string $expectedPlanHash = null,
        bool $resume = false,
        ?string $operationId = null,
        ?string $expiresAt = null,
        ?HistoricConvergenceOperationPlan $prepared = null,
    ): HistoricConvergenceBatchResult {
        $batchStartedAt = hrtime(true);
        $approved = $prepared ?? $this->prepareBatch($mediaBundle, $convergenceBundle, $operationId, $expiresAt);

        if ($approved->isExpired()) {
            throw new RuntimeException('Historic convergence plan has expired; run a new dry run.');
        }

        if ($expectedPlanHash !== null && ! hash_equals($approved->planHash, $expectedPlanHash)) {
            throw new RuntimeException('Historic convergence plan hash does not match the approved dry run.');
        }

        $plan = $this->prepareBatch(
            $mediaBundle,
            $convergenceBundle,
            $approved->operationId,
            $approved->expiresAt->format(DATE_ATOM),
        );

        if (! hash_equals($approved->planHash, $plan->planHash)) {
            throw new RuntimeException('Historic convergence plan changed before apply; no records were committed.');
        }

        /**
         * IC2 re-scopes batch admission from "whole approved corpus applicable or
         * refuse" to "apply every applicable service; report the rest". A service
         * still needing review, still in conflict, or otherwise not yet ready to
         * converge is corpus-completeness residue — not a reason to leave an
         * already-applicable sibling service unwritten. Per-service lock,
         * classification and transaction are exactly as before; only the
         * batch-wide refusal is gone.
         */
        $admission = $this->partitionApplicable($plan);

        foreach ($admission->held as $held) {
            $this->ledger()->recordHeld($plan, $held['identity'], $held['reason']);
        }

        /**
         * "This whole operation re-ran and changed nothing" is a claim about the operation, not
         * about whichever subset of it happened to be applicable this round. Once IC2 allowed a
         * round to hold services, recording the event over `$admission->applicable` alone would
         * have let one already-present service and fifty held ones write a passing no-op rerun
         * into retained closeout evidence. REV-D1 retires this as a *gate*, which is exactly why
         * the surviving record has to keep saying something true.
         */
        $alreadyPresent = HistoricImportClassification::AlreadyPresent->value;
        $isExactNoOp = $admission->held === [] && $admission->applicable !== [] && collect($admission->applicable)->every(
            static fn (array $service): bool => ($service['media_plan'] ?? null) instanceof HistoricProcessingResultImportPlan
                && $service['media_plan']->classification === $alreadyPresent
                && ($service['convergence_plan'] ?? null) instanceof ChurchServiceConvergenceImportPlan
                && $service['convergence_plan']->classification === $alreadyPresent,
        );

        $results = [];

        foreach ($admission->applicable as $servicePlan) {
            $identity = $servicePlan['identity'] ?? null;

            if ($resume && is_string($identity) && $this->ledger()->hasCompleted($plan->operationId, $identity)) {
                $this->assertCompletedServiceStillApplied($servicePlan, $identity);

                continue;
            }

            if (! is_string($identity)) {
                throw new RuntimeException('Historic convergence service plan contains no natural identity.');
            }

            $this->assertServiceAdmission($plan, $identity);
            $results[] = Model::withoutEvents(
                fn (): array => $this->applyPreparedService($plan, $servicePlan, $mediaBundle, $convergenceBundle),
            );
        }

        if ($isExactNoOp) {
            $identities = array_map(
                static fn (array $service): string => (string) $service['identity'],
                $admission->applicable,
            );
            $this->ledger()->recordExactNoOpRerun(
                $plan,
                $this->elapsedSince($batchStartedAt),
                $identities,
            );
        }

        return new HistoricConvergenceBatchResult($results, $admission->held);
    }

    /**
     * A resume re-preflights every service, including the ones the ledger says
     * already applied. Those must now classify as an exact no-op on both halves;
     * anything else means production moved after the ledger recorded them, and
     * skipping would silently leave the batch half-applied.
     *
     * @param  array<string, mixed>  $servicePlan
     */
    private function assertCompletedServiceStillApplied(array $servicePlan, string $identity): void
    {
        $mediaPlan = $servicePlan['media_plan'] ?? null;
        $convergencePlan = $servicePlan['convergence_plan'] ?? null;
        $alreadyPresent = HistoricImportClassification::AlreadyPresent->value;

        if (! $mediaPlan instanceof HistoricProcessingResultImportPlan
            || $mediaPlan->classification !== $alreadyPresent) {
            $classification = $mediaPlan instanceof HistoricProcessingResultImportPlan
                ? $mediaPlan->classification
                : 'invalid';

            throw new RuntimeException(
                "Resumed operation cannot revalidate the completed media result for {$identity}: {$classification}.",
            );
        }

        if (! $convergencePlan instanceof ChurchServiceConvergenceImportPlan
            || $convergencePlan->classification !== $alreadyPresent) {
            $classification = $convergencePlan instanceof ChurchServiceConvergenceImportPlan
                ? $convergencePlan->classification
                : 'invalid';

            throw new RuntimeException(
                "Resumed operation cannot revalidate the completed convergence result for {$identity}: {$classification}.",
            );
        }
    }

    /**
     * The single service `execute()` prepares must classify as applicable
     * before it writes anything. The accepted sets below are exactly what
     * HistoricProcessingResultBundleImporter::persistPreparedService() and
     * ChurchServiceConvergenceBundleImporter::persistPreparedService() accept —
     * a preflight that admitted more would still abort, but only after the
     * write had already happened.
     *
     * IC2 stopped `executeBatch()` calling this: a batch reports an
     * inapplicable service as held residue instead of refusing every other
     * service alongside it. See {@see self::partitionApplicable()}, which
     * shares the same accepted-classification predicates.
     */
    private function assertPlanApplicable(HistoricConvergenceOperationPlan $plan): void
    {
        $scriptureKeys = [];

        foreach ($plan->services as $servicePlan) {
            $identity = $servicePlan['identity'] ?? 'unknown service';
            $mediaPlan = $servicePlan['media_plan'] ?? null;
            $convergencePlan = $servicePlan['convergence_plan'] ?? null;

            if (! $mediaPlan instanceof HistoricProcessingResultImportPlan) {
                throw new RuntimeException("Historic media preflight is invalid for {$identity}.");
            }

            if (! $this->mediaApplicableForApply($mediaPlan)) {
                throw new RuntimeException(
                    "Historic media preflight is {$mediaPlan->classification} for {$identity}.",
                );
            }

            if (
                ! $convergencePlan instanceof ChurchServiceConvergenceImportPlan
                || ! $this->convergenceApplicableForApply($convergencePlan)
            ) {
                $classification = $convergencePlan instanceof ChurchServiceConvergenceImportPlan
                    ? $convergencePlan->classification
                    : 'invalid';

                throw new RuntimeException("Church-service convergence preflight is {$classification} for {$identity}.");
            }

            foreach ($this->scriptureRequirements()->forService($mediaPlan->service) as $key) {
                $scriptureKeys[$this->scriptureRequirements()->identity($key)] = $key;
            }
        }

        /**
         * Decision D3: the destination relinks Scripture Passages by natural key
         * and holds no text of its own until enrichment has run. Resolving that
         * per publication inside the apply would throw partway through a service
         * that had already written its run, sections and earlier publications,
         * so the whole batch's identities are settled here — before the first
         * write, with every missing key named at once so one enrichment pass can
         * close them all.
         */
        $this->scriptureRequirements()->assertAvailable(
            array_values($scriptureKeys),
            "Historic convergence operation {$plan->operationId}",
        );
    }

    /**
     * IC2's batch admission: every service in the plan classified as
     * applicable, plus everything else reported as held with a reason —
     * never thrown. Scripture settlement (invariant #8) stays a hard,
     * fail-closed precondition, but scoped to only the services this call is
     * about to write, so a held service's missing enrichment never blocks an
     * unrelated applicable one.
     */
    private function partitionApplicable(HistoricConvergenceOperationPlan $plan): HistoricConvergenceBatchAdmission
    {
        $applicable = [];
        $held = [];
        $scriptureKeys = [];

        foreach ($plan->services as $servicePlan) {
            $identity = is_string($servicePlan['identity'] ?? null) ? $servicePlan['identity'] : 'unknown service';
            $mediaPlan = $servicePlan['media_plan'] ?? null;
            $convergencePlan = $servicePlan['convergence_plan'] ?? null;

            if (! $mediaPlan instanceof HistoricProcessingResultImportPlan
                || ! $convergencePlan instanceof ChurchServiceConvergenceImportPlan) {
                $held[] = ['identity' => $identity, 'reason' => 'invalid_preflight'];

                continue;
            }

            if (! $this->mediaApplicableForApply($mediaPlan)) {
                $held[] = ['identity' => $identity, 'reason' => "media_{$mediaPlan->classification}"];

                continue;
            }

            if (! $this->convergenceApplicableForApply($convergencePlan)) {
                $held[] = ['identity' => $identity, 'reason' => "convergence_{$convergencePlan->classification}"];

                continue;
            }

            $applicable[] = $servicePlan;

            foreach ($this->scriptureRequirements()->forService($mediaPlan->service) as $key) {
                $scriptureKeys[$this->scriptureRequirements()->identity($key)] = $key;
            }
        }

        /**
         * Decision D3, scoped to the services this admission actually applies:
         * a held service's Scripture Passages are never resolved this round, so
         * they never belong in the set the pre-apply enrichment pass is asked
         * to close before the first write.
         */
        $this->scriptureRequirements()->assertAvailable(
            array_values($scriptureKeys),
            "Historic convergence operation {$plan->operationId}",
        );

        return new HistoricConvergenceBatchAdmission($applicable, $held);
    }

    private function mediaApplicableForApply(HistoricProcessingResultImportPlan $mediaPlan): bool
    {
        return $mediaPlan->classification === HistoricImportClassification::Create->value
            || (
                $mediaPlan->classification === HistoricImportClassification::AlreadyPresent->value
                && $mediaPlan->existingProcessingLogId !== null
            );
    }

    private function convergenceApplicableForApply(ChurchServiceConvergenceImportPlan $convergencePlan): bool
    {
        return in_array($convergencePlan->classification, [
            HistoricImportClassification::AlreadyPresent->value,
            HistoricImportClassification::SafeEnrichment->value,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     * @param  array<string, mixed>  $servicePlan
     * @return array{church_service: ChurchService, processing_log: MediaProcessingLog, created_assets: list<string>}
     */
    private function applyPreparedService(
        HistoricConvergenceOperationPlan $operationPlan,
        array $servicePlan,
        array $mediaBundle,
        array $convergenceBundle,
    ): array {
        $mediaIndex = $servicePlan['media_index'] ?? null;
        $convergenceIndex = $servicePlan['convergence_index'] ?? null;
        $mediaPlan = $servicePlan['media_plan'] ?? null;
        $convergencePlan = $servicePlan['convergence_plan'] ?? null;

        if (! is_int($mediaIndex) || ! is_int($convergenceIndex)) {
            throw new RuntimeException('Historic convergence plan contains invalid bundle indexes.');
        }

        if (! $mediaPlan instanceof HistoricProcessingResultImportPlan
            || ! $convergencePlan instanceof ChurchServiceConvergenceImportPlan) {
            throw new RuntimeException('Historic convergence plan contains invalid importer plans.');
        }

        $mediaPayload = $this->servicePayload($mediaBundle, $mediaIndex, 'media');
        $summary = $servicePlan['summary'] ?? null;

        if (! is_array($summary)
            || ! is_string($summary['media_plan_hash'] ?? null)
            || ! is_string($summary['convergence_plan_hash'] ?? null)
            || ! hash_equals($summary['media_plan_hash'], $mediaPlan->planHash)
            || ! hash_equals($summary['convergence_plan_hash'], $convergencePlan->planHash)
        ) {
            throw new RuntimeException('Historic convergence service plan hashes are not bound to the approved operation.');
        }

        $mediaPlanHash = $summary['media_plan_hash'];
        $convergencePlanHash = $summary['convergence_plan_hash'];

        $createdAssets = [];
        $phase = self::PHASE_PREFLIGHT;
        $applyStartedAt = hrtime(true);
        $mediaPersistSeconds = null;
        $this->ledger()->recordStarted($operationPlan, $servicePlan);

        try {
            /**
             * Both closures capture by reference deliberately. An arrow
             * function would copy `$createdAssets` and `$phase` into its own
             * scope, so the catch below would compensate an empty asset list
             * and record the wrong phase.
             */
            $result = $this->dispatchGuard()->guard(function () use (
                $operationPlan,
                $servicePlan,
                $mediaBundle,
                $convergenceBundle,
                $mediaPlan,
                $mediaPayload,
                $convergencePlan,
                $mediaPlanHash,
                $convergencePlanHash,
                &$createdAssets,
                &$phase,
                &$mediaPersistSeconds,
            ): array {
                return DB::transaction(function () use (
                    $operationPlan,
                    $servicePlan,
                    $mediaBundle,
                    $convergenceBundle,
                    $mediaPlan,
                    $mediaPayload,
                    $convergencePlan,
                    $mediaPlanHash,
                    $convergencePlanHash,
                    &$createdAssets,
                    &$phase,
                    &$mediaPersistSeconds,
                ): array {
                    $phase = self::PHASE_LOCK_SERVICE;
                    $service = $this->lockService($mediaPayload);
                    $this->assertServiceAdmission(
                        $operationPlan,
                        (string) ($servicePlan['identity'] ?? ''),
                    );
                    [$mediaPlan, $convergencePlan] = $this->reprepareAndRebindUnderLock(
                        $operationPlan,
                        $servicePlan,
                        $mediaBundle,
                        $convergenceBundle,
                    );
                    $phase = self::PHASE_PERSIST_MEDIA;
                    $mediaPersistStartedAt = hrtime(true);
                    $mediaResult = $this->mediaImporter->persistPreparedService($mediaPlan, $mediaPlanHash);
                    $mediaPersistSeconds = $this->elapsedSince($mediaPersistStartedAt);
                    $createdAssets = $mediaResult['created_assets'];
                    $run = $mediaResult['processing_log'];
                    $phase = self::PHASE_LINK_RUN;
                    $this->linkRun($run, $service);
                    $phase = self::PHASE_RESOLVE_SECTIONS;
                    $sections = $this->sectionsByPortableKey($run, $mediaPayload['media_graph']['sections']);
                    $phase = self::PHASE_INGEST_LIVESTREAM;
                    $this->ingestLivestreamRevision(
                        $service,
                        $run,
                        $sections,
                        $mediaPayload['livestream_source_revision'],
                    );

                    $convergencePlan = new ChurchServiceConvergenceImportPlan(
                        classification: $convergencePlan->classification,
                        reason: $convergencePlan->reason,
                        planHash: $convergencePlan->planHash,
                        bundleHash: $convergencePlan->bundleHash,
                        churchService: $service->fresh(['sourceRecords.assertions']) ?? $service,
                        reviewer: $convergencePlan->reviewer,
                        servicePayload: $convergencePlan->servicePayload,
                    );

                    if ($convergencePlan->churchService->isNot($service)) {
                        throw new RuntimeException('Convergence plan resolved a different church service.');
                    }

                    $phase = self::PHASE_PERSIST_CONVERGENCE;
                    $service = $this->convergenceImporter->persistPreparedService(
                        $convergencePlan,
                        $convergencePlanHash,
                        false,
                    );
                    $phase = self::PHASE_LINK_SECTIONS;
                    $this->linkSectionsAndSongVideos($service, $sections);
                    $phase = self::PHASE_VERIFY_GRAPH;
                    $this->verifyMediaGraph($run, $mediaPayload['media_graph']);
                    $phase = self::PHASE_COMMIT;

                    return [
                        'church_service' => $service->fresh(['items.song']) ?? $service,
                        'processing_log' => $run->fresh() ?? $run,
                        'created_assets' => $createdAssets,
                    ];
                });
            });
        } catch (Throwable $exception) {
            $this->assets->cleanup($createdAssets);
            if (! $exception instanceof HistoricConvergenceBatchSplit) {
                $this->ledger()->recordFailed(
                    $operationPlan,
                    $phase,
                    $exception->getMessage(),
                    is_string($servicePlan['identity'] ?? null) ? $servicePlan['identity'] : null,
                    // Recorded after cleanup, deliberately: §15.2's rollback reserve
                    // has to cover compensating the assets, not just the failure.
                    $this->elapsedSince($applyStartedAt),
                );
            }

            throw $exception;
        }

        $this->ledger()->recordCompleted(
            $operationPlan,
            $servicePlan,
            $this->elapsedSince($applyStartedAt),
            ...$this->assetAccounting($mediaPlan, $createdAssets, $mediaPersistSeconds),
        );

        return $result;
    }

    /**
     * Re-run both importer preflights after the natural-identity lock is held.
     * The approved hashes are the rebind contract; a source or production
     * change observed after the batch preflight therefore stops before any
     * asset or row mutation.
     *
     * @param  array<string, mixed>  $servicePlan
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     * @return array{0: HistoricProcessingResultImportPlan, 1: ChurchServiceConvergenceImportPlan}
     */
    private function reprepareAndRebindUnderLock(
        HistoricConvergenceOperationPlan $operationPlan,
        array $servicePlan,
        array $mediaBundle,
        array $convergenceBundle,
    ): array {
        $mediaIndex = $servicePlan['media_index'] ?? null;
        $convergenceIndex = $servicePlan['convergence_index'] ?? null;

        if (! is_int($mediaIndex) || ! is_int($convergenceIndex)) {
            throw new RuntimeException('Historic convergence plan contains invalid bundle indexes.');
        }

        $reprepared = $this->prepare(
            $mediaBundle,
            $convergenceBundle,
            $mediaIndex,
            $convergenceIndex,
            $operationPlan->operationId,
            $operationPlan->expiresAt->format(DATE_ATOM),
        );
        $freshServicePlan = $reprepared->services[0] ?? null;

        if (! is_array($freshServicePlan)
            || ! ($freshServicePlan['media_plan'] ?? null) instanceof HistoricProcessingResultImportPlan
            || ! ($freshServicePlan['convergence_plan'] ?? null) instanceof ChurchServiceConvergenceImportPlan
        ) {
            throw new RuntimeException('Historic convergence re-preflight produced an invalid service binding.');
        }

        $approvedSummary = $servicePlan['summary'] ?? null;
        $freshSummary = $freshServicePlan['summary'] ?? null;

        if (! is_array($approvedSummary) || ! is_array($freshSummary)
            || ! is_string($approvedSummary['media_plan_hash'] ?? null)
            || ! is_string($approvedSummary['convergence_plan_hash'] ?? null)
            || ! is_string($freshSummary['media_plan_hash'] ?? null)
            || ! is_string($freshSummary['convergence_plan_hash'] ?? null)
            || ! hash_equals($approvedSummary['media_plan_hash'], $freshSummary['media_plan_hash'])
            || ! hash_equals($approvedSummary['convergence_plan_hash'], $freshSummary['convergence_plan_hash'])
        ) {
            throw new RuntimeException('Historic convergence service binding changed while acquiring the natural-identity lock.');
        }

        return [$freshServicePlan['media_plan'], $freshServicePlan['convergence_plan']];
    }

    private function assertServiceAdmission(HistoricConvergenceOperationPlan $plan, string $identity): void
    {
        $decision = $this->admission()->decide($plan->operationId, $plan->expiresAt);

        if ($decision['admitted']) {
            return;
        }

        $this->ledger()->recordSplit($plan, $identity, [
            'reason' => $decision['reason'],
            'deadline' => $plan->expiresAt->format(DATE_ATOM),
            'remaining_seconds' => $decision['remaining_seconds'],
            'reserve_seconds' => $decision['reserve_seconds'],
        ]);

        throw new HistoricConvergenceBatchSplit("Historic convergence batch split before {$identity}: accepted deadline reserve exhausted.");
    }

    private function admission(): HistoricConvergenceAdmission
    {
        return $this->admission ?? app(HistoricConvergenceAdmission::class);
    }

    /**
     * Bytes written by this attempt and the time it took, for §13.4's asset-copy
     * throughput.
     *
     * Two things a reader should not have to infer. The byte total is
     * role-expanded: one physical file carrying N roles becomes N production
     * copies, because destinations are allocated per role, so the unique asset
     * total would understate what was actually written. And the seconds are the
     * whole media-persistence phase, which contains the copy alongside its
     * database writes — so the derived throughput is a *floor*, never a peak.
     * A floor is the correct side to be wrong on when it is sizing a window.
     *
     * An `already_present` service copies nothing, and reporting its plan's
     * bytes as though they had been written would inflate the throughput of
     * every no-op rerun.
     *
     * @param  list<string>  $createdAssets
     * @return array{0: int|null, 1: float|null}
     */
    private function assetAccounting(
        HistoricProcessingResultImportPlan $mediaPlan,
        array $createdAssets,
        ?float $mediaPersistSeconds,
    ): array {
        if ($createdAssets === []) {
            return [0, null];
        }

        $bytes = 0;

        foreach ($this->assets->expand($mediaPlan->assets) as $asset) {
            $bytes += $asset['size'];
        }

        return [$bytes, $mediaPersistSeconds];
    }

    /** Monotonic elapsed seconds; hrtime is immune to a clock step mid-operation. */
    private function elapsedSince(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000_000, 6);
    }

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     */
    private function assertBundleIdentity(array $mediaBundle, array $convergenceBundle): void
    {
        if (
            $convergenceBundle['media_bundle_hash'] !== $mediaBundle['bundle_hash']
            || $convergenceBundle['batch_hash'] !== $mediaBundle['batch_hash']
            || CanonicalJson::hash($convergenceBundle['processing_fingerprint'])
                !== CanonicalJson::hash($mediaBundle['processing_fingerprint'])
        ) {
            throw new RuntimeException('Historic media and reviewed convergence bundles do not describe the same service result.');
        }
    }

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     */
    private function operationId(
        array $mediaBundle,
        array $convergenceBundle,
        int $mediaIndex,
        int $convergenceIndex,
    ): string {
        return substr(CanonicalJson::hash([
            'batch_hash' => $mediaBundle['batch_hash'],
            'media_bundle_hash' => $mediaBundle['bundle_hash'],
            'convergence_bundle_hash' => $convergenceBundle['bundle_hash'],
            'media_index' => $mediaIndex,
            'convergence_index' => $convergenceIndex,
        ]), 0, 32);
    }

    /**
     * @param  array<string, mixed>  $mediaPayload
     * @param  array<string, mixed>  $convergencePayload
     * @return array<string, mixed>
     */
    private function bindingState(
        HistoricProcessingResultImportPlan $mediaPlan,
        ChurchServiceConvergenceImportPlan $convergencePlan,
        array $mediaPayload,
        array $convergencePayload,
    ): array {
        $service = $convergencePlan->churchService->fresh([
            'sourceRecords',
            'mergeProposals.triggerSourceRecord',
            'reviewSessions',
        ]) ?? $convergencePlan->churchService;
        $processingKey = $mediaPayload['media_graph']['processing_key'];
        $existingRun = MediaProcessingLog::query()
            ->where('processing_id', $processingKey)
            ->first();
        $existingGraphHash = null;

        if ($existingRun instanceof MediaProcessingLog) {
            try {
                $existingGraphHash = $this->inventory->build($existingRun)['logical_hash'];
            } catch (Throwable) {
                $existingGraphHash = 'invalid-live-graph';
            }
        }

        $sourceRecords = $service->sourceRecords
            ->map(fn ($record): array => [
                'source' => $record->source->value,
                'source_key' => $record->source_key,
                'revision_hash' => $record->revision_hash,
                'input_hash' => $record->input_hash,
                'payload_complete' => $record->payload_complete,
            ])
            ->sortBy(fn (array $record): string => implode('|', [
                $record['source'],
                $record['source_key'],
                $record['revision_hash'],
            ]))
            ->values()
            ->all();
        $proposals = $service->mergeProposals
            ->filter(fn ($proposal): bool => $proposal->status->value !== 'stale')
            ->map(fn ($proposal): array => [
                'identity' => $this->proposalIdentity()->for($proposal),
                'status' => $proposal->status->value,
                'base_canonical_revision' => $proposal->base_canonical_revision,
                'base_canonical_hash' => $proposal->base_canonical_hash,
            ])
            ->sortBy('identity')
            ->values()
            ->all();
        $reviews = $service->reviewSessions
            ->map(fn ($review): array => [
                'review_uuid' => $review->review_uuid,
                'base_canonical_revision' => $review->base_canonical_revision,
                'base_canonical_hash' => $review->base_canonical_hash,
                'resulting_canonical_revision' => $review->resulting_canonical_revision,
                'resulting_canonical_hash' => $review->resulting_canonical_hash,
                'completed' => $review->completed_at !== null,
            ])
            ->sortBy('review_uuid')
            ->values()
            ->all();

        return [
            'service_identity' => [$mediaPayload['date'], $mediaPayload['service']],
            'media' => [
                'processing_key' => $processingKey,
                'classification' => $mediaPlan->classification,
                'reason' => $mediaPlan->reason,
                'live_graph_hash' => $existingGraphHash,
                'live_row_revision' => $existingRun?->updated_at?->toISOString(),
                'asset_manifest_hash' => CanonicalJson::hash($mediaPlan->assets),
            ],
            'convergence' => [
                'classification' => $convergencePlan->classification,
                'reason' => $convergencePlan->reason,
                'reviewer' => $convergencePlan->reviewer instanceof User
                    ? [
                        'email_hash' => hash('sha256', mb_strtolower(trim($convergencePlan->reviewer->email))),
                        'updated_at' => $convergencePlan->reviewer->updated_at?->toISOString(),
                    ]
                    : null,
                'canonical_revision' => $service->canonical_revision,
                'canonical_hash' => $service->canonical_hash,
                'updated_at' => $service->updated_at?->toISOString(),
                'evidence_set_hash' => $convergencePayload['evidence_set_hash'],
                'pre_review_hash' => $convergencePayload['pre_review_hash'],
                'source_records' => $sourceRecords,
                'proposals' => $proposals,
                'reviews' => $reviews,
            ],
            'target_classification' => [
                'media' => $mediaPlan->classification,
                'convergence' => $convergencePlan->classification,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    private function serviceSummary(
        array $binding,
        HistoricProcessingResultImportPlan $mediaPlan,
        ChurchServiceConvergenceImportPlan $convergencePlan,
    ): array {
        return [
            'identity' => implode('|', $binding['service_identity']),
            'binding_hash' => CanonicalJson::hash($binding),
            'media_plan_hash' => $mediaPlan->planHash,
            'media_classification' => $mediaPlan->classification,
            'convergence_plan_hash' => $convergencePlan->planHash,
            'convergence_classification' => $convergencePlan->classification,
            'asset_manifest_hash' => CanonicalJson::hash($mediaPlan->assets),
            'asset_count' => count($mediaPlan->assets),
        ];
    }

    private function ledger(): HistoricConvergenceLedger
    {
        return $this->ledger ?? app(HistoricConvergenceLedger::class);
    }

    private function historicOperation(?string $operationId): ?HistoricImportOperation
    {
        if ($operationId === null) {
            return null;
        }

        return HistoricImportOperation::query()->where('operation_id', $operationId)->first();
    }

    private function proposalIdentity(): ChurchServiceProposalIdentity
    {
        return $this->proposalIdentity ?? app(ChurchServiceProposalIdentity::class);
    }

    private function dispatchGuard(): HistoricConvergenceDispatchGuard
    {
        return $this->dispatchGuard ?? app(HistoricConvergenceDispatchGuard::class);
    }

    private function scriptureRequirements(): HistoricScripturePassageRequirements
    {
        return $this->scriptureRequirements ?? app(HistoricScripturePassageRequirements::class);
    }

    private function deploymentIdentifier(): string
    {
        $identifier = config('app.release_identifier');

        if (! is_string($identifier) || trim($identifier) === '') {
            throw new RuntimeException('Historic convergence requires a configured deployment identifier.');
        }

        return trim($identifier);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function servicePayload(array $bundle, int $index, string $label): array
    {
        $payload = $bundle['services'][$index] ?? null;

        if (! is_array($payload)) {
            throw new RuntimeException("The {$label} service index {$index} does not exist.");
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $mediaBundle
     * @param  array<string, mixed>  $convergenceBundle
     */
    private function assertServiceIdentity(
        array $mediaBundle,
        array $convergenceBundle,
        int $mediaIndex,
        int $convergenceIndex,
    ): void {
        $media = $this->servicePayload($mediaBundle, $mediaIndex, 'media');
        $convergence = $this->servicePayload($convergenceBundle, $convergenceIndex, 'convergence');

        if (
            $media['date'] !== $convergence['date']
            || $media['service'] !== $convergence['service']
            || $media['evidence_set_hash'] !== $convergence['evidence_set_hash']
            || $media['pre_review_hash'] !== $convergence['pre_review_hash']
        ) {
            throw new RuntimeException('Historic media and reviewed convergence bundles do not identify the same service result.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function lockService(array $payload): ChurchService
    {
        $services = ChurchService::query()
            ->whereDate('date', $payload['date'])
            ->where('service', $payload['service'])
            ->lockForUpdate()
            ->get();

        if ($services->count() !== 1) {
            throw new RuntimeException('Convergence requires exactly one production service for the natural identity.');
        }

        return $services->firstOrFail();
    }

    private function linkRun(MediaProcessingLog $run, ChurchService $service): void
    {
        if ($run->church_service_id !== null && $run->church_service_id !== $service->id) {
            throw new RuntimeException('Historic processing run is linked to a different church service.');
        }

        if ($run->church_service_id === null) {
            $run->forceFill(['church_service_id' => $service->id])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, ServiceSection>
     */
    private function sectionsByPortableKey(MediaProcessingLog $run, array $payloads): array
    {
        $sectionsByOrder = $run->serviceSections()->get()->keyBy('section_order');
        $sections = [];

        foreach ($payloads as $payload) {
            $key = $payload['section_key'] ?? null;
            $order = $payload['section_order'] ?? null;
            $section = is_int($order) ? $sectionsByOrder->get($order) : null;

            if (! is_string($key) || ! $section instanceof ServiceSection) {
                throw new RuntimeException('Imported media graph does not reproduce its portable section identities.');
            }

            $sections[$key] = $section;
        }

        if (count($sections) !== $sectionsByOrder->count()) {
            throw new RuntimeException('Imported media graph contains unexpected service sections.');
        }

        return $sections;
    }

    /**
     * @param  array<string, ServiceSection>  $sections
     * @param  array<string, mixed>  $payload
     */
    private function ingestLivestreamRevision(
        ChurchService $service,
        MediaProcessingLog $run,
        array $sections,
        array $payload,
    ): void {
        $assertions = array_values(array_map(function (array $assertion) use ($run, $sections): array {
            $canonicalKey = $assertion['song_canonical_key'] ?? null;
            $assertion['song_id'] = is_string($canonicalKey)
                ? Song::query()->where('canonical_key', $canonicalKey)->value('id')
                : null;

            if (is_string($canonicalKey) && $assertion['song_id'] === null) {
                throw new RuntimeException("Livestream assertion song does not exist: {$canonicalKey}.");
            }

            $metadata = is_array($assertion['metadata'] ?? null) ? $assertion['metadata'] : [];
            $sectionKey = $metadata['livestream_service_section_key'] ?? null;
            unset($metadata['livestream_service_section_key']);

            if (is_string($sectionKey)) {
                $section = $sections[$sectionKey] ?? null;

                if (! $section instanceof ServiceSection) {
                    throw new RuntimeException("Livestream assertion references unknown section {$sectionKey}.");
                }

                $metadata['livestream_processing_id'] = $run->processing_id;
                $metadata['livestream_service_section_id'] = $section->id;
            }

            $assertion['metadata'] = $metadata === [] ? null : $metadata;

            return $assertion;
        }, $payload['assertions']));

        $result = $this->ingestSourceRevision->execute(
            $service,
            new ChurchServiceSourceRevision(
                source: ChurchServiceSource::Livestream,
                sourceKey: $payload['source_key'],
                inputHash: $payload['input_hash'],
                assertions: $assertions,
                processingFingerprint: $payload['processing_fingerprint'],
                serviceContent: $payload['service_content'],
                batchHash: $payload['batch_hash'],
                payloadComplete: $payload['payload_complete'],
                capturedAt: Carbon::parse($payload['captured_at']),
            ),
            dispatchEvents: false,
        );

        if (! hash_equals($payload['revision_hash'], $result->sourceRecord->revision_hash)) {
            throw new RuntimeException('Remapped Livestream revision differs from the reviewed source revision.');
        }
    }

    /** @param array<string, ServiceSection> $sections */
    private function linkSectionsAndSongVideos(ChurchService $service, array $sections): void
    {
        $items = $service->items()->get()->keyBy('livestream_service_section_id');

        foreach ($sections as $section) {
            $item = $items->get($section->id);

            if ($item instanceof ChurchServiceItem) {
                $section->forceFill(['church_service_item_id' => $item->id])->save();
            }
        }

        SongVideo::query()
            ->whereIn('service_section_id', collect($sections)->pluck('id'))
            ->update(['church_service_id' => $service->id]);
    }

    /** @param array<string, mixed> $graph */
    private function verifyMediaGraph(MediaProcessingLog $run, array $graph): void
    {
        $actual = $this->inventory->build($run->fresh() ?? $run);

        if (! hash_equals($graph['logical_hash'], $actual['logical_hash'])) {
            throw new RuntimeException('Linked historic media graph differs from the imported portable graph.');
        }

        $unlinkedSongVideos = SongVideo::query()
            ->whereHas('serviceSection', fn ($query) => $query->where('media_processing_log_id', $run->id))
            ->whereNull('church_service_id')
            ->exists();

        if ($unlinkedSongVideos) {
            throw new RuntimeException('Imported song videos were not linked to the church service.');
        }
    }
}
