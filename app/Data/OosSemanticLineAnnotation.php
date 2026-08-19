<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Enums\OosSemanticUncertainty;

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
