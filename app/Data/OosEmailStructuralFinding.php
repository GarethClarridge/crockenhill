<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosEmailStructuralFindingRule;

/**
 * One bookkeeping finding, kept at the scope it was actually observed at.
 *
 * The rules that produce these all know the offending source line at the moment they fire, but
 * used to flatten it into a prose reason. Nothing downstream could then recover it, so the archive
 * census attributed a finding to every item of the plan — and, for a document-wide reason, to
 * every plan of the email. Recording the line, the plan and the items the line sits between lets
 * the census say which part of an order a hold concerns.
 *
 * `planIndex` is null only when the line falls outside every plan's item span: a trailing
 * appendix or a sign-off belongs to the document, not to a service.
 */
readonly class OosEmailStructuralFinding
{
    public function __construct(
        public OosEmailStructuralFindingRule $rule,
        public int $lineId,
        public ?int $planIndex = null,
        public ?string $precedingItemType = null,
        public ?string $followingItemType = null,
    ) {}

    /**
     * The item types either side of the offending line, resolved once the whole plan has been
     * walked — the rules fire mid-walk, when the following item is not yet known.
     *
     * @param  list<array{line_id:int,type:string}>  $planItemLines
     */
    public function withAdjacentItemTypes(array $planItemLines): self
    {
        $preceding = null;
        $following = null;

        foreach ($planItemLines as $itemLine) {
            if ($itemLine['line_id'] < $this->lineId) {
                $preceding = $itemLine['type'];
            } elseif ($itemLine['line_id'] > $this->lineId && $following === null) {
                $following = $itemLine['type'];
            }
        }

        return new self($this->rule, $this->lineId, $this->planIndex, $preceding, $following);
    }

    public function withPlanIndex(?int $planIndex): self
    {
        return new self($this->rule, $this->lineId, $planIndex, $this->precedingItemType, $this->followingItemType);
    }

    /**
     * @return array{rule:string,line_id:int,plan_index:?int,preceding_item_type:?string,following_item_type:?string}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule->value,
            'line_id' => $this->lineId,
            'plan_index' => $this->planIndex,
            'preceding_item_type' => $this->precedingItemType,
            'following_item_type' => $this->followingItemType,
        ];
    }
}
