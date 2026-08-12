<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailExtractionValidationResult;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;

class OosEmailExtractionValidator
{
    private const EVENING_SERVICE_PATTERN = '/(?:\bafternoon\b|\bevening\b|\btonight\b|\bpm\b|\b(?:1[6-9]|2[0-3])[:.]\d{2}\b|\b(?:5|6|7|8|9)\s*(?:[.:]\d{2}\s*)?pm\b)/iu';

    private const SERVICE_ITEM_PATTERN = '/^\s*(?:(?:welcome|opening\s+prayer|closing\s+prayer|prayers?|notices|sermon|message|bible\s+reading|reading|communion|children(?:\x{2019}|\x{27})?s\s+talk|family\s+talk|call\s+to\s+worship)\b|(?:hymn|song)\s*:|(?:nip|ch|mp|sofp)?\s*\d{1,4}\b)/iu';

    /**
     * These are deliberately narrow: every `other` service requires human review, while this
     * list only distinguishes an explicit special-service anchor from ordinary notices.
     */
    private const SPECIAL_SERVICE_PATTERN = '/\b(?:carols?|christmas|good\s+friday|maundy|easter|thanksgiving|baptism|ordination|anniversary|funeral|wedding)\b/iu';

    public function validate(
        OosEmailSourceDocument $source,
        OosEmailItemExtractionResult $extraction,
        ?string $subject = null,
    ): OosEmailExtractionValidationResult {
        if (! $extraction->provenanceComplete) {
            return new OosEmailExtractionValidationResult;
        }

        $globalReasons = [];
        $planReasons = [];
        $assignments = [];
        $serviceCount = count($extraction->services);

        if ($extraction->serviceCount !== $serviceCount) {
            $globalReasons[] = sprintf(
                'The extraction reports %d service order(s) but returned %d service plan(s).',
                $extraction->serviceCount ?? 0,
                $serviceCount,
            );
        }

        foreach ($extraction->ignoredLines as $ignoredLine) {
            $lineId = $ignoredLine['line_id'];
            $reason = $ignoredLine['reason'];

            if (! $source->hasLine($lineId)) {
                $globalReasons[] = 'An ignored-line reference does not exist in the source email.';

                continue;
            }

            if ($reason === '') {
                $globalReasons[] = "Ignored source line {$lineId} has no reason.";

                continue;
            }

            $this->assignLine($assignments, $lineId, 'ignored', $globalReasons);
        }

        $planStarts = $this->planStarts($extraction);
        $planKeys = [];

        foreach ($extraction->services as $planIndex => $service) {
            $planReasons[$planIndex] ??= [];
            $serviceName = $service['service'] ?? null;
            $date = $service['date'] ?? null;
            $planKey = "{$serviceName}:{$date}";

            if (isset($planKeys[$planKey])) {
                $globalReasons[] = "The extraction returned duplicate service plan {$planKey}.";
            }

            $planKeys[$planKey] = true;
            $evidenceLineIds = $this->integerLineIds($service['service_evidence_line_ids'] ?? []);

            if ($serviceCount > 1 && $evidenceLineIds === []) {
                $planReasons[$planIndex][] = 'Multiple service orders require source-line evidence for each boundary.';
            }

            foreach ($evidenceLineIds as $lineId) {
                if (! $source->hasLine($lineId)) {
                    $planReasons[$planIndex][] = "Service evidence line {$lineId} does not exist.";

                    continue;
                }

                $this->assignLine($assignments, $lineId, "plan {$planIndex} evidence", $planReasons[$planIndex]);
            }

            $hasSinglePlanSubjectEvidence = $serviceCount === 1 && is_string($subject);

            if ($serviceName === 'other'
                && ! $this->hasSpecialServiceEvidence($source, $evidenceLineIds)
                && ! ($hasSinglePlanSubjectEvidence && preg_match(self::SPECIAL_SERVICE_PATTERN, $subject) === 1)) {
                $planReasons[$planIndex][] = 'An other service requires explicit special-service evidence; ordinary notices are not a service order.';
            }

            if ($serviceName === 'evening'
                && ! $this->hasEveningServiceEvidence($source, $evidenceLineIds)
                && ! ($hasSinglePlanSubjectEvidence && preg_match(self::EVENING_SERVICE_PATTERN, $subject) === 1)) {
                $planReasons[$planIndex][] = 'An evening service requires an explicit evening or PM boundary in its evidence lines.';
            }

            $previousItemLineId = null;
            $planItemLineIds = [];

            foreach ($service['items'] as $itemIndex => $item) {
                $sourceLineIds = $this->integerLineIds($item['source_line_ids'] ?? []);
                $continuation = ($item['continuation'] ?? false) === true;

                if ($sourceLineIds === []) {
                    $planReasons[$planIndex][] = 'Every extracted item must reference at least one source line.';

                    continue;
                }

                if ($sourceLineIds !== array_values(array_unique($sourceLineIds))) {
                    $planReasons[$planIndex][] = 'Item '.($itemIndex + 1).' repeats a source line.';
                }

                $sortedLineIds = $sourceLineIds;
                sort($sortedLineIds);

                if ($sourceLineIds !== $sortedLineIds) {
                    $planReasons[$planIndex][] = 'Item '.($itemIndex + 1).' has out-of-order source lines.';
                }

                if (count($sourceLineIds) > 1 && (! $continuation || ! $source->arePhysicallyConsecutive($sourceLineIds))) {
                    $planReasons[$planIndex][] = 'Item '.($itemIndex + 1).' merges separate source lines instead of preserving one item per line.';
                }

                $firstLineId = $sourceLineIds[0];

                if ($previousItemLineId !== null && $firstLineId <= $previousItemLineId) {
                    $planReasons[$planIndex][] = 'Extracted items are not in source order.';
                }

                foreach ($sourceLineIds as $lineId) {
                    if (! $source->hasLine($lineId)) {
                        $planReasons[$planIndex][] = "Item source line {$lineId} does not exist.";

                        continue;
                    }

                    $this->assignLine($assignments, $lineId, "plan {$planIndex} item", $planReasons[$planIndex]);
                    $planItemLineIds[] = $lineId;
                    $previousItemLineId = $lineId;
                }
            }

            $this->validatePlanSpan(
                $source,
                $planIndex,
                $planStarts,
                $planItemLineIds,
                $assignments,
                $planReasons,
            );
        }

        foreach ($source->lineIds() as $lineId) {
            if (! isset($assignments[$lineId])) {
                $globalReasons[] = "Source line {$lineId} was not classified as evidence, an item, or ignored context.";
            }
        }

        return new OosEmailExtractionValidationResult(
            globalReasons: array_values(array_unique($globalReasons)),
            planReasons: array_map(
                static fn (array $reasons): array => array_values(array_unique($reasons)),
                $planReasons,
            ),
        );
    }

