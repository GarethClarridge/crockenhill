<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailExtractionValidationResult;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Data\OosEmailStructuralFinding;
use App\Enums\OosEmailStructuralFindingRule;

class OosEmailExtractionValidator
{
    private const EVENING_SERVICE_PATTERN = '/(?:\bafternoon\b|\bevening\b|\btonight\b|\bpm\b|\b(?:1[6-9]|2[0-3])[:.]\d{2}\b|\b(?:5|6|7|8|9)\s*(?:[.:]\d{2}\s*)?pm\b)/iu';

    private const SERVICE_ITEM_PATTERN = '/^\s*(?:(?:welcome|opening\s+prayer|closing\s+prayer|prayers?|notices|sermon|message|bible\s+reading|reading|communion|children(?:\x{2019}|\x{27})?s\s+talk|family\s+talk|call\s+to\s+worship)\b|(?:hymn|song)\s*:|(?:nip|ch|mp|sofp)?\s*\d{1,4}\b)/iu';

    /**
     * These are deliberately narrow: every `other` service requires human review, while this
     * list only distinguishes an explicit special-service anchor from ordinary notices.
     *
     * Public because {@see OosServiceDateResolver} reuses this exact pattern to suppress its
     * relative-Sunday fallback for a named special service: "the next Sunday" is not a safe guess
     * when the evidence names Christmas, Easter or another date-fixed occasion.
     */
    public const SPECIAL_SERVICE_PATTERN = '/\b(?:carols?|christmas|good\s+friday|maundy|easter|thanksgiving|baptism|ordination|anniversary|funeral|wedding)\b/iu';

