<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosSemanticAnnotationPatch
{
    /**
     * @param  array<int, OosSemanticLineAnnotation>  $replacements
     * @param  array<string, mixed>  $telemetry
     */
    public function __construct(
        public array $replacements,
        public array $telemetry = [],
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(
            static fn (OosSemanticLineAnnotation $annotation): array => $annotation->toArray(),
            $this->replacements,
        );
    }
}
