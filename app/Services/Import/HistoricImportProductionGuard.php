<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\ImportIngressLock;
use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Enforces the G8 boundary the plan header previously only described.
 *
 * The header of docs/plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md
 * forbids canonical OoS/OpenLP archive imports, historic-video dispatch and
 * Bundle A/B persistence "until Gate G8". Read literally that also forbade
 * §13.5 steps 3-4, because staging Email evidence is only reachable through
 * `oos:import-archive --import` — there is no code path from the corpus to a
 * persisted `ChurchServiceSourceRevision` that avoids it. A prohibition that
 * blocks the only route to its own exit gate cannot have meant that, so the
 * scope is production, and this class is where saying so becomes enforceable.
 *
 * Two things follow from that framing, both deliberate:
 *
 * - **Outside production the guard is silent.** Local and rehearsal databases
 *   are where the corpus is meant to be staged, projected and re-projected
 *   repeatedly. Gating that work would gate G5.
 * - **In production it fails closed.** An unset approval is not read as
 *   permission, for the same reason `ChurchServiceProposalCensusGate` refuses an
 *   unset corpus size: the absence of a decision is not a decision.
 *
 * Storage isolation remains the per-batch staging root's job
 * ({@see HistoricStagingGuard}). Production identity is resolved independently
 * of APP_ENV: a configured production target fingerprint activates this guard
 * even when a shell incorrectly labels the application as local.
 */
class HistoricImportProductionGuard
{
    public function __construct(
        private readonly Application $application,
    ) {}

    /**
     * The operator-facing reason this operation must not run, or null when it may.
     *
     * Returning a message rather than throwing lets each command report it the
     * way it already reports every other refusal, instead of introducing a
     * second failure idiom into commands that are careful about their exit codes.
     *
     * @param  string  $operation  The invocation being guarded, as an operator would type it.
     */
    public function refusalFor(string $operation, ?string $operationId = null): ?string
    {
        if (! $this->guardsCurrentEnvironment()) {
            return null;
        }

        if ($this->publicServiceCutoff() === null) {
            return implode(PHP_EOL, [
                "Refusing to run {$operation} against production: the public service cutoff is not configured.",
                'Set CHURCH_SERVICES_PUBLIC_FROM to the reviewed lower publication boundary before the import window.',
            ]);
        }

        if (! $this->hasPrivateHistoricQuarantine()) {
            return implode(PHP_EOL, [
                "Refusing to run {$operation} against production: historic sermon quarantine storage is not private and isolated.",
                'HISTORIC_QUARANTINE_DISK must resolve to a private disk distinct from SERMON_STORAGE_DISK.',
            ]);
        }

        $approvalPath = config('church.historic_corpus.production_import_approval');

        if (! is_string($approvalPath) || trim($approvalPath) === '') {
            return $this->describeRefusal($operation);
        }

        try {
            $target = app(HistoricImportTargetFingerprint::class)->hash();
            $approval = app(HistoricImportApprovalManifest::class)->authorize(
                trim($approvalPath),
                $operation,
                $target,
                (string) config('media-processing.historic_import.evidence_signing_key'),
            );

            if ($operationId !== null && $approval['operation_id'] !== $operationId) {
                throw new RuntimeException('The command operation id does not match the approved immutable operation.');
            }

            $lock = app(ImportIngressGate::class)->active();

            if (! $lock instanceof ImportIngressLock
                || $lock->operation_id !== $approval['operation_id']
                || ! is_array($lock->queue_pause_accounting)
                || ! array_key_exists('supervisors_to_pause', $lock->queue_pause_accounting)) {
                throw new RuntimeException('The approved operation does not hold the measured ingress/queue freeze.');
            }

            app(HistoricImportMutationFreeze::class)->authorize((string) $approval['operation_id']);

            return null;
        } catch (Throwable $exception) {
            return "Refusing to run {$operation} against production: {$exception->getMessage()}";
        }
    }

    /**
     * The recorded G8 approval, or null when production imports are unapproved.
     *
     * Whitespace-only is null: a config value of " " is a mistake, and reading it
     * as an approval would make the fail-closed default defeatable by a typo.
     */
    public function approvedOperationId(): ?string
    {
        $path = config('church.historic_corpus.production_import_approval');

        if (! is_string($path) || trim($path) === '' || ! is_file(trim($path))) {
            return null;
        }

        try {
            $approval = json_decode((string) file_get_contents(trim($path)), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $operationId = is_array($approval) ? ($approval['operation_id'] ?? null) : null;

        return is_string($operationId) && trim($operationId) !== '' ? trim($operationId) : null;
    }

    public function guardsCurrentEnvironment(): bool
    {
        if ($this->application->isProduction()) {
            return true;
        }

        $productionTarget = config('church.historic_corpus.production_target_fingerprint');

        if (! is_string($productionTarget) || preg_match('/\A[a-f0-9]{64}\z/', $productionTarget) !== 1) {
            return false;
        }

        try {
            return hash_equals($productionTarget, app(HistoricImportTargetFingerprint::class)->hash());
        } catch (Throwable) {
            return true;
        }
    }

    public function publicServiceCutoff(): ?string
    {
        $cutoff = config('church.services.public_from');

        if (! is_string($cutoff) || trim($cutoff) === '') {
            return null;
        }

        $cutoff = trim($cutoff);

        try {
            $resolved = Carbon::createFromFormat('!Y-m-d', $cutoff);
        } catch (Throwable) {
            return null;
        }

        return $resolved instanceof Carbon && $resolved->format('Y-m-d') === $cutoff
            ? $cutoff
            : null;
    }

    public function hasPrivateHistoricQuarantine(): bool
    {
        $quarantine = config('media-processing.storage.historic_quarantine_disk');
        $public = config('media-processing.storage.sermon_disk');

        if (! is_string($quarantine) || trim($quarantine) === '' || $quarantine === $public) {
            return false;
        }

        return config("filesystems.disks.{$quarantine}.visibility") === 'private';
    }

    public function describeRefusal(string $operation): string
    {
        return implode(PHP_EOL, [
            "Refusing to run {$operation} against production: no approved G8 import operation is recorded.",
            'Canonical historic imports are a production-once operation (§5, G8). Stage, project and',
            're-project the corpus in a rehearsal database instead — that is what §13.5 steps 3-4 are,',
            'and this guard is silent there.',
            'If this *is* the approved production window, set HISTORIC_IMPORT_PRODUCTION_APPROVAL to the',
            'signed approval artifact bound to the operation, target, release, commands and freeze.',
        ]);
    }
}
