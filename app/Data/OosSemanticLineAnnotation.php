<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Enums\OosSemanticUncertainty;
use RuntimeException;

readonly class OosSemanticLineAnnotation
{
    /** @param list<string> $sharedServiceGroupIds */
    public function __construct(
        public int $lineId,
        public OosSemanticRole $role,
        public ?string $serviceGroupId,
        public ?OosSemanticItemKind $itemKind,
        public ?int $continuationTargetLineId,
        public ?OosSemanticUncertainty $uncertainty,
        public array $sharedServiceGroupIds = [],
        public bool $boundaryAlsoItem = false,
    ) {}

    /**
     * The exact inverse of {@see self::toArray()}, so a banked annotation payload can be rehydrated
     * and recompiled without re-calling the model. Unlike {@see OosSemanticAnnotationDecoder}, which
     * reads the model's `L001`-keyed schema response, this reads what the parser already stored.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $role = is_string($payload['role'] ?? null) ? OosSemanticRole::tryFrom($payload['role']) : null;

        if (! is_int($payload['line_id'] ?? null) || ! $role instanceof OosSemanticRole) {
            throw new RuntimeException('A stored line annotation carries no line ID and role.');
        }

        $sharedServiceGroupIds = [];

        foreach (is_array($payload['shared_service_group_ids'] ?? null) ? $payload['shared_service_group_ids'] : [] as $groupId) {
            if (! is_string($groupId)) {
                throw new RuntimeException('A stored line annotation shares a non-string service group ID.');
            }

            $sharedServiceGroupIds[] = $groupId;
        }

        return new self(
            lineId: $payload['line_id'],
            role: $role,
            serviceGroupId: is_string($payload['service_group_id'] ?? null) ? $payload['service_group_id'] : null,
            itemKind: is_string($payload['item_kind'] ?? null) ? OosSemanticItemKind::from($payload['item_kind']) : null,
            continuationTargetLineId: is_int($payload['continuation_target_line_id'] ?? null) ? $payload['continuation_target_line_id'] : null,
            uncertainty: is_string($payload['uncertainty'] ?? null) ? OosSemanticUncertainty::from($payload['uncertainty']) : null,
            sharedServiceGroupIds: $sharedServiceGroupIds,
            boundaryAlsoItem: (bool) ($payload['boundary_also_item'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'line_id' => $this->lineId,
            'role' => $this->role->value,
            'service_group_id' => $this->serviceGroupId,
            'item_kind' => $this->itemKind?->value,
            'continuation_target_line_id' => $this->continuationTargetLineId,
            'uncertainty' => $this->uncertainty?->value,
            'shared_service_group_ids' => $this->sharedServiceGroupIds,
            'boundary_also_item' => $this->boundaryAlsoItem,
        ];
    }
}
