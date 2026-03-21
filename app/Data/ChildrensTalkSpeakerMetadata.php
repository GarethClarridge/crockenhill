<?php

declare(strict_types=1);

namespace App\Data;

final class ChildrensTalkSpeakerMetadata extends JsonData
{
    /**
     * @param  array<string, mixed>|null  $predicted
     * @param  array<string, mixed>|null  $reviewed
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?array $predicted = null,
        public readonly ?array $reviewed = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $predicted = $value['predicted'] ?? null;
        $reviewed = $value['reviewed'] ?? null;

        return new self(
            predicted: is_array($predicted) ? $predicted : null,
            reviewed: is_array($reviewed) ? $reviewed : null,
            raw: $value,
        );
    }

    /**
     * @return array{preacher_id:int|null,preacher_name:string,source:string,confidence:float|null}|null
     */
    public function publicationSpeaker(): ?array
    {
        if (! is_array($this->reviewed)) {
            return null;
        }

        $name = self::stringOrNull($this->reviewed['preacher_name'] ?? null);

        if ($name === null) {
            return null;
        }

        $source = self::stringOrNull($this->reviewed['source'] ?? null) ?? 'manual';

        return [
            'preacher_id' => self::intOrNull($this->reviewed['preacher_id'] ?? null),
            'preacher_name' => $name,
            'source' => $source,
            'confidence' => self::floatOrNull($this->reviewed['confidence'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->predicted !== null) {
            $data['predicted'] = $this->predicted;
        }

        if ($this->reviewed !== null) {
            $data['reviewed'] = $this->reviewed;
        }

        return $data;
    }
}
