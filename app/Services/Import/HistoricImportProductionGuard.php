<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Enforces the RG-B production boundary the plan header previously only described.
 *
 * Originally written against the one-shot G8 window: the header of
 * docs/archived-plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md
 * forbade canonical OoS/OpenLP archive imports, historic-video dispatch and
 * Bundle A/B persistence "until Gate G8". Read literally that also forbade
 * §13.5 steps 3-4, because staging Email evidence is only reachable through
 * `oos:import-archive --import` — there is no code path from the corpus to a
 * persisted `ChurchServiceSourceRevision` that avoids it. A prohibition that
 * blocks the only route to its own exit gate cannot have meant that, so the
 * scope is production, and this class is where saying so becomes enforceable.
 *
 * **IC2 re-scoped the boundary itself from one-shot GO to per-round approval**
 * (docs/plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md §6, §7.1
 * RG-B): a named round operation, bound to the round's approved manifest and
 * reviewed plan hashes, replaces the single G8 window. The ingress lock and
 * mutation-freeze machinery this class used to require are retained as
 * optional per-round tooling — REV-D3 is explicit that "there is no freeze
 * requirement" — rather than removed, because they stay useful for an
 * operator who chooses a brief pause. What no longer gates approval is the
 * deploy/config freeze ceremony, the abort-threshold window budget and the
 * external watchboard {@see HistoricImportApprovalManifest} used to require:
 * see its docblock for the retired schema and what replaced it.
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
 * of APP_ENV: a configured production database anchor activates this guard even
 * when a shell incorrectly labels the application as local.
 *
 * **HIR1 changed what "the production target" means here.** The guard used to
 * compare the whole {@see HistoricImportTargetFingerprint}, which also carries
 * the release identifier, migration batch/count and three pipeline settings. A
 * mislabelled shell pointed at the production database therefore stopped being
 * guarded as soon as any of those drifted, which is precisely the
 * misconfiguration this fallback exists for. The comparison is now against the
 * stable {@see HistoricImportResourceIdentity} database anchor alone, so
 * configuration drift can never be read as evidence that a target is safe.
 *
 * Per HIR-D2 the storage anchor is recorded but **does not** trigger these
 * controls, because local dev's public sermon disk resolves to the production
 * bucket and an OR-ed storage anchor would refuse the §13.5 rehearsal. The
 * accepted residual risk is that a local historic *release* still writes to the
 * production public bucket and this guard will not stop it; HIR7's object-store
 * boundary carries the compensating refusal (plan §4.2.1).
 */
class HistoricImportProductionGuard
{
    public function __construct(
        private readonly Application $application,
        private readonly HistoricImportResourceIdentity $resources,
    ) {}

    /**
     * The operator-facing reason this operation must not run, or null when it may.
     *
     * Returning a message rather than throwing lets each command report it the
     * way it already reports every other refusal, instead of introducing a
     * second failure idiom into commands that are careful about their exit codes.
     *
     * IC2 re-scoped this from the one-shot G8 window to per-round approval: a
     * caller that already knows the round's approved corpus and reviewed plan
     * hash passes them here, and a signed approval bound to a *different*
     * manifest or plan is refused exactly as an unapproved run would be. A
     * caller with no such hashes to bind (a lane IC2 has not reached yet)
     * passes neither and gets the pre-IC2 operation/target check only.
     *
     * **The corpus hash is per-command, and an approval binds to one command's notion of it.**
     * Each lane hashes its approved corpus differently — `oos:import-archive` presents its
     * curation manifest hash, `service-tracking:converge-historic-service` presents the batch
     * hash covering its media and convergence bundles — so `round.manifest_hash` means "the hash
     * this round's command computes for its own corpus". One approval listing both commands in
     * `permitted_commands` can therefore only ever satisfy one of them; the other is refused.
     * That fails in the safe direction, but sign an approval per command rather than discovering
     * it as a mystery refusal.
     *
     * @param  string  $operation  The invocation being guarded, as an operator would type it.
     * @param  string|null  $roundCorpusHash  The round's approved corpus hash as this command computes it, when known.
     * @param  string|null  $planHash  The round's exact reviewed plan hash, when known.
     */
    public function refusalFor(
        string $operation,
        ?string $operationId = null,
        ?string $roundCorpusHash = null,
        ?string $planHash = null,
    ): ?string {
        $anchorError = $this->anchorConfigurationError();

        if ($anchorError !== null) {
            return "Refusing to run {$operation}: {$anchorError}";
        }

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
                $roundCorpusHash,
                $planHash,
            );

