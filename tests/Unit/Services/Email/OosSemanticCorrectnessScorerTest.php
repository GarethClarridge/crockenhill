<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosCandidateService;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Services\Email\CompileOosSemanticAnnotations;
use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use App\Services\Email\OosEmailExtractionValidator;
use App\Services\Email\OosParserSurfaceFingerprint;
use App\Services\Email\OosSemanticAnnotationValidator;
use App\Services\Email\OosSemanticCandidateEvidenceRunner;
use App\Services\Email\OosSemanticCorrectnessScorer;
use App\Services\Email\OosSemanticEvaluationCorpusGate;
use App\Services\Email\OosServiceDateResolver;
use App\Services\Email\RunOosSemanticSafetyFixtures;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosSemanticCorrectnessScorerTest extends TestCase
{
    private const Subject = 'Order of service for Sunday 4 January 2099';

    private const Body = "Morning Service\nHymn 100 Praise the Lord\nSermon: The Word became flesh\nBest wishes";

    #[Test]
    public function a_candidate_that_reproduces_truth_passes_every_scoreable_gate(): void
    {
        $report = $this->score();

        $this->assertSame('scored', $report['inference']['label']);
        $this->assertSame([], $report['inference']['refusals']);
        $this->assertSame(1.0, $report['metrics']['items']['precision']);
        $this->assertSame(1.0, $report['metrics']['items']['recall']);
        $this->assertSame(1.0, $report['metrics']['title_binding']['rate']);
        $this->assertSame(1.0, $report['metrics']['item_kinds']['semantic_kind_accuracy']);
        $this->assertSame([], $report['metrics']['routing']['incorrect_unattended_imports']);

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 10, 11] as $gate) {
            $this->assertSame('pass', $this->gate($report, $gate)['status'], "gate {$gate}");
        }

        $this->assertSame('pass', $report['verdict']);
    }

    #[Test]
    public function entry_point_parity_is_a_precondition_rather_than_a_gate(): void
    {
        $report = $this->score();

        /**
         * It was §6.3 gate 9 until 2026-08-20. It never behaved like a gate — no arm can move it,
         * so it was `not_scored` on every artifact, which made `verdict: pass` unreachable and
         * Delivery 7's precondition unsatisfiable. A gate 9 reappearing in the list would restore
         * exactly that deadlock.
         */
        $this->assertSame(
            [],
            array_values(array_filter($report['gates'], static fn (array $gate): bool => $gate['gate'] === 9)),
            'Entry-point parity is a precondition; a gate 9 in the list makes verdict: pass unreachable again.',
        );

        $parity = $report['preconditions']['entry_point_parity'];

        $this->assertSame('tests/Feature/Services/Email/OosParserEntryPointParityTest.php', $parity['established_by']);
        $this->assertTrue($parity['contract_test_present']);
        $this->assertSame(
            hash_file('sha256', base_path($parity['established_by'])),
            $parity['contract_test_sha256'],
            'The recorded hash must pin the contract test as it stands in the scored tree.',
        );
    }

    #[Test]
    public function a_missing_parity_contract_test_is_recorded_as_absent_rather_than_assumed(): void
    {
        $report = $this->score();
        $parity = $report['preconditions']['entry_point_parity'];

        /**
         * The block records presence, never a suite result. If the contract test were deleted the
         * artifact must say so instead of carrying a stale hash forward — the whole point of
         * pinning it is that a reader can re-run that file at the recorded commit.
         */
        $this->assertNotNull($parity['contract_test_sha256']);
        $this->assertStringContainsString('not an assertion that the test passed', $parity['note']);
    }

    #[Test]
    public function it_refuses_to_score_truth_that_is_not_fully_adjudicated(): void
    {
        $report = $this->score(corpusMutator: function (array $corpus): array {
            $corpus['sources'][0]['truth']['adjudication_state'] = 'pending';
            $corpus['completeness']['scoreable'] = false;
            $corpus['completeness']['fully_adjudicated_sources'] = 0;
            $corpus['completeness']['pending_sources'] = 1;

            return $corpus;
        });

        $this->assertSame('refused', $report['inference']['label']);
        $this->assertNull($report['metrics']);
        $this->assertNull($report['gates']);
        $this->assertNull($report['verdict']);
        $this->assertStringContainsString('not fully adjudicated', implode(' ', $report['inference']['refusals']));
    }

    #[Test]
    public function it_refuses_a_corpus_that_no_longer_reproduces_its_own_hash(): void
    {
        $report = $this->score(corpusMutator: static function (array $corpus): array {
            $corpus['sources'][0]['authority']['date'] = '2099-01-11';

            return $corpus;
        }, rehash: false);

        $this->assertSame('refused', $report['inference']['label']);
        $this->assertStringContainsString('does not reproduce its own corpus hash', $report['inference']['refusals'][0]);
    }

    #[Test]
    public function it_refuses_an_arm_that_ran_against_a_different_corpus(): void
    {
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            $candidate['inputs']['corpus_hash'] = str_repeat('0', 64);

            return $candidate;
        });

        $this->assertSame('refused', $report['inference']['label']);
        $this->assertStringContainsString('different corpus', implode(' ', $report['inference']['refusals']));
    }

    #[Test]
    public function it_refuses_an_arm_whose_parser_surface_drifted_from_the_scorer(): void
    {
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            $candidate['candidate']['parser_surface']['hash'] = str_repeat('a', 64);

            return $candidate;
        });

        $this->assertSame('refused', $report['inference']['label']);
        $this->assertStringContainsString('different parser surface', implode(' ', $report['inference']['refusals']));
    }

    #[Test]
    public function it_refuses_a_baseline_the_corpus_was_not_frozen_against(): void
    {
        $report = $this->score(baselineMutator: static function (array $baseline): array {
            $baseline['stability']['validation']['parse_count'] = 61;

            return $baseline;
        });

        $this->assertSame('refused', $report['inference']['label']);
        $this->assertStringContainsString('not the one the truth corpus was frozen against', implode(' ', $report['inference']['refusals']));
    }

    #[Test]
    public function it_refuses_an_arm_that_did_not_cover_every_corpus_source(): void
    {
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            $candidate['results'] = [];
            $candidate['results'][] = ['item_key' => 'somewhere-else', 'source_hash' => str_repeat('b', 64), 'extraction' => [], 'risk_signals' => [], 'attempts' => []];

            return $candidate;
        });

        $this->assertSame('refused', $report['inference']['label']);
        $refusals = implode(' ', $report['inference']['refusals']);
        $this->assertStringContainsString('did not parse corpus source', $refusals);
        $this->assertStringContainsString('which is not in the truth corpus', $refusals);
    }

    #[Test]
    public function an_unannotated_source_line_fails_the_line_identity_gate(): void
    {
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            $annotations = $candidate['results'][0]['attempts'][0]['final_annotations']['annotations'];
            unset($annotations[4]);
            $candidate['results'][0]['attempts'][0]['final_annotations']['annotations'] = $annotations;

            return $candidate;
        });

        $this->assertSame('fail', $this->gate($report, 1)['status']);
        $this->assertSame(1, $this->gate($report, 1)['detail']['missing']);
        $this->assertSame(['2099-01-04'], $this->gate($report, 1)['detail']['sources_with_defects']);
        $this->assertSame('fail', $report['verdict']);
    }

    #[Test]
    public function a_title_that_is_not_the_exact_source_line_fails_the_binding_gate(): void
    {
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            $candidate['results'][0]['extraction']['services'][0]['items'][0]['title'] = 'Hymn 100';

            return $candidate;
        });

        $this->assertSame('fail', $this->gate($report, 3)['status']);
        $this->assertSame('Hymn 100', $this->gate($report, 3)['detail']['unbound'][0]['title']);
        $this->assertSame('Hymn 100 Praise the Lord', $this->gate($report, 3)['detail']['unbound'][0]['expected']);
    }

    #[Test]
    public function a_confident_plan_that_disagrees_with_truth_fails_the_unattended_import_gate(): void
    {
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            $candidate['results'][0]['extraction']['services'][0]['confidence'] = 0.95;
            array_pop($candidate['results'][0]['extraction']['services'][0]['items']);

            return $candidate;
        });

        $this->assertSame(1, $report['metrics']['routing']['unattended_eligible_plans']);
        $this->assertSame('auto_importable', $report['metrics']['routing']['candidate_categories']['auto_importable'] > 0 ? 'auto_importable' : 'other');
        $this->assertSame('fail', $this->gate($report, 5)['status']);
        $this->assertSame('morning:2099-01-04', $this->gate($report, 5)['detail']['incorrect_unattended_imports'][0]['plan_key']);
    }

    #[Test]
    public function an_evidence_tier_plan_filed_against_the_wrong_scope_fails_the_unattended_import_gate(): void
    {
        // The semantic compiler fixes confidence at 0.75, so no semantic plan ever reaches the 0.90
        // auto-import threshold. Until REV-D2 was scored here that made gate 5 unreachable: it
        // reported zero eligible plans while `isEvidenceImportable()` was admitting review-required
        // plans to the real unattended path. This is the case the gate previously could not see.
        //
        // The mutation over-claims: the fixture is a song and a sermon with no structural frame
        // item, so it compiles to `partial`, and a candidate asserting `full` presents an
        // incomplete order as complete. That is the direction that costs something — the projector
        // builds a service's item list from `payload_complete` records with no review gate.
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            $candidate['results'][0]['extraction']['services'][0]['content_scope'] = 'full';

            return $candidate;
        });

        // The plan sits below the auto-import threshold, so it is admitted by the evidence tier
        // alone — the tier the old gate could not reach.
        $this->assertGreaterThan(0.75, $report['metrics']['routing']['auto_import_threshold']);
        $this->assertSame(0, $report['metrics']['routing']['auto_import_eligible_plans']);
        $this->assertSame(1, $report['metrics']['routing']['evidence_import_eligible_plans']);
        $this->assertSame('fail', $this->gate($report, 5)['status']);
        $this->assertSame([], $this->gate($report, 5)['detail']['incorrect_unattended_imports']);
        $this->assertSame('morning:2099-01-04', $this->gate($report, 5)['detail']['misfiled_evidence_admissions'][0]['plan_key']);
    }

    #[Test]
    public function an_evidence_tier_plan_that_only_loses_items_still_passes_the_unattended_import_gate(): void
    {
        // REV-D2 admits evidence-tier content precisely because its content confidence is not
        // trusted. Item loss is gate 6's business and the quarantine's; failing gate 5 for it would
        // contradict the policy this tier implements.
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            array_pop($candidate['results'][0]['extraction']['services'][0]['items']);

            return $candidate;
        });

        $this->assertSame(1, $report['metrics']['routing']['evidence_import_eligible_plans']);
        $this->assertSame([], $this->gate($report, 5)['detail']['misfiled_evidence_admissions']);
        $this->assertSame('pass', $this->gate($report, 5)['status']);
    }

    #[Test]
    public function a_candidate_artifact_that_does_not_reproduce_its_own_evidence_hash_is_refused(): void
    {
        // The artifact is written once and hashed over itself. Copying that hash into the score
        // without recomputing it left results, attempts, usage and price data editable after
        // generation with no integrity refusal.
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            $candidate['usage']['total_tokens'] = 999_999;

            return $candidate;
        }, rehash: false);

        $this->assertSame('refused', $report['inference']['label']);
        $this->assertStringContainsString(
            'does not reproduce its own evidence hash',
            $report['inference']['refusals'][0],
        );
    }

    #[Test]
    public function a_replicate_artifact_that_does_not_reproduce_its_own_evidence_hash_is_refused(): void
    {
        $report = $this->score(replicateMutator: static function (array $replicate): array {
            $replicate['usage']['total_tokens'] = 999_999;

            return $replicate;
        }, rehash: false);

        $this->assertSame('refused', $report['inference']['label']);
        $this->assertTrue(collect($report['inference']['refusals'])
            ->contains(fn (string $refusal): bool => str_contains($refusal, 'does not reproduce its own evidence hash')));
    }

    #[Test]
    public function a_dropped_item_is_a_recall_loss_rather_than_a_precision_loss(): void
    {
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            array_pop($candidate['results'][0]['extraction']['services'][0]['items']);

            return $candidate;
        });

        $this->assertSame(1.0, $report['metrics']['items']['precision']);
        $this->assertSame(0.5, $report['metrics']['items']['recall']);
        $this->assertSame('fail', $this->gate($report, 6)['status']);
    }

    #[Test]
    public function a_repair_that_touched_a_line_the_failure_did_not_name_fails_the_locality_gate(): void
    {
        $report = $this->score(candidateMutator: static function (array $candidate): array {
            $attempt = &$candidate['results'][0]['attempts'][0];
            $attempt['patch'] = ['annotations' => []];
            $attempt['allowed_patch'] = [3 => ['item_kind']];
            $attempt['initial_annotations']['annotations'][4]['role'] = 'notice_context';

            return $candidate;
        });

        $this->assertSame('fail', $this->gate($report, 8)['status']);
        $this->assertSame([4], $this->gate($report, 8)['detail']['sources_with_unrelated_mutations'][0]['line_ids']);
    }

    #[Test]
    public function the_authority_identity_gate_reports_the_adjudicated_truth_ceiling_beside_the_legacy_baseline(): void
    {
        $report = $this->score(corpusMutator: static function (array $corpus): array {
            // The legacy arm named the approved identity; nothing in the source lets a deterministic
            // resolver reach it, so the truth ceiling sits below the baseline too.
            $corpus['sources'][0]['authority']['date'] = '2099-01-11';

            return $corpus;
        }, legacyMatchesAuthority: true, authorityDate: '2099-01-11');

        $authority = $this->gate($report, 7)['detail']['authority_identity'];
        $this->assertSame(0, $authority['candidate']);
        $this->assertSame(0, $authority['adjudicated_truth_ceiling']);
        $this->assertSame(1, $authority['legacy_baseline']);
        $this->assertSame('fail', $this->gate($report, 7)['status']);
    }

    #[Test]
    public function a_content_rule_the_baseline_never_had_the_chance_to_hit_is_not_a_regression(): void
    {
        $mutator = static function (array $candidate): array {
            $candidate['results'][0]['extraction']['service_count'] = 2;

            return $candidate;
        };

        $onHardCase = $this->score(candidateMutator: $mutator, stabilitySample: false);
        $onSample = $this->score(candidateMutator: $mutator, stabilitySample: true);

        $this->assertSame(
            ['service_count_mismatch' => 1],
            $onHardCase['metrics']['compatibility_validation']['hard_case_content_rule_counts'],
        );
        $this->assertArrayNotHasKey('service_count_mismatch', $onHardCase['metrics']['compatibility_validation']['content_rule_families']);
        $this->assertSame('pass', $this->gate($onHardCase, 10)['status']);

        $this->assertTrue($onSample['metrics']['compatibility_validation']['content_rule_families']['service_count_mismatch']['regressed']);
        $this->assertSame(['service_count_mismatch'], $this->gate($onSample, 10)['detail']['regressed_families']);
        $this->assertSame('fail', $this->gate($onSample, 10)['status']);
    }

    #[Test]
    public function a_replicate_produces_a_decomposed_self_disagreement_figure(): void
    {
        $report = $this->score(replicateMutator: static function (array $replicate): array {
            $replicate['results'][0]['extraction']['services'][0]['items'][0]['type'] = 'other';

            return $replicate;
        });

        $this->assertSame(1, $report['metrics']['stability']['self_disagreements']);
        $this->assertSame(1.0, $report['metrics']['stability']['rate']);
        $this->assertSame(1, $report['metrics']['stability']['field_decomposition']['item_structure']);

        // An item type moved, so this is outcome movement and not just a citation change.
        $this->assertSame(1, $report['metrics']['stability']['outcome_disagreements']);
        $this->assertSame(1.0, $report['metrics']['stability']['outcome_rate']);
    }

    #[Test]
    public function movement_confined_to_provenance_counts_against_the_raw_rate_but_not_the_outcome_rate(): void
    {
        $report = $this->score(replicateMutator: static function (array $replicate): array {
            // The same item, bound to a different evidence line. Nothing a consumer reads changes.
            $replicate['results'][0]['extraction']['services'][0]['service_evidence_line_ids'] = [1, 2];

            return $replicate;
        });

        $stability = $report['metrics']['stability'];

        /**
         * The §6.3 ceiling is about answers changing between draws, not citations. Counting a
         * provenance-only move against it would reject a candidate for exactly what §6.3 says is
         * not grounds for rejection while "deterministic projection remains correct and safe" — and
         * on the real v6 pair this was 15 of 16 disagreeing sources, so it dominated the headline.
         */
        $this->assertSame(1, $stability['self_disagreements'], 'Raw rate must still see the movement.');
        $this->assertSame(1, $stability['field_decomposition']['provenance']);
        $this->assertSame(0, $stability['outcome_disagreements'], 'A citation change is not outcome movement.');
        $this->assertSame(0.0, $stability['outcome_rate']);
        $this->assertSame(0, $stability['plan_key_disagreements']);
        $this->assertSame('outcome_rate', $stability['ceiling_applies_to']);
    }

    // -----------------------------------------------------------------------------------------

    /**
     * @param  ?callable(array<string, mixed>): array<string, mixed>  $corpusMutator
     * @param  ?callable(array<string, mixed>): array<string, mixed>  $candidateMutator
     * @param  ?callable(array<string, mixed>): array<string, mixed>  $baselineMutator
     * @param  ?callable(array<string, mixed>): array<string, mixed>  $replicateMutator
     * @return array<string, mixed>
     */
    private function score(
        ?callable $corpusMutator = null,
        ?callable $candidateMutator = null,
        ?callable $baselineMutator = null,
        ?callable $replicateMutator = null,
        bool $rehash = true,
        bool $legacyMatchesAuthority = false,
        string $authorityDate = '2099-01-04',
        bool $stabilitySample = true,
    ): array {
        // The corpus binds the *frozen* baseline, so a mutator here produces the drifted artifact a
        // later session might supply rather than silently changing what the corpus was frozen against.
        $baseline = $this->baseline();
        $corpus = $this->corpus($baseline, $legacyMatchesAuthority, $authorityDate, $stabilitySample);

        if ($baselineMutator !== null) {
            $baseline = $baselineMutator($baseline);
        }

        if ($corpusMutator !== null) {
            $corpus = $corpusMutator($corpus);

            if ($rehash) {
                unset($corpus['corpus_hash']);
                $corpus['corpus_hash'] = CanonicalJson::hash($corpus);
            }
        }

        $candidate = $this->candidate($corpus);

        if ($candidateMutator !== null) {
            // Rehashed for the same reason the corpus is: a mutator here is standing in for a
            // *differently produced* arm, not for someone editing a banked artifact after the fact.
            // Leaving the stale hash in place made every one of these tests a tamper case, which is
            // why the scorer's failure to verify it went unnoticed. `rehash: false` opts back into
            // genuine tampering, and one test uses it deliberately.
            $candidate = $this->rehashed($candidateMutator($candidate), $rehash);
        }

        $replicate = null;

        if ($replicateMutator !== null) {
            $replicate = $this->rehashed($replicateMutator($this->candidate($corpus)), $rehash);
        }

        return $this->scorer()->score($corpus, $candidate, $baseline, $this->safetyFixtures(), $replicate);
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function rehashed(array $artifact, bool $rehash): array
    {
        if (! $rehash) {
            return $artifact;
        }

        unset($artifact['evidence_hash']);
        $artifact['evidence_hash'] = CanonicalJson::hash($artifact);

        return $artifact;
    }

    private function scorer(): OosSemanticCorrectnessScorer
    {
        return new OosSemanticCorrectnessScorer(
            new OosSemanticEvaluationCorpusGate,
            new OosEmailExtractionValidator,
            new OosParserSurfaceFingerprint,
        );
    }

    private function source(): OosEmailSourceDocument
    {
        return OosEmailSourceDocument::fromContext(self::Subject, self::Body, '2099-01-02');
    }

    private function annotationResult(): OosSemanticAnnotationResult
    {
        return new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Song, null, null),
                3 => new OosSemanticLineAnnotation(3, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Sermon, null, null),
                4 => new OosSemanticLineAnnotation(4, OosSemanticRole::GreetingOrSignature, null, null, null, null),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function baseline(): array
    {
        return [
            'format' => 'crockenhill-oos-parser-stability-diagnostic',
            'manifest_hash' => str_repeat('c', 64),
            'stability' => [
                'sample_source_keys' => ['2099-01-04'],
                'validation' => [
                    'parse_count' => 60,
                    'first_pass_failure_parses' => 24,
                    'first_pass_rule_codes' => [
                        'content' => ['items_out_of_source_order' => 6, 'source_line_claimed_by_multiple_items' => 4],
                        'bookkeeping' => ['line_unclassified' => 16],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @return array<string, mixed>
     */
    private function corpus(array $baseline, bool $legacyMatchesAuthority, string $authorityDate, bool $stabilitySample = true): array
    {
        $source = $this->source();
        $validator = new OosSemanticAnnotationValidator;
        $compilation = (new CompileOosSemanticAnnotations($validator, new OosServiceDateResolver))
            ->compile($source, $this->annotationResult());

        $corpus = [
            'format' => FreezeOosSemanticEvaluationCorpus::Format,
            'version' => FreezeOosSemanticEvaluationCorpus::Version,
            'private' => true,
            'inputs' => [
                'curation_manifest_hash' => str_repeat('c', 64),
                'approved_source_count' => 554,
                'legacy_projection_sha256' => str_repeat('d', 64),
                'stability_diagnostic_sha256' => CanonicalJson::hash($baseline),
                'item_truth_sha256' => str_repeat('e', 64),
            ],
            'selection' => [
                'stability_sample_source_keys' => $stabilitySample ? ['2099-01-04'] : [],
                'hard_case_source_keys' => $stabilitySample ? [] : ['2099-01-04'],
                'selected_source_keys' => ['2099-01-04'],
            ],
            'completeness' => [
                'source_count' => 1,
                'fully_adjudicated_sources' => 1,
                'pending_sources' => 0,
                'scoreable' => true,
                'blocking_fields' => [],
            ],
            'sources' => [[
                'item_key' => '2099-01-04',
                'selection' => ['stability_sample' => $stabilitySample, 'hard_case' => ! $stabilitySample],
                'approved_payload_sha256' => str_repeat('f', 64),
                'source_document' => [
                    'format_version' => OosEmailSourceDocument::PortableFormatVersion,
                    'input_hash' => $source->inputHash(),
                    'subject' => $source->subject,
                    'received_date' => $source->receivedDate,
                    'lines' => $source->semanticLineRecords(),
                ],
                'authority' => ['date' => $authorityDate, 'service' => 'morning', 'content_scope' => 'full', 'item_truth' => null],
                'truth' => [
                    'format' => FreezeOosSemanticEvaluationCorpus::TruthFormat,
                    'version' => FreezeOosSemanticEvaluationCorpus::TruthVersion,
                    'adjudication_state' => 'adjudicated',
                    'services' => [['group_id' => 'morning', 'proposed_service' => 'morning', 'boundary_line_ids' => [1], 'uncertainties' => []]],
                    'annotations' => array_values(array_map(
                        static fn (OosSemanticLineAnnotation $annotation): array => $annotation->toArray(),
                        $this->annotationResult()->annotations,
                    )),
                    'expected_plans' => $compilation->extraction->services,
                    'adjudicated_by' => 'maintainer@example.test',
                    'adjudicated_at' => '2026-08-19T12:00:00+01:00',
                ],
                'legacy_machine_prefill' => [
                    'not_truth' => true,
                    'routing' => ['category' => 'review_required', 'auto_importable_plan_keys' => [], 'importable_plan_keys' => ['morning:2099-01-04']],
                    'output' => [
                        'date' => $legacyMatchesAuthority ? $authorityDate : '2098-12-25',
                        'service' => 'morning',
                    ],
                    'telemetry' => [],
                    'semantic_worksheet' => [],
                ],
            ]],
        ];
        $corpus['corpus_hash'] = CanonicalJson::hash($corpus);

        return $corpus;
    }

    /**
     * A candidate that returned exactly the adjudicated annotations, so every deviation a test
     * introduces is the only difference the scorer can see.
     *
     * @param  array<string, mixed>  $corpus
     * @return array<string, mixed>
     */
    private function candidate(array $corpus): array
    {
        $source = $this->source();
        $result = $this->annotationResult();
        $validator = new OosSemanticAnnotationValidator;
        $compilation = (new CompileOosSemanticAnnotations($validator, new OosServiceDateResolver))->compile($source, $result);
        $annotations = $result->toArray();

        $artifact = [
            'format' => OosSemanticCandidateEvidenceRunner::Format,
            'version' => OosSemanticCandidateEvidenceRunner::Version,
            'candidate' => [
                'configured_model' => 'gpt-5.6-terra',
                'returned_model' => 'gpt-5.6-terra-2026-08-01',
                'reasoning_effort' => 'low',
                'parser_surface' => (new OosParserSurfaceFingerprint)->fingerprint(),
            ],
            'inputs' => [
                'corpus_hash' => $corpus['corpus_hash'],
                'price_snapshot_sha256' => str_repeat('9', 64),
                'price_snapshot' => ['taken_at' => '2026-08-19', 'billing_mode' => 'standard', 'models' => ['gpt-5.6-terra' => ['input' => 2.0, 'output' => 12.0]]],
            ],
            'usage' => ['calls' => 1, 'input_tokens' => 1000, 'output_tokens' => 200, 'total_tokens' => 1200, 'latency_ms' => 900],
            'calls' => [['item_key' => '2099-01-04', 'role' => 'annotation', 'returned_model' => 'gpt-5.6-terra-2026-08-01', 'latency_ms' => 900, 'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'total_tokens' => 1200]]],
            'results' => [[
                'item_key' => '2099-01-04',
                'source_hash' => $source->inputHash(),
                'extraction' => [
                    'items' => $compilation->extraction->items,
                    'confidence' => $compilation->extraction->confidence,
                    'notes' => $compilation->extraction->notes,
                    'services' => $compilation->extraction->services,
                    'service_count' => $compilation->extraction->serviceCount,
                    'ignored_lines' => $compilation->extraction->ignoredLines,
                    'provenance_complete' => $compilation->extraction->provenanceComplete,
                ],
                'risk_signals' => $compilation->riskSignals,
                'attempts' => [[
                    'attempt' => 1,
                    'selected' => true,
                    'initial_annotations' => $annotations,
                    'initial_rule_codes' => [],
                    'allowed_patch' => [],
                    'patch' => null,
                    'final_annotations' => $annotations,
                    'final_rule_codes' => [],
                    'repair_error' => null,
                ]],
            ]],
        ];
        $artifact['evidence_hash'] = CanonicalJson::hash($artifact);

        return $artifact;
    }

    /** @return array<string, mixed> */
    private function safetyFixtures(): array
    {
        return [
            'format' => RunOosSemanticSafetyFixtures::Format,
            'version' => RunOosSemanticSafetyFixtures::Version,
            'summary' => ['fixtures' => 15, 'satisfied' => 15, 'unsatisfied' => 0, 'unsatisfied_names' => [], 'content_invalid_false_accepts' => 0],
            'results' => [],
            'fixture_results_hash' => str_repeat('8', 64),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function gate(array $report, int $id): array
    {
        foreach ($report['gates'] as $gate) {
            if ($gate['gate'] === $id) {
                return $gate;
            }
        }

        $this->fail("The report carries no gate {$id}.");
    }
}