    /**
     * @param  array<int, string>  $assignments
     * @param  list<string>  $reasons
     */
    private function assignLine(array &$assignments, int $lineId, string $assignment, array &$reasons): void
    {
        if (isset($assignments[$lineId])) {
            $reasons[] = "Source line {$lineId} is assigned more than once.";

            return;
        }

        $assignments[$lineId] = $assignment;
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
     * @return array<int, int>
     */
    private function planStarts(OosEmailItemExtractionResult $extraction): array
    {
        $starts = [];

        foreach ($extraction->services as $planIndex => $service) {
            $candidateLineIds = $this->integerLineIds($service['service_evidence_line_ids'] ?? []);

            foreach ($service['items'] as $item) {
                $candidateLineIds = array_merge(
                    $candidateLineIds,
                    $this->integerLineIds($item['source_line_ids'] ?? []),
                );
            }

            if ($candidateLineIds !== []) {
                $starts[$planIndex] = min($candidateLineIds);
            }
        }

        return $starts;
    }

    /**
     * @param  array<int, int>  $planStarts
     * @param  list<int>  $planItemLineIds
     * @param  array<int, string>  $assignments
     * @param  array<int, list<string>>  $planReasons
     */
    private function validatePlanSpan(
        OosEmailSourceDocument $source,
        int $planIndex,
        array $planStarts,
        array $planItemLineIds,
        array $assignments,
        array &$planReasons,
    ): void {
        if ($planItemLineIds === []) {
            return;
        }

        $start = min($planItemLineIds);
        $nextStarts = array_filter(
            $planStarts,
            static fn (int $candidate, int $candidateIndex): bool => $candidateIndex > $planIndex,
            ARRAY_FILTER_USE_BOTH,
        );
        $sourceLineIds = $source->lineIds();
        if ($nextStarts !== []) {
            $end = min($nextStarts) - 1;
        } elseif ($sourceLineIds !== []) {
            $end = max($sourceLineIds);
        } else {
            return;
        }

        foreach ($source->lineIds() as $lineId) {
            if ($lineId < $start || $lineId > $end) {
                continue;
            }

            if (! isset($assignments[$lineId])) {
                continue;
            }

            $line = $source->line($lineId);

            if ($assignments[$lineId] === 'ignored'
                && is_string($line)
                && preg_match(self::SERVICE_ITEM_PATTERN, $line) === 1) {
                $planReasons[$planIndex][] = "Source line {$lineId} was ignored inside a service item sequence.";
            }
        }
    }
}
