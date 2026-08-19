<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosSemanticUncertainty;

readonly class OosCandidateService
{
    /**
     * @param  list<int>  $boundaryLineIds
     * @param  list<OosSemanticUncertainty>  $uncertainties
     */
    public function __construct(
        public string $groupId,
        public ?string $proposedService,
        public array $boundaryLineIds,
        public array $uncertainties = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'proposed_service' => $this->proposedService,
            'boundary_line_ids' => $this->boundaryLineIds,
            'uncertainties' => array_map(
                static fn (OosSemanticUncertainty $uncertainty): string => $uncertainty->value,
                $this->uncertainties,
            ),
        ];
    }
}
