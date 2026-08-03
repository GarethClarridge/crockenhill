<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChurchServiceProposalClassReview;
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
        {--gate : Exit non-zero unless every class is accounted for}';

    protected $description = 'Report pending evidence proposals grouped by class, with the review-load gate';

    public function handle(
        ChurchServiceProposalCensus $census,
        ChurchServiceProposalCensusGate $gate,
    ): int {
        $classes = $census->build();
        $result = $gate->evaluate($classes);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['classes' => $classes, 'gate' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $this->exitCode($result);
        }

        if ($classes === []) {
            $this->info('No pending evidence proposals. The census is empty.');

            return self::SUCCESS;
        }

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
        $this->reportBlockers($result);

        return $this->exitCode($result);
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
                'Gate passes: every class is marked %s or %s with a reason.',
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
