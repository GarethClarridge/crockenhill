<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Enums\OosSemanticUncertainty;

class OosSemanticAnnotationSchema
{
    /** @return array<string, mixed> */
    public function build(OosEmailSourceDocument $source): array
    {
        $annotationProperties = [];
        $requiredAnnotations = [];

        foreach ($source->lineIds() as $lineId) {
            $key = $this->lineKey($lineId);
            $annotationProperties[$key] = $this->lineSchema($source->lineIds());
            $requiredAnnotations[] = $key;
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['services', 'annotations'],
            'properties' => [
                'services' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['group_id', 'proposed_service', 'boundary_line_ids', 'uncertainties'],
                        'properties' => [
                            'group_id' => ['type' => 'string'],
                            'proposed_service' => [
                                'type' => ['string', 'null'],
                                'enum' => ['morning', 'evening', 'other', null],
                            ],
                            'boundary_line_ids' => $this->lineIdArraySchema($source->lineIds()),
                            'uncertainties' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => $this->uncertaintyValues()],
                            ],
                        ],
                    ],
                ],
                'annotations' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => $requiredAnnotations,
                    'properties' => $annotationProperties,
                ],
            ],
        ];
    }

    public function lineKey(int $lineId): string
    {
        return sprintf('L%03d', $lineId);
    }

    /**
     * @param  list<int>  $lineIds
     * @return array<string, mixed>
     */
    private function lineSchema(array $lineIds): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'role',
                'service_group_id',
                'item_kind',
                'continuation_target_line_id',
                'uncertainty',
                'shared_service_group_ids',
                'boundary_also_item',
            ],
            'properties' => [
                'role' => ['type' => 'string', 'enum' => array_column(OosSemanticRole::cases(), 'value')],
                'service_group_id' => ['type' => ['string', 'null']],
                'item_kind' => [
                    'type' => ['string', 'null'],
                    'enum' => [...array_column(OosSemanticItemKind::cases(), 'value'), null],
                ],
                'continuation_target_line_id' => [
                    'type' => ['integer', 'null'],
                    'enum' => [...$lineIds, null],
                ],
                'uncertainty' => [
                    'type' => ['string', 'null'],
                    'enum' => [...$this->uncertaintyValues(), null],
                ],
                'shared_service_group_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'boundary_also_item' => ['type' => 'boolean'],
            ],
        ];
    }

    /**
     * @param  list<int>  $lineIds
     * @return array<string, mixed>
     */
    private function lineIdArraySchema(array $lineIds): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'integer', 'enum' => $lineIds],
        ];
    }

    /** @return list<string> */
    private function uncertaintyValues(): array
    {
        return array_column(OosSemanticUncertainty::cases(), 'value');
    }
}
