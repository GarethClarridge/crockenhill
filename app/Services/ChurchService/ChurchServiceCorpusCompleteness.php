<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceSource;
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
 * The counts here are a proxy, and a weak one: a staged total cannot say which
 * approved entries produced it. {@see ChurchServiceCorpusExpectation} answers that
 * per identity from the manifest itself, and supplies `expected_services` when it
 * is present, so the figure is produced rather than configured.
 *
 * @phpstan-type CorpusEvidence array{
 *     expected_services: int|null,
 *     expected_services_source: string,
 *     staged_services: int,
 *     projected_services: int,
 *     stale_projection_services: int,
 *     unstaged_services: int|null,
 *     policy_version: int,
 *     staged_services_by_source: array<string, int>,
 *     declared_source_kinds: list<string>|null,
 *     unstaged_source_kinds: list<string>,
 *     membership: array<string, mixed>,
 *     expectation: array<string, mixed>,
 * }
 */
class ChurchServiceCorpusCompleteness
{
    public function __construct(
        private readonly ChurchServiceProjector $projector,
        private readonly ChurchServiceCorpusMembership $membership,
        private readonly ChurchServiceCorpusExpectation $expectation,
    ) {}

    /**
     * @param  array<string, mixed>|null  $expectedMembership  Hash-verified source-item membership.
     * @param  int|null  $expectedServices  The approved manifest's service count; overrides both
     *                                      the expectation and the configured value.
     * @param  array<string, mixed>|null  $corpusExpectation  Manifest-derived approved corpus.
     * @return CorpusEvidence
     */
    public function evidence(
        ?array $expectedMembership = null,
        ?int $expectedServices = null,
        ?array $corpusExpectation = null,
    ): array {
        $expectedMembership ??= $this->configuredMembership();
        $corpusExpectation ??= $this->configuredExpectation();
        $expectation = $this->expectation->certify($corpusExpectation);
        $policyVersion = $this->projector->policyFingerprint()['version'];
        $staged = $this->stagedServices();
        $projected = $this->projectedServices($policyVersion);
        $bySource = $this->stagedServicesBySource();
        $declared = $this->declaredSourceKinds();

        [$expected, $expectedFrom] = $this->expectedServices($expectedServices, $expectation);

        return [
            'expected_services' => $expected,
            'expected_services_source' => $expectedFrom,
            'staged_services' => $staged,
            'projected_services' => $projected,
            'stale_projection_services' => max(0, $staged - $projected),
            'unstaged_services' => $expected === null ? null : max(0, $expected - $staged),
            'policy_version' => $policyVersion,
            'staged_services_by_source' => $bySource,
            'declared_source_kinds' => $declared,
            'unstaged_source_kinds' => $declared === null
                ? []
                : array_values(array_filter(
                    $declared,
                    static fn (string $kind): bool => ($bySource[$kind] ?? 0) === 0,
                )),
            'membership' => $this->membership->certify($expectedMembership, $policyVersion),
            'expectation' => $expectation,
        ];
    }

    /**
     * Where the approved corpus size came from, named alongside the number.
     *
     * The manifest-derived expectation wins over the configured scalar whenever it
     * is present, because the scalar has no producer: in practice it was set from
     * what a previous run happened to stage, which makes the census grade its own
     * homework. An explicit argument still wins over both, for the diagnostic case
     * of asking what a different corpus size would say.
     *
     * @param  array<string, mixed>  $expectation
     * @return array{0:int|null, 1:string}
     */
    private function expectedServices(?int $explicit, array $expectation): array
    {
        if ($explicit !== null) {
            return [$explicit, 'argument'];
        }

        $derived = $expectation['expected_services'] ?? null;

        if (($expectation['approved'] ?? false) === true && is_int($derived)) {
            return [$derived, 'manifest_expectation'];
        }

        $configured = $this->configuredExpectedServices();

        return [$configured, $configured === null ? 'none' : 'configuration'];
    }

    /** Services carrying at least one staged source revision. */
    private function stagedServices(): int
    {
        return ChurchServiceSourceRecord::query()->distinct()->count('church_service_id');
    }

    /**
     * Distinct services carrying at least one revision of each source kind.
     *
     * These deliberately do **not** sum to `staged_services`: a service evidenced by
     * both Email and OpenLP is one staged service and appears under both kinds. The
     * total answers "how much of the corpus is evidenced at all", and this answers
     * "by what" — which is the question §9.4.2's Email x OpenLP population turns on,
     * and the one the total silently cannot distinguish.
     *
     * @return array<string, int>
     */
    private function stagedServicesBySource(): array
    {
        /** @var array<string, int> $counts */
        $counts = ChurchServiceSourceRecord::query()
            ->getQuery()
            ->select('source')
            ->selectRaw('COUNT(DISTINCT church_service_id) as services')
            ->groupBy('source')
            ->orderBy('source')
            ->pluck('services', 'source')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return $counts;
    }

    /**
     * The source kinds the census claims to cover, or null when nothing has been
     * declared.
     *
     * Unset is not "all kinds" and not "no requirement" — it is an undeclared scope,
     * which the gate refuses on the same principle it refuses an unset corpus size.
     * An unrecognised kind is also returned as null rather than silently dropped: a
     * typo that quietly narrowed the requirement would defeat the whole check.
     *
     * @return list<string>|null
     */
    private function declaredSourceKinds(): ?array
    {
        $configured = config('church.historic_corpus.census_source_kinds');

        if (! is_string($configured) && ! is_array($configured)) {
            return null;
        }

        $kinds = is_string($configured) ? explode(',', $configured) : $configured;
        $kinds = array_values(array_filter(array_map(
            static fn (mixed $kind): string => mb_strtolower(trim((string) $kind)),
            $kinds,
        ), static fn (string $kind): bool => $kind !== ''));

        if ($kinds === []) {
            return null;
        }

        foreach ($kinds as $kind) {
            if (! ChurchServiceSource::tryFrom($kind) instanceof ChurchServiceSource) {
                return null;
            }
        }

        return array_values(array_unique($kinds));
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

    /** @return array<string, mixed>|null */
    private function configuredMembership(): ?array
    {
        $configured = config('church.historic_corpus.membership');

        return is_array($configured) ? $configured : null;
    }

    /** @return array<string, mixed>|null */
    private function configuredExpectation(): ?array
    {
        $configured = config('church.historic_corpus.expectation');

        return is_array($configured) ? $configured : null;
    }
}
