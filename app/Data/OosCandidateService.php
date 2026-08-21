<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosSemanticUncertainty;
use RuntimeException;

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

    /**
     * The exact inverse of {@see self::toArray()}, so a banked annotation payload can be rehydrated
     * and recompiled without re-calling the model.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        if (! is_string($payload['group_id'] ?? null)) {
            throw new RuntimeException('A stored candidate service carries no group ID.');
        }

        $uncertainties = [];

        foreach (is_array($payload['uncertainties'] ?? null) ? $payload['uncertainties'] : [] as $uncertainty) {
            $case = is_string($uncertainty) ? OosSemanticUncertainty::tryFrom($uncertainty) : null;

            if (! $case instanceof OosSemanticUncertainty) {
                throw new RuntimeException('A stored candidate service carries an unknown uncertainty code.');
            }

            $uncertainties[] = $case;
        }

        $boundaryLineIds = [];

        foreach (is_array($payload['boundary_line_ids'] ?? null) ? $payload['boundary_line_ids'] : [] as $lineId) {
            if (! is_int($lineId)) {
                throw new RuntimeException('A stored candidate service carries a non-integer boundary line ID.');
            }

            $boundaryLineIds[] = $lineId;
        }

        return new self(
            groupId: $payload['group_id'],
            proposedService: is_string($payload['proposed_service'] ?? null) ? $payload['proposed_service'] : null,
            boundaryLineIds: $boundaryLineIds,
            uncertainties: $uncertainties,
        );
    }

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
