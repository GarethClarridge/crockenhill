<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\OosEmailItemExtractor;
use App\Contracts\OosSemanticAnnotator;
use App\Data\OosCandidateService;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Enums\OosSemanticUncertainty;
use App\Models\InboundEmail;
use App\Services\ChurchService\ServiceItemTitleCleaner;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Executes the §6.3 gate 4 safety fixtures through the real parser, not through a description of it.
 *
 * Each fixture is driven end to end by {@see OosEmailParserService}, so "held" means what production
 * means by it — {@see OosEmailServicePlan::isAutoImportable()} and
 * {@see OosEmailServicePlan::isEvidenceImportable()} answering no — rather than a rule this class
 * restates and could drift from. Nothing is written and no model is called: the annotator and the
 * legacy extractor are replaced by fixed fakes that return the fixture's own defective output.
 *
 * This surface is deleted with the rest of the Delivery 0 evaluation tooling at an accepted
 * historic-import IC8 closeout.
 *
 * Retention amended 2026-08-20: the Delivery 6 comparison artifact is now accepted, which under the
 * original wording would have made this deletable immediately. It is not. §9.12 requires
 * `outcome_rate` to be re-read on any future arm rather than treated as settled, and the accepted
 * 28.9% has a named remedy (the item-kind arm) that needs this surface to execute. Deleting on
 * Delivery 6 acceptance would strand the plan without the tooling to act on its own caveat, so the
 * trigger is now historic-import IC8 closeout only.
 */
class RunOosSemanticSafetyFixtures
{
    public const string Format = 'crockenhill-oos-semantic-safety-fixture-results';

    public const int Version = 1;

    public function __construct(
        private readonly OosSemanticSafetyFixtures $fixtures,
        private readonly ExistingEmailImportLookup $existingEmailImports,
        private readonly ServiceItemTitleCleaner $titleCleaner,
    ) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        $implementation = config('service-tracking.email_parsing.implementation');
        $results = [];

        try {
            foreach ($this->fixtures->all() as $fixture) {
                $results[] = $this->execute($fixture);
            }
        } finally {
            config()->set('service-tracking.email_parsing.implementation', $implementation);
        }

