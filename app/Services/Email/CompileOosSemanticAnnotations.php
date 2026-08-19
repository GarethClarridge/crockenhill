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
        $ignoredLines = $this->ignoredLines($result);
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
            'content_scope' => in_array(OosSemanticUncertainty::IncompleteService, $service->uncertainties, true) ? 'partial' : 'full',
            'service_evidence_line_ids' => $this->evidenceLineIds($result, $service),
            'items' => $items,
            'confidence' => 0.75,
        ];
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

    /** @return list<array{line_id:int,reason:string}> */
    private function ignoredLines(OosSemanticAnnotationResult $result): array
    {
        $ignored = [];

        foreach ($result->annotations as $annotation) {
            if (in_array($annotation->role, [OosSemanticRole::Item, OosSemanticRole::Continuation, OosSemanticRole::ServiceBoundary], true)) {
                continue;
            }

            if ($annotation->serviceGroupId !== null || $annotation->sharedServiceGroupIds !== []) {
                continue;
            }

            $ignored[] = [
                'line_id' => $annotation->lineId,
                'reason' => match ($annotation->role) {
                    OosSemanticRole::ForwardedContext => 'forwarded_header',
                    OosSemanticRole::GreetingOrSignature => 'signature',
                    default => 'context',
                },
            ];
        }

        return $ignored;
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
