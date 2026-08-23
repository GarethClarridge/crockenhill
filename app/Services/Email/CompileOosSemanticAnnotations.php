<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosCandidateService;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticCompilationResult;
use App\Data\OosSemanticFinding;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Enums\OosSemanticUncertainty;
use App\Exceptions\OosSemanticCompilationException;

class CompileOosSemanticAnnotations
{
    public function __construct(
        private readonly OosSemanticAnnotationValidator $validator,
        private readonly OosServiceDateResolver $dateResolver,
        private readonly OosSemanticIgnoredLines $ignoredLines = new OosSemanticIgnoredLines,
    ) {}

    public function compile(OosEmailSourceDocument $source, OosSemanticAnnotationResult $result): OosSemanticCompilationResult
    {
        $findings = $this->validator->validate($source, $result);

        if ($findings !== []) {
            $recovered = $this->recoverFromFindings($source, $result, $findings);

            if ($recovered === null) {
                throw new OosSemanticCompilationException($findings);
            }

            $result = $recovered;
            $findings = $this->validator->validate($source, $result);

            if ($findings !== []) {
                throw new OosSemanticCompilationException($findings);
            }
        }

        $services = $result->services;
        usort($services, fn (OosCandidateService $left, OosCandidateService $right): int => $this->firstGroupLine($result, $left->groupId) <=> $this->firstGroupLine($result, $right->groupId));
        $compiledServices = array_map(
            fn (OosCandidateService $service): array => $this->compileService($source, $result, $service),
            $services,
        );
        $ignoredLines = $this->ignoredLines->forResult($result);
        $uncertainties = $this->uncertainties($result);
        $riskSignals = [
            'implicit_or_ambiguous_boundary' => $this->hasUncertainty($uncertainties, OosSemanticUncertainty::AmbiguousBoundary),
            'unresolved_identity' => count(array_filter($compiledServices, static fn (array $service): bool => $service['service'] === null || $service['date'] === null)) > 0,
            'uncertain_annotation_count' => count($uncertainties),
            'uncertainty_codes' => array_values(array_unique(array_column($uncertainties, 'code'))),
            'forwarded_current_message_ambiguity' => $this->hasUncertainty($uncertainties, OosSemanticUncertainty::ForwardedCurrentAmbiguity),
            'targeted_repair_required' => false,
            'targeted_repair_failed' => false,
            'content_validator_findings' => [],
            'manifest_corroboration' => null,
            'openlp_corroboration' => null,
            'hymn_corroboration' => null,
            'catalogue_resolution' => null,
        ];

        $flattenedItems = [];

        foreach ($compiledServices as $compiledService) {
            $flattenedItems = [...$flattenedItems, ...$compiledService['items']];
        }

        return new OosSemanticCompilationResult(
            extraction: new OosEmailItemExtractionResult(
                items: $flattenedItems,
                confidence: 0.75,
                notes: [],
                services: $compiledServices,
                serviceCount: count($compiledServices),
                ignoredLines: $ignoredLines,
                provenanceComplete: true,
            ),
            riskSignals: $riskSignals,
        );
    }

    /**
     * Every deterministic recovery this compiler knows, applied in turn to a failing annotation.
     *
     * Each recovery is narrow and refuses the documents it does not understand, so the useful
     * question is which ones apply *together*: `2018-02-04-details` carries three findings from
     * three unrelated causes, and a single-recovery gate reports whichever one it happens to check
     * first as the whole story. Re-validating between passes means a later recovery sees the
     * findings the earlier one actually left, not the ones it started with.
     *
     * Returns the rewritten annotation when anything moved, or `null` when no recovery applied —
     * deliberately *not* "the document now validates". A partial recovery still narrows the
     * findings the caller reports, and both callers re-validate before trusting the result, so
     * neither can mistake "something moved" for "this document is now clean".
     *
     * Called from {@see self::compile()} and {@see OosSemanticParserCandidate::parse()}, which has
     * its own all-or-nothing gate on the validator's raw findings and so never reaches this
     * class's own re-validated call to compile(). One shared implementation avoids the drift a
     * second, hand-matched copy would invite.
     *
     * @param  list<OosSemanticFinding>  $findings
     */
    public function recoverFromFindings(OosEmailSourceDocument $source, OosSemanticAnnotationResult $result, array $findings): ?OosSemanticAnnotationResult
    {
        $recovered = null;

        foreach ([
            $this->normaliseNonBoundarySharedGroups(...),
            $this->salvageEmptyUnanchoredGroups(...),
        ] as $recovery) {
            $next = $recovery($result, $findings);

            if (! $next instanceof OosSemanticAnnotationResult) {
                continue;
            }

            $recovered = $result = $next;
            $findings = $this->validator->validate($source, $result);

            if ($findings === []) {
                break;
            }
        }

        return $recovered;
    }

