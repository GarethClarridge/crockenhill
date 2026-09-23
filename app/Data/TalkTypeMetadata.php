<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TalkType;

/**
 * What kind of talk a short-talk section is: the detector's proposal, and the
 * type an operator confirms. Only the reviewed type is ever published.
 */
final readonly class TalkTypeMetadata extends JsonData
{
    /**
     * @param  array<string, mixed>|null  $reviewed
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?TalkType $proposed = null,
        public ?array $reviewed = null,
        public array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $proposed = self::stringOrNull($value['proposed'] ?? null);
        $reviewed = $value['reviewed'] ?? null;

        return new self(
            proposed: $proposed === null ? null : TalkType::tryFrom($proposed),
            reviewed: is_array($reviewed) ? $reviewed : null,
            raw: $value,
        );
    }

    /**
     * The confirmed type, never the proposal.
     */
    public function publicationTalkType(): ?TalkType
    {
        $value = self::stringOrNull($this->reviewed['value'] ?? null);

        return $value === null ? null : TalkType::tryFrom($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;
        $data['proposed'] = $this->proposed?->value;

        if ($this->reviewed !== null) {
            $data['reviewed'] = $this->reviewed;
        }

        return $data;
    }
}
