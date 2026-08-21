<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\CompileOosSemanticAnnotations;
use App\Services\Email\OosParserSurfaceFingerprint;
use App\Services\Email\OosSemanticAnnotationValidator;
use App\Services\Email\OosSemanticEvaluationCorpusGate;
use App\Services\Email\OosServiceDateResolver;
use App\Services\Email\RecompileOosSemanticCandidateEvidence;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\OosSemanticCandidateArtifactFixture;
use Tests\TestCase;

class RecompileOosSemanticCandidateEvidenceTest extends TestCase
{
    private OosSemanticCandidateArtifactFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new OosSemanticCandidateArtifactFixture;
    }

    #[Test]
    public function it_recompiles_a_banked_compilation_through_the_current_compiler(): void
    {
        // The banked artifact claims `full` for a plan of nothing but songs, which is what the
        // compiler produced before the structural-frame rule. Replaying it must narrow the claim
        // without any model call.
        $artifact = $this->recompiler()->recompile($this->fixture->corpus(), $this->fixture->candidate());

        $this->assertSame('partial', $artifact['results'][0]['extraction']['services'][0]['content_scope']);
        $this->assertSame('partial', $artifact['results'][0]['attempts'][0]['compilation']['extraction']['services'][0]['content_scope']);
        $this->assertSame(['sample'], $artifact['recompilation']['sources_with_changed_compilation']);
    }

    #[Test]
    public function it_carries_the_model_output_across_byte_identically(): void
    {
        $candidate = $this->fixture->candidate();
        $artifact = $this->recompiler()->recompile($this->fixture->corpus(), $candidate);
        $before = $candidate['results'][0]['attempts'][0];
        $after = $artifact['results'][0]['attempts'][0];

        foreach (['initial_annotations', 'final_annotations', 'patch', 'repair_telemetry', 'prompt_hash', 'schema_hash', 'parser_surface_hash'] as $field) {
            $this->assertSame($before[$field], $after[$field], "{$field} must not move in a recompilation");
        }

        $this->assertSame($candidate['usage'], $artifact['usage']);
        $this->assertSame($candidate['calls'], $artifact['calls']);
    }

    #[Test]
    public function it_restamps_the_surface_and_corpus_bindings_and_records_what_they_were(): void
    {
        $candidate = $this->fixture->candidate();
        $corpus = $this->fixture->corpus();
        $artifact = $this->recompiler()->recompile($corpus, $candidate);
        $surface = (new OosParserSurfaceFingerprint)->fingerprint();

        // The scorer refuses a candidate whose surface or corpus binding differs from the one
        // scoring it, so a recompilation that did not restamp these would be unscoreable.
        $this->assertSame($surface['hash'], $artifact['candidate']['parser_surface']['hash']);
        $this->assertSame($corpus['corpus_hash'], $artifact['inputs']['corpus_hash']);
        $this->assertSame('a-stale-surface-hash', $artifact['recompilation']['original_parser_surface_hash']);
        $this->assertSame($candidate['inputs']['corpus_hash'], $artifact['recompilation']['original_corpus_hash']);
        $this->assertSame($candidate['evidence_hash'], $artifact['recompilation']['source_evidence_hash']);
        $this->assertNotSame($candidate['evidence_hash'], $artifact['evidence_hash']);

        $withoutHash = $artifact;
        unset($withoutHash['evidence_hash']);
        $this->assertSame($artifact['evidence_hash'], CanonicalJson::hash($withoutHash));
    }

    #[Test]
    public function a_result_whose_parse_failed_has_no_compilation_and_is_left_alone(): void
    {
        $candidate = $this->fixture->candidate(static function (array $candidate): array {
            unset($candidate['results'][0]['attempts'][0]['compilation'], $candidate['results'][0]['attempts'][0]['compilation_hash']);
            $candidate['results'][0]['attempts'][0]['selected'] = false;
            $candidate['results'][0]['attempts'][0]['final_rule_codes'] = ['unbound_title'];

            return $candidate;
        });

        $artifact = $this->recompiler()->recompile($this->fixture->corpus(), $candidate);

        $this->assertSame([], $artifact['recompilation']['sources_with_changed_compilation']);
        $this->assertSame($candidate['results'][0], $artifact['results'][0]);
    }

    #[Test]
    public function a_drifted_artifact_is_refused_rather_than_relaundered(): void
    {
        $candidate = $this->fixture->candidate();
        $candidate['usage']['total_tokens'] = 999999;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not reproduce its own evidence hash');
        $this->recompiler()->recompile($this->fixture->corpus(), $candidate);
    }

    #[Test]
    public function a_source_the_arm_parsed_from_different_bytes_is_refused(): void
    {
        $candidate = $this->fixture->candidate(static function (array $candidate): array {
            $candidate['results'][0]['source_hash'] = str_repeat('0', 64);

            return $candidate;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different input than the supplied corpus holds');
        $this->recompiler()->recompile($this->fixture->corpus(), $candidate);
    }

    #[Test]
    public function an_already_recompiled_artifact_is_refused(): void
    {
        $artifact = $this->recompiler()->recompile($this->fixture->corpus(), $this->fixture->candidate());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been recompiled');
        $this->recompiler()->recompile($this->fixture->corpus(), $artifact);
    }

    private function recompiler(): RecompileOosSemanticCandidateEvidence
    {
        return new RecompileOosSemanticCandidateEvidence(
            new CompileOosSemanticAnnotations(new OosSemanticAnnotationValidator, new OosServiceDateResolver),
            new OosParserSurfaceFingerprint,
            new OosSemanticEvaluationCorpusGate,
        );
    }
}
