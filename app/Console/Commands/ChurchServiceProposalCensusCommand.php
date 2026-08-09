<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChurchServiceProposalClassReview;
use App\Services\ChurchService\ChurchServiceCorpusCompleteness;
use App\Services\ChurchService\ChurchServiceCorpusMembership;
use App\Services\ChurchService\ChurchServiceProposalCensus;
use App\Services\ChurchService\ChurchServiceProposalCensusGate;
use Illuminate\Console\Command;

/**
 * Prints the corpus proposal census and evaluates the §9.4.6 stopping condition.
 *
 * This is the working document of the review-load loop: project the corpus, take
 * the largest remaining class, decide whether it is automatable, and re-census. A
 * later matcher regression shows up here as the census growing.
 */
class ChurchServiceProposalCensusCommand extends Command
{
    protected $signature = 'services:proposal-census
        {--json : Emit the full census as JSON instead of a table}
        {--gate : Exit non-zero unless every class is accounted for}
        {--membership= : Hash-verified item-level historic corpus membership JSON}
        {--expected-services= : The approved corpus manifest'."'".'s service count, overriding church.historic_corpus.expected_services}';

    protected $description = 'Report pending evidence proposals grouped by class, with the review-load gate';

    public function handle(
        ChurchServiceProposalCensus $census,
        ChurchServiceProposalCensusGate $gate,
        ChurchServiceCorpusCompleteness $corpus,
        ChurchServiceCorpusMembership $membership,
    ): int {
        $classes = $census->build();
        $result = $gate->evaluate($classes, $corpus->evidence(
            $this->membership($membership),
            $this->expectedServices(),
        ));

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['classes' => $classes, 'gate' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $this->exitCode($result);
        }

        if ($classes === []) {
            $this->info('No pending evidence proposals. The census is empty.');
        } else {
            $this->reportClasses($classes, $result);
        }

        $this->reportCorpus($gate, $result);
        $this->reportBlockers($result);

        return $this->exitCode($result);
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     * @param  array<string, mixed>  $result
     */
    private function reportClasses(array $classes, array $result): void
    {
        $this->table(
            ['Subject', 'Tier', 'Proposals', 'Services', 'Status', 'Reason'],
            array_map(static fn (array $class): array => [
                mb_strimwidth($class['subject'], 0, 44, '…'),
                $class['match_tier'] ?? '—',
                $class['occurrence_count'],
                $class['service_count'],
                $class['status'],
                mb_strimwidth((string) ($class['reason'] ?? '—'), 0, 40, '…'),
            ], $classes),
        );

        $this->newLine();
        $this->line(sprintf(
            '%d class(es) covering %d proposal(s).',
            $result['class_count'],
            $result['proposal_count'],
        ));

        $this->reportResidual($result);
    }

    /**
     * Report what the census was taken over, so a clean-looking result can be read
     * against the corpus it claims to describe rather than on its own.
     *
     * @param  array<string, mixed>  $result
     */
    private function reportCorpus(ChurchServiceProposalCensusGate $gate, array $result): void
    {
        $corpus = $result['corpus'];
        $expected = $corpus['expected_services'];

        $this->line(sprintf(
            'Corpus: %d service(s) staged, %d projected at policy version %d, against %s approved.',
            $corpus['staged_services'],
            $corpus['projected_services'],
            $corpus['policy_version'],
            is_int($expected) ? (string) $expected : 'no manifest count',
        ));

        $this->reportSourceCoverage($corpus);
        $this->reportMembership($corpus);

        foreach ($result['corpus_blockers'] as $blocker) {
            $this->warn($gate->describeCorpusBlocker($blocker));
        }
    }

    /**
     * Name the evidence behind the count above.
     *
     * A staged-service total is the same number whether the corpus carries one source
     * kind or three, so it cannot show that a census declared over Email and OpenLP has
     * only ever seen Email. Every declared kind is listed, including the ones sitting
     * at zero — those are the whole point, and omitting an empty row would hide exactly
     * the case this reports.
     *
     * @param  array<string, mixed>  $corpus
     */
    private function reportSourceCoverage(array $corpus): void
    {
        /** @var array<string, int> $bySource */
        $bySource = $corpus['staged_services_by_source'];
        /** @var list<string>|null $declared */
        $declared = $corpus['declared_source_kinds'];
        $kinds = array_values(array_unique([...array_keys($bySource), ...($declared ?? [])]));
        sort($kinds);

        if ($kinds === []) {
            $this->line('  No source evidence is staged at all.');

            return;
        }

        $this->line('  Staged services by source: '.implode('  ', array_map(
            static fn (string $kind): string => sprintf('%s %d', $kind, $bySource[$kind] ?? 0),
            $kinds,
        )));

        if ($declared === null) {
            $this->line('  Declared census scope: none.');

            return;
        }

        $this->line('  Declared census scope: '.implode(', ', $declared).'.');
    }

    /** @param array<string, mixed> $corpus */
    private function reportMembership(array $corpus): void
    {
        $membership = $corpus['membership'] ?? [];

        if (! ($membership['approved'] ?? false)) {
            $this->line('  Item-level membership: not supplied.');

            return;
        }

        $issues = $membership['blockers'] ?? [];
        $this->line(sprintf(
            '  Item-level membership: %d approved source item(s), %s.',
            count($membership['items'] ?? []),
            $issues === [] ? 'certified' : 'mismatches: '.implode(', ', $issues),
        ));
    }

    /** @return array<string, mixed>|null */
    private function membership(ChurchServiceCorpusMembership $membership): ?array
    {
        $path = $this->option('membership');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return $membership->fromFile($path);
    }

    private function expectedServices(): ?int
    {
        $option = $this->option('expected-services');

        return is_numeric($option) ? (int) $option : null;
    }

    /** @param array<string, mixed> $result */
    private function reportResidual(array $result): void
    {
        if ($result['residual_decisions'] === 0) {
            $this->line('No class is marked irreducible, so there is no residual hand-review set.');

            return;
        }

        $seconds = $result['residual_seconds'];
        $this->line(sprintf(
            'Residual hand-review set: %d decision(s)%s.',
            $result['residual_decisions'],
            is_int($seconds)
                ? sprintf(', %s of measured review time', $this->duration($seconds))
                : ' (review time not yet measured)',
        ));
    }

    /** @param array<string, mixed> $result */
    private function reportBlockers(array $result): void
    {
        if ($result['unclassified'] !== []) {
            $this->warn(sprintf(
                '%d class(es) are neither automated nor irreducible. Mark each with a reason before the gate can pass.',
                count($result['unclassified']),
            ));
        }

        if ($result['irreducible_with_candidates'] !== []) {
            $this->warn(sprintf(
                '%d class(es) marked irreducible still name a candidate resolution a matcher tier change would settle.',
                count($result['irreducible_with_candidates']),
            ));
        }

        if ($result['unmeasured_irreducible'] !== []) {
            $this->warn(sprintf(
                '%d irreducible class(es) have no measured per-decision time, so the residual figure is an estimate.',
                count($result['unmeasured_irreducible']),
            ));
        }

        if ($result['passes']) {
            $this->info(sprintf(
                'Gate passes: the corpus reconciles and every class is marked %s or %s with a reason.',
                ChurchServiceProposalClassReview::AUTOMATED,
                ChurchServiceProposalClassReview::IRREDUCIBLE,
            ));
        }
    }

    private function duration(int $seconds): string
    {
        return $seconds < 3600
            ? sprintf('%d min', (int) ceil($seconds / 60))
            : sprintf('%.1f hours', $seconds / 3600);
    }

    /** @param array<string, mixed> $result */
    private function exitCode(array $result): int
    {
        return $this->option('gate') && ! $result['passes'] ? self::FAILURE : self::SUCCESS;
    }
}