            if ($operationId !== null && $approval['operation_id'] !== $operationId) {
                throw new RuntimeException('The command operation id does not match the approved immutable operation.');
            }

            /**
             * REV-D3/§7.1: an ingress pause is optional per-round tooling, never
             * a required gate — "there is no freeze requirement". The ingress
             * lock and mutation-freeze machinery stay available for an operator
             * who chooses a brief pause; this guard no longer demands one.
             */
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

    /**
     * Whether production controls apply to whatever this process resolves.
     *
     * The stable database anchor decides, never the full operation fingerprint.
     * An unconfigured anchor stays silent so a developer machine and the §13.5
     * rehearsal remain usable; that the anchor is *present* everywhere capable
     * of historic mutation is asserted by the release-candidate baseline test
     * rather than by refusing here, because refusing would gate G5 on config
     * that only a production deploy can supply.
     *
     * Every other unknown fails closed: a malformed or duplicated anchor, a
     * lingering legacy fingerprint key, and an anchor that is configured but
     * cannot be observed.
     */
    public function guardsCurrentEnvironment(): bool
    {
        if ($this->application->isProduction() || $this->anchorConfigurationError() !== null) {
            return true;
        }

        $anchor = $this->configuredAnchor('database');

        if ($anchor === null) {
            return false;
        }

        try {
            return hash_equals($anchor, $this->resources->databaseAnchor());
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Whether the recorded production *storage* anchor matches this process.
     *
     * Reported, never enforced — see the class docblock and HIR-D2. It exists
     * so the operator diagnostic can show the accepted residual risk, and so
     * HIR7's compensating refusal has one implementation to consult rather than
     * re-deriving the comparison.
     *
     * Null means the anchor is unconfigured or unobservable, which is not the
     * same as "does not match" and must not be reported as safe.
     */
    public function matchesProductionStorageAnchor(): ?bool
    {
        $anchor = $this->configuredAnchor('storage');

        if ($anchor === null) {
            return null;
        }

        try {
            return hash_equals($anchor, $this->resources->storageAnchor());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The reason the anchor configuration cannot be trusted, or null.
     *
     * Returned as a refusal from every guarded call site rather than logged,
     * because the failure mode this replaces was silence: a guard that cannot
     * tell whether it is looking at production must say so, not proceed.
     */
    public function anchorConfigurationError(): ?string
    {
        $legacy = config('church.historic_corpus.production_target_fingerprint');

        if (is_string($legacy) && trim($legacy) !== '') {
            return implode(PHP_EOL, [
                'HISTORIC_IMPORT_PRODUCTION_TARGET_FINGERPRINT is still set.',
                'That key compared the whole operation fingerprint, so release, migration or',
                'configuration drift silently disarmed this guard. It is read here only to refuse.',
                'Replace it with HISTORIC_IMPORT_PRODUCTION_DATABASE_ANCHOR and',
                'HISTORIC_IMPORT_PRODUCTION_STORAGE_ANCHOR from historic-import:prepare-operation --anchors.',
            ]);
        }

        $database = config('church.historic_corpus.production_database_anchor');
        $storage = config('church.historic_corpus.production_storage_anchor');

        foreach (['database' => $database, 'storage' => $storage] as $role => $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/', trim($value)) !== 1) {
                return "the recorded production {$role} anchor is not a SHA-256 digest.";
            }
        }

        // Two hashes of differently shaped objects cannot collide by accident,
        // so equality means one value was pasted into both variables.
        if ($this->configuredAnchor('database') !== null
            && $this->configuredAnchor('database') === $this->configuredAnchor('storage')) {
            return 'the production database and storage anchors are the same digest, so one was recorded twice.';
        }

        return null;
    }

    /**
     * A configured anchor, normalised, or null when it is absent or blank.
     *
     * Whitespace-only is null for the same reason a blank approval is: an env
     * line left as `=` is a mistake, and reading it as a value would make the
     * fail-closed default defeatable by a typo.
     */
    private function configuredAnchor(string $role): ?string
    {
        $value = config("church.historic_corpus.production_{$role}_anchor");

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : null;
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
