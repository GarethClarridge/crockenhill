<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\TalkTypeInput;
use App\Data\TalkTypeProposal;
use App\Enums\TalkType;

/**
 * Proposal-only ordered rules. Unresolved results retain evidence for B3; this class never calls a model.
 *
 * @phpstan-import-type Facts from TalkTypeEvidenceExtractor
 */
final class TalkTypeRules
{
    public const VERSION = 'talk-type-rules-1';

    public function __construct(private readonly TalkTypeEvidenceExtractor $extractor = new TalkTypeEvidenceExtractor) {}

    public function evaluate(TalkTypeInput $input): TalkTypeProposal
    {
        $facts = $this->extractor->extract($input);
        $seriesNames = array_values(array_unique(array_column($facts['series'], 'series')));
        $series = count($seriesNames) === 1 ? $seriesNames[0] : null;
        if (! $facts['complete']) {
            return $this->result($facts, 'input.incomplete', 'Full observable section text and stable outline are required.', series: $series, disposition: 'insufficient_evidence');
        }
        if ($facts['conflicts'] !== []) {
            return $this->result($facts, 'evidence.conflict', 'Contradictory source, series or service-role evidence requires semantic assessment.', series: $series);
        }
        if ($facts['non_address_whole']) {
            if ($series !== null || $facts['family_slot'] !== [] || $facts['main_address'] !== [] || $facts['testimony'] !== []) {
                $facts['conflicts'][] = 'non_address_with_talk_evidence';

                return $this->result($facts, 'evidence.conflict', 'Explicit non-address function conflicts with talk evidence.', series: $series);
            }

            return $this->result($facts, 'function.explicit_non_address', 'The complete text only performs an explicit notice, introduction or dismissal.', disposition: 'not_a_talk', confidence: 0.95);
        }
        $familyRule = $series !== null ? (str_starts_with($facts['series'][0]['source'], 'item.') ? 'T7.bound_item' : 'T7.declared_series') : null;
        if ($familyRule !== null) {
            return $this->family($facts, $familyRule, 'Current T7 teaching series is explicitly identified.', $series);
        }
        if ($facts['testimony_whole'] && $facts['family_slot'] === [] && $facts['main_address'] === []) {
            return $this->result($facts, 'personal_testimony', 'Personal testimony frame and the complete account describe the speaker coming to and living in faith; G10 withholds standalone testimony by default.', TalkType::Testimony, confidence: 0.9);
        }
        if ($facts['main_address'] !== [] && $facts['teaching'] !== [] && $facts['application'] !== [] && ! $facts['role_features']['separate_main_address'] && ! $facts['role_features']['full_length_elsewhere']) {
            return $this->result($facts, 'service.main_address', 'Explicit main-address role with explicit teaching and application, no separate main address and no full-length sermon elsewhere; duration alone did not decide.', TalkType::Sermon, confidence: 0.9);
        }
        if ($facts['family_slot'] !== []) {
            return $this->family($facts, 'T9.morning_family_slot', 'The positively identified morning family slot is a family talk regardless of its audience.');
        }
        if ($facts['children'] !== [] && $facts['teaching'] !== [] && $facts['application'] !== [] && $facts['role_features']['separate_main_address']) {
            return $this->family($facts, 'current_children_teaching', 'Direct current child audience, explicit teaching with application and a separate main address.');
        }

        return $this->result($facts, 'rules.no_decision', 'No sufficiently strong ordered rule decides; retain the full evidence for semantic assessment.');
    }

    /** @param Facts $facts */
    private function family(array $facts, string $rule, string $reason, ?string $series = null): TalkTypeProposal
    {
        if ($facts['service'] === 'evening') {
            return $this->result($facts, 'T9.evening_veto', 'Evening services have no family talks; contrary family evidence is retained.', series: $series);
        }
        if ($facts['service'] !== 'morning') {
            return $this->result($facts, 'service.unknown', 'Unknown service identity cannot establish a morning family talk.', series: $series);
        }

        return $this->result($facts, $rule, $reason, TalkType::ChildrensTalk, $series, confidence: 0.95);
    }

    /** @param Facts $facts */
    private function result(array $facts, string $rule, string $reason, ?TalkType $type = null, ?string $series = null, string $disposition = 'uncertain', ?float $confidence = null): TalkTypeProposal
    {
        $needsModel = $type === null && $disposition === 'uncertain';

        return new TalkTypeProposal(
            proposed: $type, proposedSeries: $series, disposition: $type === null ? $disposition : 'proposed',
            confidence: $confidence, reasons: [['rule_id' => $rule, 'reason' => $reason, 'evidence' => $facts]],
            decidedBy: 'rule', ruleId: $rule, status: $type === null && $disposition !== 'not_a_talk' ? 'unresolved' : 'completed',
            provenance: ['rules_version' => self::VERSION],
            signals: ['needs_model' => $needsModel, 'testimony_risk' => $facts['testimony'] !== [],
                'g10_withheld_by_default' => $facts['testimony'] !== [], 'evening_veto' => $facts['service'] === 'evening',
                'conflicts' => $facts['conflicts'], 'limitations' => $facts['limitations'],
                'role_features' => $facts['role_features'], 'evidence' => $facts],
        );
    }
}
