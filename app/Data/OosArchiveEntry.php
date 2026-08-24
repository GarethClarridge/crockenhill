<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use App\Services\Email\OosArchiveAssertionBundle;
use App\Services\Email\OosArchiveIdentityResolver;
use App\Services\Email\OosCurationManifest;
use Carbon\CarbonImmutable;

/**
 * One approved include from the §7.5 Email curation manifest, in the shape the
 * evaluation and import pipeline consumes.
 *
 * Every identity field here is a *manifest decision*, not an inference. The
 * predecessor of this DTO carried `headingDate`, `correctedDate` and a
 * `labelQuality` of `full`/`unverified`, all derived by regex from a heading
 * string in the superseded aggregate archive. §7.3 makes the approved manifest
 * mutation authority, so those fields are gone rather than re-fed: a resolved
 * date living in a field named after the heading it was guessed from is a
 * decision wearing an inference's name.
 *
 * Nothing is nullable that the manifest guarantees.
 * {@see OosCurationManifest::validateInclude()} has already
 * proven the date and the service, so the pipeline has no unresolved-entry
 * branch to carry.
 *
 * @phpstan-type OosEntryCuration array{
 *     date_decision:string,
 *     date_decision_reason:?string,
 *     parse_decision:string,
 *     service_assignments:list<array{source_service:string,resolved_service:string}>,
 *     content_scope:string,
 *     partial_scope_reason:?string,
 *     payload:string,
 *     service_label:?string,
 *     title_override:?string,
 *     supersedes:?string,
 *     expected_item_count:?int,
 *     decided_by:?string,
 *     decided_at:?string,
 *     decision_rule_version:?string
 * }
 */
readonly class OosArchiveEntry
{
    /**
     * @param  string  $contentScope  `full` or `partial`, straight from the manifest. A partial
     *                                asserts only part of an order — §8.4 forbids reading its
     *                                silence as disagreement — so it never imports unattended.
     * @param  list<string>  $servicesPresent  every service slot this *document* carries:
     *                                         `resolved_service` first, then the manifest's
     *                                         `additional_services`. The parser may propose several
     *                                         plans and only those this list corroborates may
     *                                         import. **Element 0 is identity-bearing and the order
     *                                         is a contract**, not an implementation detail:
     *                                         {@see OosArchiveAssertionBundle} builds `plan_key`
     *                                         from it, {@see OosArchiveIdentityResolver} falls back
     *                                         to it when a plan names no service, and
     *                                         {@see FreezeOosSemanticEvaluationCorpus} keys the
     *                                         frozen corpus on it. Only `resolved_service` is half
     *                                         the source key, so only element 0 may fill those
     *                                         roles — never `additional_services`.
     * @param  array<string, int>  $itemLineCounts  service => human-asserted item count. Empty
     *                                              unless someone read the source and said so;
     *                                              §7.5 keeps heuristic counts out of it.
     * @param  OosEntryCuration  $curation  the decisions that authorised this entry, carried into
     *                                      the evaluation report so an auditor can see on whose
     *                                      authority each entry was processed.
     */
    public function __construct(
        public int $index,
        public string $itemKey,
        public string $subject,
        public string $bodyPlain,
        public string $groundTruthDate,
        public string $contentScope,
        public array $servicesPresent,
        public array $itemLineCounts,
        public array $curation,
        public string $syntheticMessageId,
        public string $sourceKey,
        public ?string $supersedesSourceKey,
        public string $inputHash,
        public CarbonImmutable $syntheticReceivedAt,
    ) {}

    public function assertsFullOrder(): bool
    {
        return $this->contentScope === 'full';
    }
}