    public function validate(
        OosEmailSourceDocument $source,
        OosEmailItemExtractionResult $extraction,
        ?string $subject = null,
    ): OosEmailExtractionValidationResult {
        if (! $extraction->provenanceComplete) {
            return new OosEmailExtractionValidationResult;
        }

        $globalReasons = [];
        $globalContentReasons = [];
        $planReasons = [];
        $planContentReasons = [];
        $assignments = [];
        $findings = [];
        $planItemLines = [];
        $serviceCount = count($extraction->services);

        if ($extraction->serviceCount !== $serviceCount) {
            $this->addContent($globalReasons, $globalContentReasons, sprintf(
                'The extraction reports %d service order(s) but returned %d service plan(s).',
                $extraction->serviceCount ?? 0,
                $serviceCount,
            ));
        }

        foreach ($extraction->ignoredLines as $ignoredLine) {
            $lineId = $ignoredLine['line_id'];
            $reason = $ignoredLine['reason'];

            if (! $source->hasLine($lineId)) {
                $this->addContent(
                    $globalReasons,
                    $globalContentReasons,
                    'An ignored-line reference does not exist in the source email.',
                );

                continue;
            }

            if ($reason === '') {
                $globalReasons[] = "Ignored source line {$lineId} has no reason.";
                $findings[] = new OosEmailStructuralFinding(
                    OosEmailStructuralFindingRule::IgnoredLineWithoutReason,
                    $lineId,
                );

                continue;
            }

            $this->assignLine($assignments, $lineId, 'ignored', $globalReasons, $globalContentReasons, $findings, null);
        }

        $planKeys = [];

        foreach ($extraction->services as $planIndex => $service) {
            $planReasons[$planIndex] ??= [];
            $planContentReasons[$planIndex] ??= [];
            $serviceName = $service['service'] ?? null;
            $date = $service['date'] ?? null;
            $planKey = "{$serviceName}:{$date}";

            if (isset($planKeys[$planKey])) {
                $this->addContent(
                    $globalReasons,
                    $globalContentReasons,
                    "The extraction returned duplicate service plan {$planKey}.",
                );
            }

            $planKeys[$planKey] = true;
            $evidenceLineIds = $this->integerLineIds($service['service_evidence_line_ids'] ?? []);

            if ($serviceCount > 1 && $evidenceLineIds === []) {
                $this->addContent(
                    $planReasons[$planIndex],
                    $planContentReasons[$planIndex],
                    'Multiple service orders require source-line evidence for each boundary.',
                );
            }

            foreach ($evidenceLineIds as $lineId) {
                if (! $source->hasLine($lineId)) {
                    $this->addContent(
                        $planReasons[$planIndex],
                        $planContentReasons[$planIndex],
                        "Service evidence line {$lineId} does not exist.",
                    );

                    continue;
                }

                $this->assignLine(
                    $assignments,
                    $lineId,
                    'evidence',
                    $planReasons[$planIndex],
                    $planContentReasons[$planIndex],
                    $findings,
                    $planIndex,
                );
            }

            $hasSinglePlanSubjectEvidence = $serviceCount === 1 && is_string($subject);

            if ($serviceName === 'other'
                && ! $this->hasSpecialServiceEvidence($source, $evidenceLineIds)
                && ! ($hasSinglePlanSubjectEvidence && preg_match(self::SPECIAL_SERVICE_PATTERN, $subject) === 1)) {
                $this->addContent(
                    $planReasons[$planIndex],
                    $planContentReasons[$planIndex],
                    'An other service requires explicit special-service evidence; ordinary notices are not a service order.',
                );
            }

            if ($serviceName === 'evening'
                && ! $this->hasEveningServiceEvidence($source, $evidenceLineIds)
                && ! ($hasSinglePlanSubjectEvidence && preg_match(self::EVENING_SERVICE_PATTERN, $subject) === 1)) {
                $this->addContent(
                    $planReasons[$planIndex],
                    $planContentReasons[$planIndex],
                    'An evening service requires an explicit evening or PM boundary in its evidence lines.',
                );
            }

            $previousItemLineId = null;
            $planItemLineIds = [];

            foreach ($service['items'] as $itemIndex => $item) {
                $sourceLineIds = $this->integerLineIds($item['source_line_ids'] ?? []);
                $continuation = ($item['continuation'] ?? false) === true;

                if ($sourceLineIds === []) {
                    $this->addContent(
                        $planReasons[$planIndex],
                        $planContentReasons[$planIndex],
                        'Every extracted item must reference at least one source line.',
                    );

                    continue;
                }

                if ($sourceLineIds !== array_values(array_unique($sourceLineIds))) {
                    $this->addContent(
                        $planReasons[$planIndex],
                        $planContentReasons[$planIndex],
                        'Item '.($itemIndex + 1).' repeats a source line.',
                    );
                }

                $sortedLineIds = $sourceLineIds;
                sort($sortedLineIds);

                if ($sourceLineIds !== $sortedLineIds) {
                    $this->addContent(
                        $planReasons[$planIndex],
                        $planContentReasons[$planIndex],
                        'Item '.($itemIndex + 1).' has out-of-order source lines.',
                    );
                }

                if (count($sourceLineIds) > 1 && (! $continuation || ! $source->arePhysicallyConsecutive($sourceLineIds))) {
                    $this->addContent(
                        $planReasons[$planIndex],
                        $planContentReasons[$planIndex],
                        'Item '.($itemIndex + 1).' merges separate source lines instead of preserving one item per line.',
                    );
                }

                $firstLineId = $sourceLineIds[0];

                if ($previousItemLineId !== null && $firstLineId <= $previousItemLineId) {
                    $this->addContent(
                        $planReasons[$planIndex],
                        $planContentReasons[$planIndex],
                        'Extracted items are not in source order.',
                    );
                }

                foreach ($sourceLineIds as $lineId) {
                    if (! $source->hasLine($lineId)) {
                        $this->addContent(
                            $planReasons[$planIndex],
                            $planContentReasons[$planIndex],
                            "Item source line {$lineId} does not exist.",
                        );

                        continue;
                    }

                    $this->assignLine(
                        $assignments,
                        $lineId,
                        'item',
                        $planReasons[$planIndex],
                        $planContentReasons[$planIndex],
                        $findings,
                        $planIndex,
                    );
                    $planItemLineIds[] = $lineId;
                    $planItemLines[$planIndex][] = ['line_id' => $lineId, 'type' => $item['type']];
                    $previousItemLineId = $lineId;
                }
            }

            $this->validatePlanSpan(
                $source,
                $planIndex,
                $planItemLineIds,
                $assignments,
                $planReasons,
                $findings,
            );
        }

        foreach ($source->lineIds() as $lineId) {
            if (isset($assignments[$lineId])) {
                continue;
            }

            $reason = "Source line {$lineId} was not classified as evidence, an item, or ignored context.";
            $planIndex = $this->planContainingLine($planItemLines, $lineId);

            if ($planIndex === null) {
                $globalReasons[] = $reason;
            } else {
                $planReasons[$planIndex][] = $reason;
            }

            $findings[] = new OosEmailStructuralFinding(
                OosEmailStructuralFindingRule::LineUnclassified,
                $lineId,
                $planIndex,
            );
        }

        $unique = static fn (array $reasons): array => array_values(array_unique($reasons));

        return new OosEmailExtractionValidationResult(
            globalReasons: $unique($globalReasons),
            planReasons: array_map($unique, $planReasons),
            contentGlobalReasons: $unique($globalContentReasons),
            contentPlanReasons: array_map($unique, $planContentReasons),
            structuralFindings: $this->resolveFindings($findings, $planItemLines),
        );
    }

