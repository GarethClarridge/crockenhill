<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\OosSemanticAnnotator;
use App\Contracts\OosSemanticRepairer;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticFinding;
use App\Data\OosSemanticParserOutcome;
use App\Support\CanonicalJson;
use Throwable;

class OosSemanticParserCandidate
{
    public function __construct(
        private readonly OosSemanticAnnotator $annotator,
        private readonly CompileOosSemanticAnnotations $compiler,
        private readonly OosSemanticAnnotationValidator $validator,
        private readonly ApplyOosSemanticAnnotationPatch $patcher,
        private readonly OosParserSurfaceFingerprint $fingerprint,
        private readonly OosSemanticAnnotationSchema $schema,
        private readonly OosSemanticAnnotationPrompt $prompt,
        private readonly ?OosSemanticRepairer $repairer = null,
    ) {}

    public function parse(OosEmailSourceDocument $source): OosSemanticParserOutcome
    {
        $initial = $this->annotator->annotate($source);
        $initialFindings = $this->validator->validate($source, $initial);
        $final = $initial;
        $finalFindings = $initialFindings;
        $patch = null;
        $repairFailed = false;
        $repairError = null;

        if ($this->isRepairable($initialFindings) && $this->repairer instanceof OosSemanticRepairer) {
            try {
                $patch = $this->repairer->repair($source, $initial, $initialFindings);
                $final = $this->patcher->apply($initial, $patch, $initialFindings);
                $finalFindings = $this->validator->validate($source, $final);

                if ($this->introducedRuleCodes($initialFindings, $finalFindings) !== []) {
                    throw new \RuntimeException('Semantic repair introduced a new rule family.');
                }
            } catch (Throwable $exception) {
                $repairFailed = true;
                $repairError = $exception->getMessage();
                $final = $initial;
                $finalFindings = $initialFindings;
            }
        }

        $surface = $this->fingerprint->fingerprint();
        $attempt = [
            'attempt' => 1,
            'selected' => $finalFindings === [],
            'parser' => 'semantic_annotations',
            'source_format_version' => OosEmailSourceDocument::PortableFormatVersion,
            'source_hash' => $source->inputHash(),
            'parser_surface_hash' => $surface['hash'],
            'parser_surface_files' => $surface['files'],
            'prompt_version' => OosSemanticAnnotationPrompt::Version,
            'prompt_hash' => hash('sha256', $this->prompt->text()),
            'schema_hash' => CanonicalJson::hash($this->schema->build($source)),
            'initial_annotations' => $initial->toArray(),
            'initial_rule_codes' => $this->ruleCodes($initialFindings),
            'allowed_patch' => $this->allowedPatch($initialFindings),
            'patch' => $patch?->toArray(),
            'repair_telemetry' => $patch?->telemetry,
            'final_annotations' => $final->toArray(),
            'final_rule_codes' => $this->ruleCodes($finalFindings),
            'repair_error' => $repairError,
        ];

        if ($finalFindings !== []) {
            return new OosSemanticParserOutcome(
                extraction: $this->failedExtraction($source, $finalFindings),
                attempts: [$attempt],
                riskSignals: $this->failedRiskSignals($finalFindings, $initialFindings !== [], $repairFailed),
            );
        }

        $compiled = $this->compiler->compile($source, $final);
        $compilation = [
            'extraction' => $this->extractionArray($compiled->extraction),
            'risk_signals' => $compiled->riskSignals,
        ];
        $attempt['compilation'] = $compilation;
        $attempt['compilation_hash'] = CanonicalJson::hash($compilation);

        return new OosSemanticParserOutcome(
            extraction: $compiled->extraction,
            attempts: [$attempt],
            riskSignals: [
                ...$compiled->riskSignals,
                'targeted_repair_required' => $initialFindings !== [],
                'targeted_repair_failed' => $repairFailed,
            ],
        );
    }

    /** @param list<OosSemanticFinding> $findings */
    private function isRepairable(array $findings): bool
    {
        if ($findings === []) {
            return false;
        }

        foreach ($findings as $finding) {
            if ($finding->lineIds === [] || $finding->repairableFields === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<OosSemanticFinding>  $findings
     * @return list<string>
     */
    private function ruleCodes(array $findings): array
    {
        return array_values(array_unique(array_map(
            static fn (OosSemanticFinding $finding): string => $finding->code,
            $findings,
        )));
    }

    /**
     * @param  list<OosSemanticFinding>  $initial
     * @param  list<OosSemanticFinding>  $final
     * @return list<string>
     */
    private function introducedRuleCodes(array $initial, array $final): array
    {
        return array_values(array_diff($this->ruleCodes($final), $this->ruleCodes($initial)));
    }

    /**
     * @param  list<OosSemanticFinding>  $findings
     * @return array<int, list<string>>
     */
    private function allowedPatch(array $findings): array
    {
        $allowed = [];

        foreach ($findings as $finding) {
            foreach ($finding->lineIds as $lineId) {
                $allowed[$lineId] = array_values(array_unique([
                    ...($allowed[$lineId] ?? []),
                    ...$finding->repairableFields,
                ]));
            }
        }

        return $allowed;
    }

    /** @param list<OosSemanticFinding> $findings */
    private function failedExtraction(OosEmailSourceDocument $source, array $findings): OosEmailItemExtractionResult
    {
        return new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.0,
            notes: array_map(static fn (OosSemanticFinding $finding): string => $finding->message, $findings),
            services: [],
            serviceCount: 0,
            ignoredLines: array_map(
                static fn (int $lineId): array => ['line_id' => $lineId, 'reason' => 'context'],
                $source->lineIds(),
            ),
            provenanceComplete: true,
        );
    }

    /**
     * @param  list<OosSemanticFinding>  $findings
     * @return array<string, mixed>
     */
    private function failedRiskSignals(array $findings, bool $repairRequired, bool $repairFailed): array
    {
        return [
            'implicit_or_ambiguous_boundary' => false,
            'unresolved_identity' => true,
            'uncertain_annotation_count' => 0,
            'uncertainty_codes' => [],
            'forwarded_current_message_ambiguity' => false,
            'targeted_repair_required' => $repairRequired,
            'targeted_repair_failed' => $repairFailed,
            'content_validator_findings' => array_map(
                static fn (OosSemanticFinding $finding): array => $finding->toArray(),
                $findings,
            ),
            'manifest_corroboration' => null,
            'openlp_corroboration' => null,
            'hymn_corroboration' => null,
            'catalogue_resolution' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function extractionArray(OosEmailItemExtractionResult $extraction): array
    {
        return [
            'items' => $extraction->items,
            'confidence' => $extraction->confidence,
            'notes' => $extraction->notes,
            'services' => $extraction->services,
            'service_count' => $extraction->serviceCount,
            'ignored_lines' => $extraction->ignoredLines,
            'provenance_complete' => $extraction->provenanceComplete,
        ];
    }
}
