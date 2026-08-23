<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosCurationPlan;
use App\Services\ChurchService\ChurchServiceCorpusExpectation;
use App\Services\ChurchService\SourceAdapters\EmailSourceAdapter;
use App\Support\CanonicalJson;

/**
 * Produces the corpus expectation the §9.4.6 census reconciles a staged corpus
 * against — the F1 half of round gate RG-A ("exact membership against the
 * approved manifest, zero unexplained identities").
 *
 * **Why this exists.** `ChurchServiceCorpusMembership` certifies that every item
 * in a declared membership has its exact staged revision, lineage, hashes and
 * projection. That is sound, but it can only ever describe the set it is handed,
 * and the only generator of that set read it back out of
 * `church_service_source_records`. An approved entry that held rather than
 * imported was therefore absent from both sides of the comparison and no check
 * could notice. `church.historic_corpus.expected_services` had the same shape
 * from the other direction: a scalar an operator typed, in practice from what a
 * previous run happened to stage.
 *
 * So the expectation is derived here from the approved manifest and nothing
 * else. Every field a staged revision carries is already a function of the
 * manifest — `batch_hash` is the curation plan hash the importer records as
 * `archive.curation_plan_hash`, `input_hash` is the entry's own approved
 * `sha256`, and the source key is
 * {@see OosCurationEntryFactory::sourceKey()} over the item key and resolved
 * identity — so nothing here is asserted, only restated from the authority that
 * already decided it.
 *
 * **Why entries, not just identities.** One approved entry may legitimately
 * stage more than the single service its `resolved_service` names: a Sunday
 * email routinely carries both that morning's and that evening's orders, and
 * {@see OosArchiveEvaluator} imports both deliberately rather than discarding
 * the second. Those extra identities are the `service_beyond_manifest`
 * population, and a count-based expectation cannot tell them from an
 * off-manifest service that should never have staged at all. Recording each
 * entry's `origin` — the message-id half of its source key — lets the
 * reconciler admit an extra identity exactly when its staged revision came from
 * an approved entry on that entry's approved date, and fail closed otherwise.
 */
class OosApprovedCorpus
{
    /** The artifact contract is shared with the OpenLP lane and owned by its validator. */
    public const string Format = ChurchServiceCorpusExpectation::Format;

    public const int Version = ChurchServiceCorpusExpectation::Version;

    /** This producer speaks for the Email lane; OpenLP's manifest owns its own. */
    public const string Source = 'email';

    /**
     * @return array{
     *     format:string,
     *     version:int,
     *     source:string,
     *     batch_key:string,
     *     batch_hash:string,
     *     manifest_hash:string,
     *     approved_sources:list<array<string, mixed>>,
     *     expectation_hash:string,
     * }
     */
    public function expectation(OosCurationPlan $plan): array
    {
        $sources = [];

        foreach ($plan->includes as $include) {
            $sources[] = [
                'item_key' => $include['item_key'],
                'origin' => OosCurationEntryFactory::messageId($include['item_key']),
                'source_key' => OosCurationEntryFactory::sourceKey(
                    $include['item_key'],
                    $include['resolved_service'],
                    $include['resolved_date'],
                ),
                'input_hash' => $include['sha256'],
                'identity' => [
                    'date' => $include['resolved_date'],
                    'service' => $include['resolved_service'],
                ],
                /**
                 * Carried because a partial order is retained as evidence and
                 * deliberately never projected, so the reconciler must not read
                 * its unprojected state as an unstaged identity.
                 */
                'content_scope' => $include['content_scope'],
            ];
        }

        usort(
            $sources,
            static fn (array $left, array $right): int => $left['source_key'] <=> $right['source_key'],
        );

        $expectation = [
            'format' => self::Format,
            'version' => self::Version,
            'source' => self::Source,
            'batch_key' => $plan->batchKey,
            /**
             * The plan hash, because that is what the importer writes to every
             * source revision's `batch_hash` via
             * {@see EmailSourceAdapter::adapt()}.
             * Binding the expectation to it means an expectation produced from
             * one manifest cannot certify a corpus staged from another.
             */
            'batch_hash' => $plan->planHash,
            'manifest_hash' => $plan->manifestHash,
            'approved_sources' => $sources,
        ];

        $expectation['expectation_hash'] = CanonicalJson::hash($expectation);

        return $expectation;
    }
}