    /**
     * The plan whose item span brackets this line, or null when no single plan owns it. A line
     * before the first item or after the last belongs to the document — a greeting, a sign-off, an
     * appendix — and overlapping spans are already a content finding, so neither is attributed.
     *
     * @param  array<int, list<array{line_id:int,type:string}>>  $planItemLines
     */
    private function planContainingLine(array $planItemLines, int $lineId): ?int
    {
        $containing = [];

        foreach ($planItemLines as $planIndex => $itemLines) {
            $lineIds = array_column($itemLines, 'line_id');

            if ($lineIds !== [] && $lineId > min($lineIds) && $lineId < max($lineIds)) {
                $containing[] = $planIndex;
            }
        }

        return count($containing) === 1 ? $containing[0] : null;
    }

    /**
     * Fills in what was not knowable while the walk was in progress: a finding raised before its
     * plan was identified, and the items either side of the offending line.
     *
     * @param  list<OosEmailStructuralFinding>  $findings
     * @param  array<int, list<array{line_id:int,type:string}>>  $planItemLines
     * @return list<OosEmailStructuralFinding>
     */
    private function resolveFindings(array $findings, array $planItemLines): array
    {
        return array_map(
            function (OosEmailStructuralFinding $finding) use ($planItemLines): OosEmailStructuralFinding {
                $resolved = $finding->planIndex === null
                    ? $finding->withPlanIndex($this->planContainingLine($planItemLines, $finding->lineId))
                    : $finding;

                return $resolved->planIndex === null
                    ? $resolved
                    : $resolved->withAdjacentItemTypes($planItemLines[$resolved->planIndex] ?? []);
            },
            $findings,
        );
    }

    /**
     * Records a reason that impeaches the extracted order itself, so it both shows up for a human
     * and invalidates the plan.
     *
     * @param  list<string>  $reasons
     * @param  list<string>  $contentReasons
     */
    private function addContent(array &$reasons, array &$contentReasons, string $reason): void
    {
        $reasons[] = $reason;
        $contentReasons[] = $reason;
    }

