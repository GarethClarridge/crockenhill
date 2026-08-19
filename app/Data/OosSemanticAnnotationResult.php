<?php

declare(strict_types=1);

namespace App\Data;

readonly class OosSemanticAnnotationResult
{
    public const int FormatVersion = 1;

    /**
     * @param  list<OosCandidateService>  $services
     * @param  array<int, OosSemanticLineAnnotation>  $annotations
     * @param  array<string, mixed>  $telemetry
     */
    public function __construct(
        public array $services,
        public array $annotations,
        public array $telemetry = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format_version' => self::FormatVersion,
            'services' => array_map(
                static fn (OosCandidateService $service): array => $service->toArray(),
                $this->services,
            ),
            'annotations' => array_map(
                static fn (OosSemanticLineAnnotation $annotation): array => $annotation->toArray(),
                $this->annotations,
            ),
            'telemetry' => $this->telemetry,
        ];
    }
}
