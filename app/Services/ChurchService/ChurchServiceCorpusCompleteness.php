<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;

/**
 * Corpus-completeness evidence for the §9.4.6 review-load gate.
 *
 * The census counts proposals, so it is silent about the services that produced
 * none — including the services that produced none because nothing was staged or
 * projected for them. This class supplies the independent counts the gate
 * reconciles against, built from staged source revisions and recorded projection
 * state rather than from proposals, so an unrun corpus cannot look like a clean one.
 *
 * §13.1's rule applies here too: the corpus is not "complete enough" at an informal
 * percentage. The approved manifest size is declared, and the observed counts must
 * equal it.
 *
 * @phpstan-type CorpusEvidence array{
 *     expected_services: int|null,
 *     staged_services: int,
 *     projected_services: int,
 *     stale_projection_services: int,
 *     unstaged_services: int|null,
 *     policy_version: int,
 * }
 */
class ChurchServiceCorpusCompleteness
{
    public function __construct(
        private readonly ChurchServiceProjector $projector,
    ) {}

    /**
     * @param  int|null  $expectedServices  The approved manifest's service count; falls
     *                                      back to the configured value when omitted.
     * @return CorpusEvidence
     */
    public function evidence(?int $expectedServices = null): array
    {
        $expected = $expectedServices ?? $this->configuredExpectedServices();
        $policyVersion = $this->projector->policyFingerprint()['version'];
        $staged = $this->stagedServices();
        $projected = $this->projectedServices($policyVersion);

        return [
            'expected_services' => $expected,
            'staged_services' => $staged,
            'projected_services' => $projected,
            'stale_projection_services' => max(0, $staged - $projected),
            'unstaged_services' => $expected === null ? null : max(0, $expected - $staged),
            'policy_version' => $policyVersion,
        ];
    }

    /** Services carrying at least one staged source revision. */
    private function stagedServices(): int
    {
        return ChurchServiceSourceRecord::query()->distinct()->count('church_service_id');
    }

    /**
     * Staged services whose recorded projection was produced by the current policy
     * version. A service projected under an older version has to be re-projected
     * before its proposals — or their absence — mean anything.
     */
    private function projectedServices(int $policyVersion): int
    {
        return ChurchService::query()
            ->whereHas('sourceRecords')
            ->where('projection_policy_version', $policyVersion)
            ->count();
    }

    private function configuredExpectedServices(): ?int
    {
        $configured = config('church.historic_corpus.expected_services');

        return is_numeric($configured) ? (int) $configured : null;
    }
}