    /**
     * One source line may legitimately be claimed twice. A heading that is also the first item,
     * or a date line two plans both cite as their boundary, names the line more than once without
     * extracting its content more than once — the 2026-08-14 review found 68 such plans against 2
     * genuine double-counts, and rejecting them discarded otherwise perfect orders.
     *
     * Two *items* claiming one line does duplicate content, and a line both ignored and claimed
     * is a contradiction, so those still register — the first as content, the second as
     * bookkeeping.
     *
     * @param  array<int, list<string>>  $assignments
     * @param  list<string>  $reasons
     * @param  list<string>  $contentReasons
     * @param  list<OosEmailStructuralFinding>  $findings
     */
    private function assignLine(
        array &$assignments,
        int $lineId,
        string $kind,
        array &$reasons,
        array &$contentReasons,
        array &$findings,
        ?int $planIndex,
    ): void {
        $existing = $assignments[$lineId] ?? [];

        if ($kind === 'item' && in_array('item', $existing, true)) {
            $this->addContent($reasons, $contentReasons, "Source line {$lineId} is claimed by more than one item.");
        } elseif ($existing !== [] && ($kind === 'ignored' || in_array('ignored', $existing, true))) {
            $reasons[] = "Source line {$lineId} is both ignored and claimed as evidence or an item.";
            $findings[] = new OosEmailStructuralFinding(
                OosEmailStructuralFindingRule::LineIgnoredAndClaimed,
                $lineId,
                $planIndex,
            );
        }

        $assignments[$lineId][] = $kind;
    }

    /**
     * @return list<int>
     */
    private function integerLineIds(mixed $lineIds): array
    {
        if (! is_array($lineIds)) {
            return [];
        }

        return array_values(array_filter($lineIds, is_int(...)));
    }

    /**
     * @param  list<int>  $evidenceLineIds
     */
    private function hasSpecialServiceEvidence(OosEmailSourceDocument $source, array $evidenceLineIds): bool
    {
        foreach ($evidenceLineIds as $lineId) {
            $line = $source->line($lineId);

            if (is_string($line) && preg_match(self::SPECIAL_SERVICE_PATTERN, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<int> $evidenceLineIds */
    private function hasEveningServiceEvidence(OosEmailSourceDocument $source, array $evidenceLineIds): bool
    {
        foreach ($evidenceLineIds as $lineId) {
            $line = $source->line($lineId);

            if (is_string($line) && preg_match(self::EVENING_SERVICE_PATTERN, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * An ignored line that sits *between* two extracted items may be an item the model dropped,
     * so it is worth surfacing. Content after the final item is not: orders are routinely followed
     * by sermon outlines, another service's reading or an appendix addressed to one person, all of
     * which look like service items and none of which belong to the order. The span therefore ends
     * at the last item line rather than running to the next plan or the end of the document.
     *
     * This is bookkeeping, never content: the rule cannot tell a dropped item from a deliberate
     * aside, so it holds the plan for a human instead of rejecting it.
     *
     * @param  list<int>  $planItemLineIds
     * @param  array<int, list<string>>  $assignments
     * @param  array<int, list<string>>  $planReasons
     * @param  list<OosEmailStructuralFinding>  $findings
     */
    private function validatePlanSpan(
        OosEmailSourceDocument $source,
        int $planIndex,
        array $planItemLineIds,
        array $assignments,
        array &$planReasons,
        array &$findings,
    ): void {
        if ($planItemLineIds === []) {
            return;
        }

        $start = min($planItemLineIds);
        $end = max($planItemLineIds);

        foreach ($source->lineIds() as $lineId) {
            if ($lineId < $start || $lineId > $end) {
                continue;
            }

            $line = $source->line($lineId);

            if (in_array('ignored', $assignments[$lineId] ?? [], true)
                && is_string($line)
                && preg_match(self::SERVICE_ITEM_PATTERN, $line) === 1) {
                $planReasons[$planIndex][] = "Source line {$lineId} was ignored inside a service item sequence.";
                $findings[] = new OosEmailStructuralFinding(
                    OosEmailStructuralFindingRule::LineIgnoredInsideItemSpan,
                    $lineId,
                    $planIndex,
                );
            }
        }
    }
}
