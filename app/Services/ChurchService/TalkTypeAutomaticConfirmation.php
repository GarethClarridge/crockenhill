<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\TalkType;

/**
 * Decides, from a stored talk-type proposal, whether the answer is confirmed without the operator.
 *
 * Shared by weekly and historic processing. An operator's own `reviewed` answer is never touched;
 * an earlier automatic answer is replaced or removed when the proposal no longer supports it, so
 * a changed proposal that misses the bar returns to being a question.
 */
class TalkTypeAutomaticConfirmation
{
    /**
     * Least confidence at which a children's talk or testimony proposal is confirmed (T12).
     *
     * Measured 11-17 on 331 sections: 165 confirmed at this bar with 1 error, a show-and-tell
     * labelled "not a talk" that withholds nothing a member should see.
     */
    public const float CONFIDENCE_BAR = 0.95;

    public const string RULE_CONFIRMED_TYPE = 'T12';

    public const string RULE_NOT_A_TALK = 'T11';

    /**
     * @param  array<string, mixed>  $talkTypeRecord  The `talk_type` metadata record.
     * @return array<string, mixed> The record with `reviewed` set, kept or removed.
     */
    public function apply(array $talkTypeRecord): array
    {
        $reviewed = is_array($talkTypeRecord['reviewed'] ?? null) ? $talkTypeRecord['reviewed'] : null;
        if ($reviewed !== null && ($reviewed['source'] ?? null) !== 'automatic') {
            return $talkTypeRecord;
        }

        $decision = $this->decide($talkTypeRecord);
        if ($decision === null) {
            unset($talkTypeRecord['reviewed']);

            return $talkTypeRecord;
        }

        $unchanged = $reviewed !== null
            && ($reviewed['value'] ?? null) === $decision['value']
            && ($reviewed['rule'] ?? null) === $decision['rule']
            && ($reviewed['confidence'] ?? null) === ($decision['confidence'] ?? null);
        if ($unchanged) {
            return $talkTypeRecord;
        }

        $talkTypeRecord['reviewed'] = [...$decision, 'source' => 'automatic', 'at' => now()->toIso8601String()];

        return $talkTypeRecord;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{value: string, rule: string, confidence?: float}|null
     */
    private function decide(array $record): ?array
    {
        if (($record['disposition'] ?? null) === 'not_a_talk' && ($record['proposal_status'] ?? 'completed') === 'completed') {
            return ['value' => 'none', 'rule' => self::RULE_NOT_A_TALK];
        }

        $type = TalkType::tryFrom((string) ($record['proposed'] ?? ''));
        $confidence = $record['confidence'] ?? null;
        $signals = is_array($record['signals'] ?? null) ? $record['signals'] : [];
        $conflicts = $signals['conflicts'] ?? [];

        if (($record['proposal_status'] ?? null) === 'completed'
            && in_array($type, [TalkType::ChildrensTalk, TalkType::Testimony], true)
            && is_numeric($confidence) && (float) $confidence >= self::CONFIDENCE_BAR
            && $conflicts === []) {
            return ['value' => $type->value, 'rule' => self::RULE_CONFIRMED_TYPE, 'confidence' => (float) $confidence];
        }

        return null;
    }
}
