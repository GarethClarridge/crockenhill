<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ProcessingId3Metadata extends JsonData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $title = null,
        public ?string $preacher = null,
        public ?string $series = null,
        public ?string $reference = null,
        public array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        return new self(
            title: self::stringOrNull($value['title'] ?? null),
            preacher: self::stringOrNull($value['preacher'] ?? null),
            series: self::stringOrNull($value['series'] ?? null),
            reference: self::stringOrNull($value['reference'] ?? null),
            raw: $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        if ($this->preacher !== null) {
            $data['preacher'] = $this->preacher;
        }

        if ($this->series !== null) {
            $data['series'] = $this->series;
        }

        if ($this->reference !== null) {
            $data['reference'] = $this->reference;
        }

        return $data;
    }
}
