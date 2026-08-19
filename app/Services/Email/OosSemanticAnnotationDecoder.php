<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosCandidateService;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Enums\OosSemanticUncertainty;
use RuntimeException;

class OosSemanticAnnotationDecoder
{
    public function __construct(private readonly OosSemanticAnnotationSchema $schema) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $telemetry
     */
    public function decode(OosEmailSourceDocument $source, array $payload, array $telemetry = []): OosSemanticAnnotationResult
    {
        if (! is_array($payload['services'] ?? null) || ! is_array($payload['annotations'] ?? null)) {
            throw new RuntimeException('Semantic annotation response did not contain services and annotations.');
        }

        $services = [];

        foreach ($payload['services'] as $service) {
            if (! is_array($service)) {
                throw new RuntimeException('Semantic annotation response contained an invalid service declaration.');
            }

            $services[] = new OosCandidateService(
                groupId: $this->requiredString($service['group_id'] ?? null, 'service group ID'),
                proposedService: $this->nullableString($service['proposed_service'] ?? null),
                boundaryLineIds: $this->integerList($service['boundary_line_ids'] ?? null),
                uncertainties: $this->uncertainties($service['uncertainties'] ?? null),
            );
        }

        $annotations = [];

        foreach ($source->lineIds() as $lineId) {
            $key = $this->schema->lineKey($lineId);
            $annotation = $payload['annotations'][$key] ?? null;

            if (! is_array($annotation)) {
                throw new RuntimeException("Semantic annotation response omitted {$key}.");
            }

            $role = OosSemanticRole::tryFrom($this->requiredString($annotation['role'] ?? null, "{$key} role"));

            if (! $role instanceof OosSemanticRole) {
                throw new RuntimeException("Semantic annotation response returned an unknown role for {$key}.");
            }

            $annotations[$lineId] = new OosSemanticLineAnnotation(
                lineId: $lineId,
                role: $role,
                serviceGroupId: $this->nullableString($annotation['service_group_id'] ?? null),
                itemKind: $this->nullableItemKind($annotation['item_kind'] ?? null, $key),
                continuationTargetLineId: $this->nullableInteger($annotation['continuation_target_line_id'] ?? null),
                uncertainty: $this->nullableUncertainty($annotation['uncertainty'] ?? null, $key),
                sharedServiceGroupIds: $this->stringList($annotation['shared_service_group_ids'] ?? null),
                boundaryAlsoItem: ($annotation['boundary_also_item'] ?? null) === true,
            );
        }

        $expectedKeys = array_map($this->schema->lineKey(...), $source->lineIds());
        $returnedKeys = array_keys($payload['annotations']);
        sort($expectedKeys);
        sort($returnedKeys);

        if ($expectedKeys !== $returnedKeys) {
            throw new RuntimeException('Semantic annotation response contained missing or invented line keys.');
        }

        return new OosSemanticAnnotationResult($services, $annotations, $telemetry);
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Semantic annotation response has no valid {$field}.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        if (! is_array($value) || array_filter($value, static fn (mixed $item): bool => ! is_int($item)) !== []) {
            throw new RuntimeException('Semantic annotation response contained a non-integer line reference.');
        }

        return array_values($value);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || array_filter($value, static fn (mixed $item): bool => ! is_string($item)) !== []) {
            throw new RuntimeException('Semantic annotation response contained an invalid service group list.');
        }

        return array_values($value);
    }

    /** @return list<OosSemanticUncertainty> */
    private function uncertainties(mixed $value): array
    {
        return array_map(function (string $uncertainty): OosSemanticUncertainty {
            return OosSemanticUncertainty::tryFrom($uncertainty)
                ?? throw new RuntimeException("Unknown semantic uncertainty {$uncertainty}.");
        }, $this->stringList($value));
    }

    private function nullableItemKind(mixed $value, string $key): ?OosSemanticItemKind
    {
        if ($value === null) {
            return null;
        }

        return is_string($value)
            ? OosSemanticItemKind::tryFrom($value) ?? throw new RuntimeException("Unknown semantic item kind for {$key}.")
            : throw new RuntimeException("Invalid semantic item kind for {$key}.");
    }

    private function nullableUncertainty(mixed $value, string $key): ?OosSemanticUncertainty
    {
        if ($value === null) {
            return null;
        }

        return is_string($value)
            ? OosSemanticUncertainty::tryFrom($value) ?? throw new RuntimeException("Unknown semantic uncertainty for {$key}.")
            : throw new RuntimeException("Invalid semantic uncertainty for {$key}.");
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        throw new RuntimeException('Semantic annotation response contained an invalid continuation target.');
    }
}
