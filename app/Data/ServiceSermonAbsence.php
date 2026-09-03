<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ServiceOccasion;

/**
 * The detector's structured assertion that a service genuinely held no sermon,
 * and what stood in its place.
 *
 * The projection could always *notice* this — the system prompt has always said
 * "a service has exactly ONE primary sermon unless one is genuinely absent" —
 * but it had no way to say it. On the 2024-02-11 evening the finding landed in
 * free-text run notes ("No conventional sermon, Bible reading or second song is
 * present in the transcript"), where nothing read it, and the run then failed on
 * `candidate_exceeds_maximum_duration`: a content fact discovered through the
 * wrong instrument, RMS speech duration (D1, 2026-09-03).
 *
 * This is a proposal, never a verdict. A transient detector misread is real —
 * run #935 read a whole morning service as "fragmentary opening audio" and the
 * very next run of the same recording completed with twenty sections — so an
 * unconfirmed assertion holds the service back from public rendering and keeps
 * the run's source until an operator confirms it.
 */
final readonly class ServiceSermonAbsence extends JsonData
{
    /**
     * @param  ServiceOccasion|null  $occasion  The recurring kind of service this was, when it is
     *                                          one of the known occasions; null when the detector
     *                                          could not place it.
     * @param  string  $explanation  What stood in the sermon's place, in the detector's words.
     */
    public function __construct(
        public ?ServiceOccasion $occasion,
        public string $explanation,
    ) {}

    /**
     * Build from a raw detector or metadata payload. Returns null when the
     * payload carries no explanation: an assertion nobody can read back is not
     * evidence an operator could confirm, so it is no assertion at all.
     */
    public static function fromArray(mixed $value): ?self
    {
        $payload = self::arrayValue($value);
        $explanation = self::stringOrNull($payload['explanation'] ?? null);

        if ($explanation === null) {
            return null;
        }

        $occasion = self::stringOrNull($payload['occasion'] ?? null);

        return new self(
            $occasion === null ? null : ServiceOccasion::tryFrom($occasion),
            $explanation,
        );
    }

    /**
     * @return array{occasion: string|null, explanation: string}
     */
    public function toArray(): array
    {
        return [
            'occasion' => $this->occasion?->value,
            'explanation' => $this->explanation,
        ];
    }
}
