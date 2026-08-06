<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\HistoricMedia\HistoricStagingGuard;
use Illuminate\Contracts\Foundation\Application;

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
 * What this guard does *not* cover, so that its silence is not mistaken for
 * cover: storage isolation, which is the per-batch staging root's job
 * ({@see HistoricStagingGuard}), and an operator who deliberately points a
 * non-production `APP_ENV` at production infrastructure. This checks which
 * environment the application believes it is, which is the signal that catches
 * the realistic mistake — a production `.env` in the shell that a rehearsal
 * command was typed into.
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
    public function refusalFor(string $operation): ?string
    {
        if (! $this->guardsCurrentEnvironment()) {
            return null;
        }

        if ($this->approvedOperationId() !== null) {
            return null;
        }

        return $this->describeRefusal($operation);
    }

    /**
     * The recorded G8 approval, or null when production imports are unapproved.
     *
     * Whitespace-only is null: a config value of " " is a mistake, and reading it
     * as an approval would make the fail-closed default defeatable by a typo.
     */
    public function approvedOperationId(): ?string
    {
        $approval = config('church.historic_corpus.production_import_approval');

        if (! is_string($approval) || trim($approval) === '') {
            return null;
        }

        return trim($approval);
    }

    public function guardsCurrentEnvironment(): bool
    {
        return $this->application->isProduction();
    }

    public function describeRefusal(string $operation): string
    {
        return implode(PHP_EOL, [
            "Refusing to run {$operation} against production: no approved G8 import operation is recorded.",
            'Canonical historic imports are a production-once operation (§5, G8). Stage, project and',
            're-project the corpus in a rehearsal database instead — that is what §13.5 steps 3-4 are,',
            'and this guard is silent there.',
            'If this *is* the approved production window, set HISTORIC_IMPORT_PRODUCTION_APPROVAL to the',
            'approved operation identifier so the closeout report can quote the authority for the run.',
        ]);
    }
}
