<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosSemanticAnnotationPatch;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticFinding;
use RuntimeException;

class ApplyOosSemanticAnnotationPatch
{
    /**
     * @param  list<OosSemanticFinding>  $findings
     */
    public function apply(
        OosSemanticAnnotationResult $original,
        OosSemanticAnnotationPatch $patch,
        array $findings,
    ): OosSemanticAnnotationResult {
        $allowlist = $this->allowlist($findings);
        $annotations = $original->annotations;

        foreach ($patch->replacements as $lineId => $replacement) {
            $existing = $annotations[$lineId] ?? null;

            if ($existing === null || ! isset($allowlist[$lineId]) || $replacement->lineId !== $lineId) {
                throw new RuntimeException("Semantic repair attempted to mutate unrelated line {$lineId}.");
            }

            foreach ($this->changedFields($existing->toArray(), $replacement->toArray()) as $field) {
                if (! in_array($field, $allowlist[$lineId], true)) {
                    throw new RuntimeException("Semantic repair attempted to mutate disallowed field {$field} on line {$lineId}.");
                }
            }

            $annotations[$lineId] = $replacement;
        }

        return new OosSemanticAnnotationResult(
            services: $original->services,
            annotations: $annotations,
            telemetry: $original->telemetry,
        );
    }

    /**
     * @param  list<OosSemanticFinding>  $findings
     * @return array<int, list<string>>
     */
    private function allowlist(array $findings): array
    {
        $allowlist = [];

        foreach ($findings as $finding) {
            foreach ($finding->lineIds as $lineId) {
                $allowlist[$lineId] = array_values(array_unique([
                    ...($allowlist[$lineId] ?? []),
                    ...$finding->repairableFields,
                ]));
            }
        }

        return $allowlist;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function changedFields(array $before, array $after): array
    {
        unset($before['line_id'], $after['line_id']);

        return array_values(array_filter(
            array_keys($before),
            static fn (string $field): bool => $before[$field] !== $after[$field],
        ));
    }
}
