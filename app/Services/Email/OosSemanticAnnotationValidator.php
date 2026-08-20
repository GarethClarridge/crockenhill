<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticFinding;
use App\Enums\OosSemanticRole;

class OosSemanticAnnotationValidator
{
    /** @return list<OosSemanticFinding> */
    public function validate(OosEmailSourceDocument $source, OosSemanticAnnotationResult $result): array
    {
        $findings = [];
        $sourceLineIds = $source->lineIds();
        $annotationKeys = array_keys($result->annotations);
        sort($sourceLineIds);
        sort($annotationKeys);

        foreach (array_diff($sourceLineIds, $annotationKeys) as $lineId) {
            $findings[] = $this->finding('annotation_missing', "Source line {$lineId} has no annotation.", [$lineId]);
        }

        foreach (array_diff($annotationKeys, $sourceLineIds) as $lineId) {
            $findings[] = $this->finding('annotation_invented', "Annotation line {$lineId} does not exist in the source.", [$lineId]);
        }

        $groups = [];

        foreach ($result->services as $service) {
            if ($service->groupId === '' || isset($groups[$service->groupId])) {
                $findings[] = $this->finding('service_group_duplicate', 'Candidate service group IDs must be non-empty and unique.', $service->boundaryLineIds);

                continue;
            }

            $groups[$service->groupId] = true;

            if ($service->boundaryLineIds === []) {
                $findings[] = $this->finding('service_boundary_missing', "Service group {$service->groupId} has no boundary evidence.", []);
            }

            foreach ($service->boundaryLineIds as $lineId) {
                if (! $source->hasLine($lineId)) {
                    $findings[] = $this->finding('service_boundary_invented', "Service group {$service->groupId} cites missing boundary line {$lineId}.", [$lineId]);

                    continue;
                }

                $annotation = $result->annotations[$lineId] ?? null;

                if ($annotation === null
                    || $annotation->role !== OosSemanticRole::ServiceBoundary
                    || ($annotation->serviceGroupId !== $service->groupId
                        && ! in_array($service->groupId, $annotation->sharedServiceGroupIds, true))) {
                    $findings[] = $this->finding(
                        'service_boundary_annotation_invalid',
                        "Boundary line {$lineId} is not annotated for service group {$service->groupId}.",
                        [$lineId],
                        ['role', 'service_group_id', 'shared_service_group_ids'],
                    );
                }
            }
        }

        foreach ($result->annotations as $key => $annotation) {
            if ($key !== $annotation->lineId) {
                $findings[] = $this->finding('annotation_key_mismatch', "Annotation key {$key} contains line {$annotation->lineId}.", [$key, $annotation->lineId]);
            }

            $referencedGroups = array_values(array_unique(array_filter([
                $annotation->serviceGroupId,
                ...$annotation->sharedServiceGroupIds,
            ], is_string(...))));

            foreach ($referencedGroups as $groupId) {
                if (! isset($groups[$groupId])) {
                    $findings[] = $this->finding(
                        'service_group_unknown',
                        "Line {$annotation->lineId} references unknown service group {$groupId}.",
                        [$annotation->lineId],
                        ['service_group_id', 'shared_service_group_ids'],
                    );
                }
            }

            $isItem = $annotation->role === OosSemanticRole::Item || $annotation->boundaryAlsoItem;

            if ($isItem && ($annotation->itemKind === null || $annotation->serviceGroupId === null)) {
                $findings[] = $this->finding(
                    'item_semantics_incomplete',
                    "Item line {$annotation->lineId} requires an item kind and primary service group.",
                    [$annotation->lineId],
                    ['item_kind', 'service_group_id'],
                );
            }

            if (! $isItem && $annotation->itemKind !== null) {
                $findings[] = $this->finding(
                    'non_item_has_kind',
                    "Non-item line {$annotation->lineId} cannot carry an item kind.",
                    [$annotation->lineId],
                    ['item_kind', 'role'],
                );
            }

            if ($annotation->boundaryAlsoItem && $annotation->role !== OosSemanticRole::ServiceBoundary) {
                $findings[] = $this->finding(
                    'boundary_item_role_invalid',
                    "Line {$annotation->lineId} may be both boundary and item only with the service_boundary role.",
                    [$annotation->lineId],
                    ['boundary_also_item', 'role'],
                );
            }

            if ($annotation->sharedServiceGroupIds !== [] && $annotation->role !== OosSemanticRole::ServiceBoundary) {
                $findings[] = $this->finding(
                    'shared_boundary_role_invalid',
                    "Only a service boundary may be shared by service groups (line {$annotation->lineId}).",
                    [$annotation->lineId],
                    ['shared_service_group_ids', 'role'],
                );
            }

            if ($annotation->role === OosSemanticRole::Continuation) {
                $this->validateContinuation($source, $result, $annotation->lineId, $findings);
            } elseif ($annotation->continuationTargetLineId !== null) {
                $findings[] = $this->finding(
                    'continuation_target_on_wrong_role',
                    "Line {$annotation->lineId} has a continuation target but is not a continuation.",
                    [$annotation->lineId],
                    ['continuation_target_line_id', 'role'],
                );
            }
        }

        return $findings;
    }

    /** @param list<OosSemanticFinding> $findings */
    private function validateContinuation(OosEmailSourceDocument $source, OosSemanticAnnotationResult $result, int $lineId, array &$findings): void
    {
        $annotation = $result->annotations[$lineId];
        $targetLineId = $annotation->continuationTargetLineId;
        $target = $targetLineId === null ? null : ($result->annotations[$targetLineId] ?? null);

        if (! OosSemanticContinuationRule::isPermittedTarget($source, $lineId, $targetLineId) || $target === null) {
            $findings[] = $this->finding(
                'continuation_not_adjacent',
                "Continuation line {$lineId} must target the immediately preceding physical line.",
                array_filter([$lineId, $targetLineId], is_int(...)),
                ['continuation_target_line_id', 'role'],
            );

            return;
        }

        if (! in_array($target->role, [OosSemanticRole::Item, OosSemanticRole::Continuation], true)
            || $target->serviceGroupId !== $annotation->serviceGroupId) {
            $findings[] = $this->finding(
                'continuation_target_invalid',
                "Continuation line {$lineId} must extend an item in the same service group.",
                [$targetLineId, $lineId],
                ['continuation_target_line_id', 'service_group_id', 'role'],
            );
        }
    }

    /**
     * @param  list<int>  $lineIds
     * @param  list<string>  $repairableFields
     */
    private function finding(string $code, string $message, array $lineIds, array $repairableFields = []): OosSemanticFinding
    {
        return new OosSemanticFinding($code, $message, $lineIds, $repairableFields);
    }
}