    /**
     * Drop `shared_service_group_ids` from the non-boundary lines the validator has just rejected
     * for carrying them.
     *
     * Found 2026-08-23 sweeping the rehearsal corpus: 26 of 554 sources annotate a document-level
     * header — `# Sunday 12 August 2018` in `2018-08-12` — as `notice_context` shared by both that
     * day's service groups. Sharing is a *boundary* mechanism, for the one line that names two
     * services at once, so {@see OosSemanticAnnotationValidator} is right to reject it; the model
     * is simply being imprecise about a line that belongs to the document rather than to either
     * service. Until now the only thing that fixed it was a paid repair call, which is a
     * model round-trip spent restating a rule the schema already states.
     *
     * Removing the IDs moves the line from one half of the coverage partition to the other: a line
     * with no group at all is exactly what {@see OosSemanticIgnoredLines} claims as ignored
     * context, where {@see self::evidenceLineIds()} had been claiming it as evidence for every
     * group it named. Every line stays accounted for, which is what the validator's coverage rule
     * requires. The trade is deliberate and small — a genuinely shared notice becomes ignored
     * context instead of evidence for two services, rather than the whole document failing — and
     * it is preferred over promoting one of the shared IDs to the primary group, which would
     * invent a preference between two services the annotation never expressed.
     *
     * Unlike {@see self::salvageEmptyUnanchoredGroups()} this does not refuse a document carrying
     * unrelated findings. Salvage *drops a service group*, so it needs every finding to agree that
     * the group is spurious; this only removes IDs the validator has already declared illegal on
     * those exact lines, and can neither invent evidence nor widen a group's membership. Nor can
     * it strand a boundary line: the validator requires `role === ServiceBoundary` on every line a
     * group cites as boundary evidence, so a line reaching this rule is one no group can be citing
     * without already having been flagged.
     *
     * @param  list<OosSemanticFinding>  $findings
     */
    public function normaliseNonBoundarySharedGroups(OosSemanticAnnotationResult $result, array $findings): ?OosSemanticAnnotationResult
    {
        $lineIds = [];

        foreach ($findings as $finding) {
            if ($finding->code !== 'shared_boundary_role_invalid') {
                continue;
            }

            foreach ($finding->lineIds as $lineId) {
                $lineIds[$lineId] = true;
            }
        }

        if ($lineIds === []) {
            return null;
        }

        $rewrittenAnnotations = [];
        $normalised = false;

        foreach ($result->annotations as $lineId => $annotation) {
            if (! isset($lineIds[$annotation->lineId])
                || $annotation->role === OosSemanticRole::ServiceBoundary
                || $annotation->sharedServiceGroupIds === []) {
                $rewrittenAnnotations[$lineId] = $annotation;

                continue;
            }

            $normalised = true;
            $rewrittenAnnotations[$lineId] = new OosSemanticLineAnnotation(
                lineId: $annotation->lineId,
                role: $annotation->role,
                serviceGroupId: $annotation->serviceGroupId,
                itemKind: $annotation->itemKind,
                continuationTargetLineId: $annotation->continuationTargetLineId,
                uncertainty: $annotation->uncertainty,
                sharedServiceGroupIds: [],
                boundaryAlsoItem: $annotation->boundaryAlsoItem,
            );
        }

        if (! $normalised) {
            return null;
        }

        return new OosSemanticAnnotationResult($result->services, $rewrittenAnnotations, $result->telemetry);
    }

