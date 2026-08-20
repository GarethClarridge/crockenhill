<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Enums\OosSemanticUncertainty;

/**
 * The strict response schema for one source document.
 *
 * Every real body line is a required property and `additionalProperties` is false, so the model
 * cannot omit a line or invent one. That per-line shape is generated, which makes the schema's own
 * size a function of the source: OpenAI allows at most 1000 enum values across a whole schema, and
 * the first two versions of this class spent that budget twice over on the corpus's longer sources.
 *
 * Two things keep the budget constant now:
 *
 * - the per-line field schemas whose enums do not vary by line live in `$defs` and are referenced,
 *   so each of those enums is written once rather than once per line;
 * - `continuation_target_line_id` carries only the single target
 *   {@see OosSemanticContinuationRule} permits, rather than every line ID in the document.
 *
 * The second is also an accuracy change and not only a size one: a non-adjacent continuation is now
 * unsayable rather than merely refused after the fact.
 */
class OosSemanticAnnotationSchema
{
    /** @return array<string, mixed> */
    public function build(OosEmailSourceDocument $source): array
    {
        $annotationProperties = [];
        $requiredAnnotations = [];

        foreach ($source->lineIds() as $lineId) {
            $key = $this->lineKey($lineId);
            $annotationProperties[$key] = $this->lineSchema($source, $lineId);
            $requiredAnnotations[] = $key;
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['services', 'annotations'],
            '$defs' => $this->definitions(),
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
     * The shared field schemas every line's annotation references.
     *
     * A subset schema built from this one — the repairer's patch schema — must carry these with it,
     * or its `$ref`s dangle.
     *
     * @return array<string, mixed>
     */
    public function definitions(): array
    {
        return [
            'semantic_role' => [
                'type' => 'string',
                'enum' => array_column(OosSemanticRole::cases(), 'value'),
            ],
            'semantic_item_kind' => [
                'type' => ['string', 'null'],
                'enum' => [...array_column(OosSemanticItemKind::cases(), 'value'), null],
            ],
            'semantic_uncertainty' => [
                'type' => ['string', 'null'],
                'enum' => [...$this->uncertaintyValues(), null],
            ],
            'service_group_id' => ['type' => ['string', 'null']],
            'service_group_id_list' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
            // The first body line, and any line whose physical predecessor was blank, may not
            // continue anything at all.
            'no_continuation_target' => [
                'type' => ['integer', 'null'],
                'enum' => [null],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function lineSchema(OosEmailSourceDocument $source, int $lineId): array
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
                'role' => ['$ref' => '#/$defs/semantic_role'],
                'service_group_id' => ['$ref' => '#/$defs/service_group_id'],
                'item_kind' => ['$ref' => '#/$defs/semantic_item_kind'],
                'continuation_target_line_id' => $this->continuationTargetSchema($source, $lineId),
                'uncertainty' => ['$ref' => '#/$defs/semantic_uncertainty'],
                'shared_service_group_ids' => ['$ref' => '#/$defs/service_group_id_list'],
                'boundary_also_item' => ['type' => 'boolean'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function continuationTargetSchema(OosEmailSourceDocument $source, int $lineId): array
    {
        $permitted = OosSemanticContinuationRule::permittedTargetLineId($source, $lineId);

        if ($permitted === null) {
            return ['$ref' => '#/$defs/no_continuation_target'];
        }

        return [
            'type' => ['integer', 'null'],
            'enum' => [$permitted, null],
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
