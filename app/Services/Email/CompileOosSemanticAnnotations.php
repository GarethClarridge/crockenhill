<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosCandidateService;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticCompilationResult;
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
            throw new OosSemanticCompilationException($findings);
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