    /**
     * Cause B (IC3 item 15, 2026-08-22): `parse()`'s all-or-nothing failure discards a fully
     * clean, anchored service group along with a genuinely empty, unanchored sibling — the
     * deferred "evening hymns to follow" half of a two-service email that the annotator declares
     * as a group but never annotates an item for. Corpus-checked against `2018-01-07` and
     * `2018-02-04-details`: the empty group still typically owns a `notice_context` line (the
     * "hymns to follow" sentence itself), so the salvageable condition is zero *item* annotations,
     * not zero annotations of any kind — every other `service_boundary_missing` source in the
     * corpus is a single-group document with nothing to salvage either way, so this narrow rule
     * covers every case found without a general salvage architecture.
     *
     * Dropping the group's service entry alone would strand any non-item line still pointing at
     * it — neither evidence for a service (nothing compiles that group any more) nor eligible for
     * {@see OosSemanticIgnoredLines}, which deliberately skips any line still carrying a service
     * group ID. So the orphaned lines are re-homed to no group, which is exactly what makes that
     * class treat them as ignored context instead. A `service_boundary` or `continuation` role
     * line cannot be re-homed that way without inventing evidence it never had, so the group is
     * refused as salvageable if one is found — a document inconsistency the compile-time failure
     * should still surface rather than paper over.
     *
     * Public because {@see self::recoverFromFindings()} sequences it alongside the other
     * recoveries, and both {@see self::compile()} and {@see OosSemanticParserCandidate::parse()}
     * enter through that one door.
     *
     * @param  list<OosSemanticFinding>  $findings
     */
    public function salvageEmptyUnanchoredGroups(OosSemanticAnnotationResult $result, array $findings): ?OosSemanticAnnotationResult
    {
        $droppableGroupIds = [];

        foreach ($findings as $finding) {
            if ($finding->code !== 'service_boundary_missing' || $finding->groupId === null) {
                return null;
            }

            $droppableGroupIds[$finding->groupId] = true;
        }

        $remainingServices = array_values(array_filter(
            $result->services,
            static fn (OosCandidateService $service): bool => ! isset($droppableGroupIds[$service->groupId]),
        ));

        if ($remainingServices === [] || count($remainingServices) === count($result->services)) {
            return null;
        }

        $rewrittenAnnotations = [];

        foreach ($result->annotations as $lineId => $annotation) {
            $belongsToDroppedGroup = isset($droppableGroupIds[$annotation->serviceGroupId])
                || array_any($annotation->sharedServiceGroupIds, static fn (string $groupId): bool => isset($droppableGroupIds[$groupId]));

            if (! $belongsToDroppedGroup) {
                $rewrittenAnnotations[$lineId] = $annotation;

                continue;
            }

            $isItemLike = $annotation->role === OosSemanticRole::Item
                || $annotation->boundaryAlsoItem
                || $annotation->role === OosSemanticRole::Continuation
                || $annotation->role === OosSemanticRole::ServiceBoundary;

            if ($isItemLike) {
                return null;
            }

            $rewrittenAnnotations[$lineId] = new OosSemanticLineAnnotation(
                lineId: $annotation->lineId,
                role: $annotation->role,
                serviceGroupId: null,
                itemKind: $annotation->itemKind,
                continuationTargetLineId: $annotation->continuationTargetLineId,
                uncertainty: $annotation->uncertainty,
                sharedServiceGroupIds: array_values(array_filter(
                    $annotation->sharedServiceGroupIds,
                    static fn (string $groupId): bool => ! isset($droppableGroupIds[$groupId]),
                )),
                boundaryAlsoItem: $annotation->boundaryAlsoItem,
            );
        }

        return new OosSemanticAnnotationResult($remainingServices, $rewrittenAnnotations, $result->telemetry);
    }

    /**
     * @return array{service:?string,date:?string,content_scope:string,service_evidence_line_ids:list<int>,items:list<array{type:string,title:string,source_line_ids:list<int>,continuation:bool,semantic_kind:?string}>,confidence:float}
     */
    private function compileService(OosEmailSourceDocument $source, OosSemanticAnnotationResult $result, OosCandidateService $service): array
    {
        $items = [];
        $annotations = $result->annotations;
        ksort($annotations);

        foreach ($annotations as $annotation) {
            if ($annotation->serviceGroupId !== $service->groupId) {
                continue;
            }

            if ($annotation->role === OosSemanticRole::Continuation) {
                $lastIndex = array_key_last($items);

                if ($lastIndex === null) {
                    throw new \LogicException('A validated continuation must follow an item.');
                }

                $items[$lastIndex]['source_line_ids'][] = $annotation->lineId;
                $items[$lastIndex]['title'] = $source->textFor($items[$lastIndex]['source_line_ids']) ?? $items[$lastIndex]['title'];
                $items[$lastIndex]['continuation'] = true;

                continue;
            }

            if ($annotation->role !== OosSemanticRole::Item && ! $annotation->boundaryAlsoItem) {
                continue;
            }

            $kind = $annotation->itemKind;
            $items[] = [
                'type' => $this->canonicalType($kind),
                'title' => $source->line($annotation->lineId) ?? '',
                'source_line_ids' => [$annotation->lineId],
                'continuation' => false,
                'semantic_kind' => $kind?->value,
            ];
        }

        return [
            'service' => $service->proposedService,
            'date' => $this->dateResolver->resolve($source, $service->boundaryLineIds),
            'content_scope' => $this->contentScope($service, $items),
            'service_evidence_line_ids' => $this->evidenceLineIds($result, $service),
            'items' => $items,
            'confidence' => 0.75,
        ];
    }