        $unsatisfied = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['satisfied'] !== true,
        ));

        $artifact = [
            'format' => self::Format,
            'version' => self::Version,
            'fixtures_format' => OosSemanticSafetyFixtures::Format,
            'fixtures_version' => OosSemanticSafetyFixtures::Version,
            'summary' => [
                'fixtures' => count($results),
                'satisfied' => count($results) - count($unsatisfied),
                'unsatisfied' => count($unsatisfied),
                'unsatisfied_names' => array_column($unsatisfied, 'name'),
                'content_invalid_false_accepts' => count(array_filter(
                    $results,
                    static fn (array $result): bool => $result['expectation'] !== OosSemanticSafetyFixtures::ExpectAutoImportable
                        && $result['observed']['auto_importable_plan_keys'] !== [],
                )),
            ],
            'results' => $results,
        ];
        $artifact['fixture_results_hash'] = CanonicalJson::hash($artifact);

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function execute(array $fixture): array
    {
        $parse = $this->parse($fixture);
        $autoImportable = [];
        $evidenceImportable = [];
        $dispositions = [];

        foreach ($parse->servicePlans as $plan) {
            $dispositions[] = ['plan_key' => $plan->key(), 'disposition' => $plan->disposition->value, 'hold_reasons' => $plan->holdReasonValues()];

            if ($plan->isAutoImportable()) {
                $autoImportable[] = $plan->key();
            }

            if ($plan->isEvidenceImportable()) {
                $evidenceImportable[] = $plan->key();
            }
        }

        $attempt = $parse->extractionAttempts[0] ?? [];
        $observed = [
            'compiled_service_count' => count(array_filter(
                $parse->servicePlans,
                static fn (OosEmailServicePlan $plan): bool => $plan->items !== [],
            )),
            'primary_disposition' => $parse->disposition->value,
            'plans' => $dispositions,
            'auto_importable_plan_keys' => $autoImportable,
            'evidence_importable_plan_keys' => $evidenceImportable,
            'semantic_rule_codes' => $this->stringList($attempt['final_rule_codes'] ?? null),
            'content_rule_codes' => $this->stringList($attempt['validation_rule_codes']['content'] ?? ($attempt['compatibility_validation']['validation_rule_codes']['content'] ?? null)),
            'bookkeeping_rule_codes' => $this->stringList($attempt['validation_rule_codes']['bookkeeping'] ?? ($attempt['compatibility_validation']['validation_rule_codes']['bookkeeping'] ?? null)),
        ];

        return [
            'name' => $fixture['name'],
            'family' => $fixture['family'],
            'layer' => $fixture['layer'],
            'expectation' => $fixture['expectation'],
            'observed' => $observed,
            'satisfied' => $this->satisfies($fixture['expectation'], $observed),
        ];
    }

    /**
     * The expectation, read only off what production decided.
     *
     * `auto_importable_plan_keys` and `evidence_importable_plan_keys` come from the plan objects
     * themselves, so a change to what the pipeline will import unattended moves this check with it.
     *
     * @param  array<string, mixed>  $observed
     */
    private function satisfies(string $expectation, array $observed): bool
    {
        $unattended = $observed['auto_importable_plan_keys'] !== [] || $observed['evidence_importable_plan_keys'] !== [];

        return match ($expectation) {
            OosSemanticSafetyFixtures::ExpectRefusedBeforeCompilation => ! $unattended
                && $observed['compiled_service_count'] === 0
                && $observed['semantic_rule_codes'] !== [],
            OosSemanticSafetyFixtures::ExpectContentInvalid => ! $unattended
                && $observed['primary_disposition'] === 'invalid_extraction',
            OosSemanticSafetyFixtures::ExpectHeldForReview => $observed['auto_importable_plan_keys'] === []
                && $observed['primary_disposition'] === 'review_required',
            OosSemanticSafetyFixtures::ExpectAutoImportable => $observed['auto_importable_plan_keys'] !== [],
            default => throw new RuntimeException("Unknown safety fixture expectation {$expectation}."),
        };
    }

    /** @param array<string, mixed> $fixture */
    private function parse(array $fixture): OosEmailParseResult
    {
        $email = new InboundEmail;
        $email->subject = $fixture['subject'];
        $email->body_plain = $fixture['body'];
        $email->message_id = "safety-fixture-{$fixture['name']}";
        $email->received_at = Carbon::parse($fixture['received_date']);

        config()->set(
            'service-tracking.email_parsing.implementation',
            $fixture['layer'] === 'annotation' ? 'semantic_annotations' : 'legacy',
        );

        return $this->parser($fixture)->parse($email);
    }

    /** @param array<string, mixed> $fixture */
    private function parser(array $fixture): OosEmailParserService
    {
        $validator = new OosSemanticAnnotationValidator;

        return new OosEmailParserService(
            $this->legacyExtractor($fixture),
            $this->existingEmailImports,
            $this->titleCleaner,
            semanticParser: new OosSemanticParserCandidate(
                $this->annotator($fixture),
                new CompileOosSemanticAnnotations($validator, new OosServiceDateResolver),
                $validator,
                new ApplyOosSemanticAnnotationPatch,
                new OosParserSurfaceFingerprint,
                new OosSemanticAnnotationSchema,
                new OosSemanticAnnotationPrompt,
                // No repairer on purpose: a fixture proves the *validator* refuses the defect, and a
                // repair that rescued one would hide which layer did the holding.
                repairer: null,
            ),
        );
    }

    /** @param array<string, mixed> $fixture */
    private function annotator(array $fixture): OosSemanticAnnotator
    {
        $result = $fixture['annotation'] === null
            ? new OosSemanticAnnotationResult([], [])
            : $this->annotationResult($fixture['annotation']);

        return new class($result) implements OosSemanticAnnotator
        {
            public function __construct(private readonly OosSemanticAnnotationResult $result) {}

            public function annotate(OosEmailSourceDocument $source): OosSemanticAnnotationResult
            {
                return $this->result;
            }
        };
    }

    /** @param array<string, mixed> $fixture */
    private function legacyExtractor(array $fixture): OosEmailItemExtractor
    {
        $extraction = $fixture['extraction'] === null
            ? new OosEmailItemExtractionResult([], 0.0, provenanceComplete: true)
            : new OosEmailItemExtractionResult(
                items: $fixture['extraction']['items'],
                confidence: $fixture['extraction']['confidence'],
                services: $fixture['extraction']['services'],
                serviceCount: count($fixture['extraction']['services']),
                ignoredLines: $fixture['extraction']['ignored_lines'],
                provenanceComplete: true,
            );

        return new class($extraction) implements OosEmailItemExtractor
        {
            public function __construct(private readonly OosEmailItemExtractionResult $extraction) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return $this->extraction;
            }
        };
    }

    /**
     * Build the annotation result directly rather than through {@see OosSemanticAnnotationDecoder}.
     *
     * The decoder enforces membership before the validator ever sees the response, so a fixture for
     * a missing or invented line identity could not reach the rule it exists to exercise if it were
     * decoded first.
     *
     * @param  array<string, mixed>  $annotation
     */
    private function annotationResult(array $annotation): OosSemanticAnnotationResult
    {
        $services = array_map(
            static fn (array $service): OosCandidateService => new OosCandidateService(
                groupId: $service['group_id'],
                proposedService: $service['proposed_service'],
                boundaryLineIds: array_values($service['boundary_line_ids']),
                uncertainties: array_values(array_map(
                    static fn (string $code): OosSemanticUncertainty => OosSemanticUncertainty::from($code),
                    $service['uncertainties'],
                )),
            ),
            array_values($annotation['services']),
        );

        $annotations = [];

        foreach ($annotation['annotations'] as $line) {
            $annotations[$line['key']] = new OosSemanticLineAnnotation(
                lineId: $line['line_id'],
                role: OosSemanticRole::from($line['role']),
                serviceGroupId: $line['service_group_id'],
                itemKind: $line['item_kind'] === null ? null : OosSemanticItemKind::from($line['item_kind']),
                continuationTargetLineId: $line['continuation_target_line_id'],
                uncertainty: $line['uncertainty'] === null ? null : OosSemanticUncertainty::from($line['uncertainty']),
                sharedServiceGroupIds: $line['shared_service_group_ids'],
                boundaryAlsoItem: $line['boundary_also_item'],
            );
        }

        return new OosSemanticAnnotationResult($services, $annotations);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
