<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The curated occasion/title/speaker/scripture/series facts an operator
 * adjudicated while building the historic video manifest.
 *
 * These are hand-verified and hash-covered by the approved manifest, so they
 * outrank both ID3 tags and AI analysis wherever they are present. The final
 * import readiness plan's F44 requires them to survive the one-time import
 * rather than be re-derived from a filename, which is what leaves historic
 * sermons titled only "Morning" or "Evening".
 */
final readonly class HistoricEditorialFacts extends JsonData
{
    public function __construct(
        public ?string $occasion = null,
        public ?string $title = null,
        public ?string $speaker = null,
        public ?string $scriptureReference = null,
        public ?string $series = null,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $facts = new self(
            occasion: self::stringOrNull($value['occasion'] ?? null),
            title: self::stringOrNull($value['title'] ?? null),
            speaker: self::stringOrNull($value['speaker'] ?? null),
            scriptureReference: self::stringOrNull($value['scripture_reference'] ?? null),
            series: self::stringOrNull($value['series'] ?? null),
        );

        return $facts->isEmpty() ? null : $facts;
    }

    /**
     * A manifest entry always declares all five keys, so an entry whose facts
     * were all left null carries no authority and must not be mistaken for one
     * that does.
     */
    public function isEmpty(): bool
    {
        return $this->occasion === null
            && $this->title === null
            && $this->speaker === null
            && $this->scriptureReference === null
            && $this->series === null;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'occasion' => $this->occasion,
            'title' => $this->title,
            'speaker' => $this->speaker,
            'scripture_reference' => $this->scriptureReference,
            'series' => $this->series,
        ];
    }
}