    /**
     * `full` claims the email presents that service's complete running order, and a consumer acts on
     * the claim: the church-service projector builds a service's item list from its
     * `payload_complete` source records, with no review gate in front of that filter. An incomplete
     * order presented as complete is therefore the costly direction.
     *
     * The annotation alone cannot carry that claim. `incomplete_service` is its only signal, and
     * that code is defined nowhere in the prompt or the schema, so `full` is the *absence* of a flag
     * rather than an assertion of completeness — the annotator and the model were each guessing at
     * the same undefined term, and disagreed on eight plans of the Delivery 6 corpus.
     *
     * A running order is `full` only when it also contains at least one structural frame item — the
     * parts a service has because it is a service. A plan of nothing but songs, readings and a
     * sermon heading is a *contribution* to an order, not the order itself: that is the shape of
     * every "here are the hymns for tomorrow" email, and of every second-service stub in a
     * two-service email. The flag is still honoured, so the model may only ever narrow the claim.
     *
     * The rule downgrades and never upgrades, so a wrong answer holds a plan for review rather than
     * misfiling one as complete.
     *
     * @param  list<array{type:string,title:string,source_line_ids:list<int>,continuation:bool,semantic_kind:?string}>  $items
     */
    private function contentScope(OosCandidateService $service, array $items): string
    {
        if (in_array(OosSemanticUncertainty::IncompleteService, $service->uncertainties, true)) {
            return 'partial';
        }

        foreach ($items as $item) {
            if (OosSemanticItemKind::tryFrom($item['semantic_kind'] ?? '')?->structuralFrame() === true) {
                return 'full';
            }
        }

        return 'partial';
    }

    private function canonicalType(?OosSemanticItemKind $kind): string
    {
        return match ($kind) {
            OosSemanticItemKind::Welcome => 'welcome',
            OosSemanticItemKind::Prayer => 'prayer',
            OosSemanticItemKind::Notices => 'notices',
            OosSemanticItemKind::Song => 'song',
            OosSemanticItemKind::ChildrensTalk => 'childrens_talk',
            OosSemanticItemKind::BibleReading => 'bible_reading',
            OosSemanticItemKind::Sermon => 'sermon',
            default => 'other',
        };
    }

    /** @return list<int> */
    private function evidenceLineIds(OosSemanticAnnotationResult $result, OosCandidateService $service): array
    {
        $lineIds = $service->boundaryLineIds;

        foreach ($result->annotations as $annotation) {
            if ($annotation->role === OosSemanticRole::Item || $annotation->role === OosSemanticRole::Continuation) {
                continue;
            }

            if ($annotation->serviceGroupId === $service->groupId
                || in_array($service->groupId, $annotation->sharedServiceGroupIds, true)) {
                $lineIds[] = $annotation->lineId;
            }
        }

        $lineIds = array_values(array_unique($lineIds));
        sort($lineIds);

        return $lineIds;
    }

    /** @return list<array{line_id:?int,code:string}> */
    private function uncertainties(OosSemanticAnnotationResult $result): array
    {
        $uncertainties = [];

        foreach ($result->services as $service) {
            foreach ($service->uncertainties as $uncertainty) {
                $uncertainties[] = ['line_id' => null, 'code' => $uncertainty->value];
            }
        }

        foreach ($result->annotations as $annotation) {
            if ($annotation->uncertainty instanceof OosSemanticUncertainty) {
                $uncertainties[] = ['line_id' => $annotation->lineId, 'code' => $annotation->uncertainty->value];
            }
        }

        return $uncertainties;
    }

    /** @param list<array{line_id:?int,code:string}> $uncertainties */
    private function hasUncertainty(array $uncertainties, OosSemanticUncertainty $uncertainty): bool
    {
        return in_array($uncertainty->value, array_column($uncertainties, 'code'), true);
    }

    private function firstGroupLine(OosSemanticAnnotationResult $result, string $groupId): int
    {
        $lineIds = [];

        foreach ($result->annotations as $annotation) {
            if ($annotation->serviceGroupId === $groupId || in_array($groupId, $annotation->sharedServiceGroupIds, true)) {
                $lineIds[] = $annotation->lineId;
            }
        }

        return $lineIds === [] ? PHP_INT_MAX : min($lineIds);
    }
}
