<?php

declare(strict_types=1);

namespace App\Data;

use RuntimeException;

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

    /**
     * The exact inverse of {@see self::toArray()}, so a banked candidate attempt can be recompiled
     * from the annotations it already stores. Telemetry is deliberately dropped: it describes the
     * model call, and a recompilation makes none.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        if (($payload['format_version'] ?? null) !== self::FormatVersion) {
            throw new RuntimeException('Stored semantic annotations are not a supported format version.');
        }

        if (! is_array($payload['services'] ?? null) || ! is_array($payload['annotations'] ?? null)) {
            throw new RuntimeException('Stored semantic annotations carry no services and annotations.');
        }

        $services = [];

        foreach ($payload['services'] as $service) {
            if (! is_array($service)) {
                throw new RuntimeException('Stored semantic annotations carry an invalid service declaration.');
            }

            $services[] = OosCandidateService::fromArray($service);
        }

        $annotations = [];

        foreach ($payload['annotations'] as $annotation) {
            if (! is_array($annotation)) {
                throw new RuntimeException('Stored semantic annotations carry an invalid line annotation.');
            }

            $hydrated = OosSemanticLineAnnotation::fromArray($annotation);
            $annotations[$hydrated->lineId] = $hydrated;
        }

        return new self($services, $annotations);
    }

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
