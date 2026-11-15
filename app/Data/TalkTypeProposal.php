<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TalkType;
use InvalidArgumentException;

/** A proposal cannot contain reviewed facts, bounds or publication instructions. */
final readonly class TalkTypeProposal extends JsonData
{
    public ?string $proposedSeries;

    /**
     * @param  array<array-key, mixed>  $reasons
     * @param  array<string, mixed>  $provenance  Model, prompt, schema, settings and actual tier, when applicable.
     * @param  array<string, mixed>  $signals  Completeness, testimony risk and conflicts, without clearance semantics.
     */
    public function __construct(
        public ?TalkType $proposed = null,
        ?string $proposedSeries = null,
        public string $disposition = 'uncertain',
        public ?float $confidence = null,
        public array $reasons = [],
        public ?string $decidedBy = null,
        public ?string $ruleId = null,
        public string $status = 'completed',
        public array $provenance = [],
        public array $signals = [],
    ) {
        $this->proposedSeries = self::stringOrNull($proposedSeries);
        if (($this->proposedSeries !== null && mb_strlen($this->proposedSeries) > 255)
            || ($confidence !== null && (! is_finite($confidence) || $confidence < 0 || $confidence > 1))
            || ! in_array($disposition, ['proposed', 'not_a_talk', 'none_of_these', 'uncertain', 'insufficient_evidence'], true)
            || ! in_array($status, ['completed', 'unresolved', 'failed'], true)
            || ($decidedBy !== null && ! in_array($decidedBy, ['rule', 'model'], true))
            || (($proposed !== null) !== ($disposition === 'proposed'))
            || ($status !== 'completed' && ($proposed !== null || $confidence !== null))
            || ! array_is_list($reasons)
            || count(array_filter($reasons, static fn (mixed $reason): bool => ! is_array($reason) && ! is_string($reason))) > 0) {
            throw new InvalidArgumentException('Invalid talk-type proposal.');
        }
    }

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value) || ! isset($value['disposition'])) {
            return null;
        }
        try {
            $type = $value['proposed'] ?? null;
            if ($type !== null && (! is_string($type) || TalkType::tryFrom($type) === null)) {
                return null;
            }

            return new self(
                proposed: $type === null ? null : TalkType::from($type),
                proposedSeries: $value['proposed_series'] ?? null,
                disposition: $value['disposition'],
                confidence: $value['confidence'] ?? null,
                reasons: $value['reasons'] ?? [],
                decidedBy: $value['decided_by'] ?? null,
                ruleId: $value['rule_id'] ?? null,
                status: $value['proposal_status'] ?? 'completed',
                provenance: $value['provenance'] ?? [],
                signals: $value['signals'] ?? [],
            );
        } catch (InvalidArgumentException|\TypeError) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['proposed' => $this->proposed?->value, 'proposed_series' => $this->proposedSeries,
            'disposition' => $this->disposition, 'confidence' => $this->confidence, 'reasons' => $this->reasons,
            'decided_by' => $this->decidedBy, 'rule_id' => $this->ruleId, 'proposal_status' => $this->status,
            'provenance' => $this->provenance, 'signals' => $this->signals];
    }
}
