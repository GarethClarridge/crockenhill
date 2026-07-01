<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ServiceSectionType;

/**
 * A detected service structure: the LLM's typed, timed reading of the whole
 * recording, ordered chronologically. Proposals only — nothing here reaches
 * `ServiceSectionSyncService::sync()` without passing the deterministic gate.
 */
final readonly class ServiceStructure extends JsonData
{
    /**
     * @param  list<ServiceStructureSection>  $sections  Ordered by start time
     * @param  list<string>  $notes  Run-level detector notes
     * @param  string|null  $model  The model that produced this structure
     */
    public function __construct(
        public array $sections,
        public array $notes = [],
        public ?string $model = null,
    ) {}

    /**
     * @param  list<ServiceStructureSection>  $sections
     * @param  list<string>  $notes
     */
    public static function fromSections(array $sections, array $notes = [], ?string $model = null): self
    {
        $ordered = array_values($sections);

        usort(
            $ordered,
            static fn (ServiceStructureSection $left, ServiceStructureSection $right): int => $left->startTime <=> $right->startTime
        );

        return new self($ordered, array_values($notes), $model);
    }

    public static function fromArray(mixed $value): self
    {
        $payload = self::arrayValue($value);

        $sections = [];

        foreach (is_array($payload['sections'] ?? null) ? $payload['sections'] : [] as $sectionPayload) {
            $section = ServiceStructureSection::fromArray($sectionPayload);

            if ($section instanceof ServiceStructureSection) {
                $sections[] = $section;
            }
        }

        return self::fromSections(
            $sections,
            self::stringList($payload['notes'] ?? []),
            self::stringOrNull($payload['model'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sections' => array_map(
                static fn (ServiceStructureSection $section): array => $section->toArray(),
                $this->sections
            ),
            'notes' => $this->notes,
            'model' => $this->model,
        ];
    }

    /**
     * @return list<ServiceStructureSection>
     */
    public function sectionsOfType(ServiceSectionType $type): array
    {
        return array_values(array_filter(
            $this->sections,
            static fn (ServiceStructureSection $section): bool => $section->type === $type
        ));
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }
}
