<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosEmailPlanHoldReason;

/**
 * The outcome of validating one extraction against its source document.
 *
 * Reasons come in two kinds, and the distinction decides whether a human can still rescue the
 * plan (F65). A **content** reason impeaches the extracted order itself — two items claiming one
 * line, items out of source order, a service boundary with no evidence — and makes the plan an
 * invalid extraction that review cannot import. Every other reason impeaches only the model's
 * bookkeeping about which source lines it accounted for; the order may be perfect, so the plan is
 * held for review rather than rejected outright.
 *
 * `globalReasons`/`planReasons` carry every reason, for display and for the corrective retry
 * prompt. The `content*` lists are the subset that invalidates.
 */
readonly class OosEmailExtractionValidationResult
{
    /**
     * @param  list<string>  $globalReasons
     * @param  array<int, list<string>>  $planReasons
     * @param  list<string>  $contentGlobalReasons
     * @param  array<int, list<string>>  $contentPlanReasons
     * @param  list<OosEmailStructuralFinding>  $structuralFindings
     */
    public function __construct(
        public array $globalReasons = [],
        public array $planReasons = [],
        public array $contentGlobalReasons = [],
        public array $contentPlanReasons = [],
        public array $structuralFindings = [],
    ) {}

    /**
     * @return list<string>
     */
    public function allReasons(): array
    {
        return array_values(array_unique(array_merge(
            $this->globalReasons,
            ...array_values($this->planReasons),
        )));
    }

    /**
     * @return list<string>
     */
    public function reasonsForPlan(int $planIndex): array
    {
        return array_values(array_unique(array_merge(
            $this->globalReasons,
            $this->planReasons[$planIndex] ?? [],
        )));
    }

    /**
     * The reasons that make this plan an invalid extraction rather than one held for review.
     *
     * @return list<string>
     */
    public function contentReasonsForPlan(int $planIndex): array
    {
        return array_values(array_unique(array_merge(
            $this->contentGlobalReasons,
            $this->contentPlanReasons[$planIndex] ?? [],
        )));
    }

    /**
     * The reasons that hold this plan for review without impeaching its order — what
     * {@see OosEmailPlanHoldReason::Bookkeeping} is meant to name. The parser used to
     * ask for these by passing {@see reasonsForPlan()} into a parameter called `$structuralReasons`,
     * so every content-invalid plan was also labelled a bookkeeping hold: content reasons are a
     * subset of all reasons, and the subset was never subtracted.
     *
     * @return list<string>
     */
    public function structuralReasonsForPlan(int $planIndex): array
    {
        return array_values(array_diff(
            $this->reasonsForPlan($planIndex),
            $this->contentReasonsForPlan($planIndex),
        ));
    }

    /**
     * Findings that concern this plan: the ones observed inside it, plus the document-wide ones
     * that belong to no plan's item span.
     *
     * @return list<OosEmailStructuralFinding>
     */
    public function structuralFindingsForPlan(int $planIndex): array
    {
        return array_values(array_filter(
            $this->structuralFindings,
            static fn (OosEmailStructuralFinding $finding): bool => $finding->planIndex === $planIndex
                || $finding->planIndex === null,
        ));
    }

    public function isValid(): bool
    {
        return $this->allReasons() === [];
    }

    public function reasonCount(): int
    {
        return count($this->allReasons());
    }

    /**
     * Stable diagnostic identifiers for rules that invalidate extracted content. The prose remains
     * the operator-facing explanation; evaluation artifacts use these identifiers so a wording
     * change cannot masquerade as a changed failure family.
     *
     * @return list<string>
     */
    public function contentRuleCodes(): array
    {
        $codes = array_map(function (string $reason): string {
            return match (true) {
                str_starts_with($reason, 'The extraction reports ') => 'service_count_mismatch',
                str_starts_with($reason, 'An ignored-line reference does not exist') => 'ignored_line_missing',
                str_starts_with($reason, 'The extraction returned duplicate service plan ') => 'duplicate_service_plan',
                str_starts_with($reason, 'Multiple service orders require source-line evidence') => 'missing_service_boundary_evidence',
                str_starts_with($reason, 'An other service requires explicit special-service evidence') => 'missing_special_service_evidence',
                str_starts_with($reason, 'An evening service requires an explicit evening or PM boundary') => 'missing_evening_service_evidence',
                str_starts_with($reason, 'Every extracted item must reference ') => 'item_missing_source_line',
                preg_match('/^Item \d+ repeats a source line\.$/', $reason) === 1 => 'item_repeats_source_line',
                preg_match('/^Item \d+ has out-of-order source lines\.$/', $reason) === 1 => 'item_source_lines_out_of_order',
                preg_match('/^Item \d+ merges separate source lines/', $reason) === 1 => 'item_merges_non_continuation_lines',
                $reason === 'Extracted items are not in source order.' => 'items_out_of_source_order',
                preg_match('/^Item source line \d+ does not exist\.$/', $reason) === 1 => 'item_source_line_missing',
                preg_match('/^Source line \d+ is claimed by more than one item\.$/', $reason) === 1 => 'source_line_claimed_by_multiple_items',
                default => 'unclassified_content_rule',
            };
        }, array_values(array_unique(array_merge(
            $this->contentGlobalReasons,
            ...array_values($this->contentPlanReasons),
        ))));

        sort($codes);

        return array_values(array_unique($codes));
    }

    /** @return list<string> */
    public function bookkeepingRuleCodes(): array
    {
        $codes = array_map(
            static fn (OosEmailStructuralFinding $finding): string => $finding->rule->value,
            $this->structuralFindings,
        );
        sort($codes);

        return array_values(array_unique($codes));
    }
}
