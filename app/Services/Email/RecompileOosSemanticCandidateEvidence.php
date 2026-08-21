<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosSemanticAnnotationResult;
use App\Support\CanonicalJson;
use App\Support\RepositoryCommit;
use RuntimeException;

/**
 * Recompiles a banked candidate evidence artifact through the current compiler, from the model
 * annotations it already stores, without making a single model call.
 *
 * **Why this is not a second hash re-baseline.** `evidence:rebaseline-hash` re-derived a hash from
 * whatever a file happened to hold and could not tell encoder loss from an edit. This is the
 * opposite: the model's own output — `initial_annotations`, `final_annotations`, the patch, the
 * telemetry, the usage and price bindings — is carried across byte-identically and is never
 * rewritten. Only the *derived* compilation moves, and it moves deterministically: anyone holding
 * the same annotations and the same compiler reproduces it exactly. The recompilation is a
 * reproducible function of retained inputs, not a fresh attestation of a run.
 *
 * **Why the surface and corpus bindings are restamped.** The scorer refuses a candidate whose
 * `candidate.parser_surface.hash` differs from the surface scoring it, because that field answers
 * "what code compiled these plans" — and after a recompile the honest answer is the current
 * surface. The same holds for `inputs.corpus_hash` once truth has been recompiled by the same
 * change. Both original values are preserved in the `recompilation` block rather than lost, and the
 * binding that actually proves the arm parsed this corpus — the per-source `source_hash` — is
 * *verified* here against the supplied corpus rather than assumed, which is a stronger check than
 * the corpus-hash equality it replaces.
 *
 * A result whose parse failed validation carries no compilation at all; there is nothing to
 * recompile and it is passed through untouched.
 *
 * Deleted with the rest of the Delivery 0/6 evaluation surface at historic import IC8 closeout.
 */
class RecompileOosSemanticCandidateEvidence
{
    public function __construct(
        private readonly CompileOosSemanticAnnotations $compiler,
        private readonly OosParserSurfaceFingerprint $fingerprint,
        private readonly OosSemanticEvaluationCorpusGate $gate,
        private readonly OosSemanticEvaluationSource $sources = new OosSemanticEvaluationSource,
    ) {}

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function recompile(array $corpus, array $candidate): array
    {
        $this->gate->assertScoreable($corpus);
        $this->assertCandidateVerifies($candidate);

        $records = $this->corpusRecordsByKey($corpus);
        $results = [];
        $changed = [];

        foreach ($candidate['results'] as $result) {
            if (! is_array($result) || ! is_string($result['item_key'] ?? null)) {
                throw new RuntimeException('The candidate artifact carries a result without a source key.');
            }

            $key = $result['item_key'];
            $record = $records[$key] ?? throw new RuntimeException("The candidate parsed source {$key}, which the supplied corpus does not hold.");
            $recompiled = $this->recompileResult($result, $record, $key);
            $results[] = $recompiled['result'];

            if ($recompiled['changed']) {
                $changed[] = $key;
            }
        }

        $surface = $this->fingerprint->fingerprint();
        $recompiledArtifact = $candidate;
        $recompiledArtifact['results'] = $results;
        $recompiledArtifact['candidate']['parser_surface'] = $surface;
        $recompiledArtifact['inputs']['corpus_hash'] = $corpus['corpus_hash'];
        $recompiledArtifact['recompilation'] = [
            'recompiled_at' => now()->toIso8601String(),
            'recompiled_application_commit' => RepositoryCommit::current(),
            'source_evidence_hash' => $candidate['evidence_hash'],
            'original_parser_surface_hash' => $candidate['candidate']['parser_surface']['hash'] ?? null,
            'recompiled_parser_surface_hash' => $surface['hash'],
            'changed_parser_surface_files' => $this->changedSurfaceFiles($candidate, $surface),
            'original_corpus_hash' => $candidate['inputs']['corpus_hash'] ?? null,
            'recompiled_corpus_hash' => $corpus['corpus_hash'],
            'sources_with_changed_compilation' => $changed,
            'note' => 'Model output was replayed, not re-requested: every stored annotation, patch and '
                .'telemetry field is byte-identical to the original run, and only the compilation '
                .'derived from them was recomputed. Each result\'s source_hash was verified against '
                .'the supplied corpus before recompiling.',
        ];
        unset($recompiledArtifact['evidence_hash']);
        $recompiledArtifact['evidence_hash'] = CanonicalJson::hash($recompiledArtifact);

        return $recompiledArtifact;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $record
     * @return array{result:array<string, mixed>,changed:bool}
     */
    private function recompileResult(array $result, array $record, string $key): array
    {
        $document = $this->sources->document($record);

        // The binding that matters: the arm must have parsed the bytes this corpus holds. Without
        // this the corpus-hash restamp above would be an unchecked assertion.
        if (($result['source_hash'] ?? null) !== $document->inputHash()) {
            throw new RuntimeException("The candidate parsed source {$key} from different input than the supplied corpus holds.");
        }

        $attempts = $result['attempts'] ?? null;

        if (! is_array($attempts) || ! is_array($attempts[0] ?? null)) {
            throw new RuntimeException("The candidate carries no attempt for source {$key}.");
        }

        $attempt = $attempts[0];

        if (! array_key_exists('compilation', $attempt)) {
            return ['result' => $result, 'changed' => false];
        }

        if (! is_array($attempt['final_annotations'] ?? null)) {
            throw new RuntimeException("The candidate attempt for source {$key} stores no final annotations to recompile.");
        }

        $compiled = $this->compiler->compile($document, OosSemanticAnnotationResult::fromArray($attempt['final_annotations']));
        $compilation = [
            'extraction' => $compiled->extraction->toArray(),
            'risk_signals' => $compiled->riskSignals,
        ];
        $previousHash = $attempt['compilation_hash'] ?? null;
        $attempt['compilation'] = $compilation;
        $attempt['compilation_hash'] = CanonicalJson::hash($compilation);
        $result['attempts'][0] = $attempt;
        $result['extraction'] = $compilation['extraction'];
        $result['risk_signals'] = [
            ...$compiled->riskSignals,
            'targeted_repair_required' => ($attempt['initial_rule_codes'] ?? []) !== [],
            'targeted_repair_failed' => ($attempt['repair_error'] ?? null) !== null,
        ];

        return ['result' => $result, 'changed' => $previousHash !== $attempt['compilation_hash']];
    }

    /** @param array<string, mixed> $candidate */
    private function assertCandidateVerifies(array $candidate): void
    {
        if (($candidate['format'] ?? null) !== OosSemanticCandidateEvidenceRunner::Format
            || ($candidate['version'] ?? null) !== OosSemanticCandidateEvidenceRunner::Version) {
            throw new RuntimeException('The supplied artifact is not a supported candidate evidence artifact.');
        }

        if (isset($candidate['recompilation'])) {
            throw new RuntimeException('The supplied artifact has already been recompiled; recompile the original run instead.');
        }

        if (! is_array($candidate['results'] ?? null) || $candidate['results'] === []) {
            throw new RuntimeException('The candidate artifact carries no results.');
        }

        $recorded = $candidate['evidence_hash'] ?? null;
        $withoutHash = $candidate;
        unset($withoutHash['evidence_hash']);

        // Fail closed, exactly as the scorer does: recompiling a drifted artifact would launder the
        // drift into a freshly hashed file that verifies.
        if (! is_string($recorded) || ! hash_equals($recorded, CanonicalJson::hash($withoutHash))) {
            throw new RuntimeException('The candidate artifact does not reproduce its own evidence hash, so its contents have drifted since the arm was run.');
        }
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<string, array<string, mixed>>
     */
    private function corpusRecordsByKey(array $corpus): array
    {
        $records = [];

        foreach ($corpus['sources'] as $record) {
            if (is_array($record) && is_string($record['item_key'] ?? null)) {
                $records[$record['item_key']] = $record;
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array{hash:string,files:array<string, string>}  $surface
     * @return list<string>
     */
    private function changedSurfaceFiles(array $candidate, array $surface): array
    {
        $original = $candidate['candidate']['parser_surface']['files'] ?? null;

        if (! is_array($original)) {
            return [];
        }

        $changed = [];

        foreach ($surface['files'] as $file => $hash) {
            if (($original[$file] ?? null) !== $hash) {
                $changed[] = $file;
            }
        }

        foreach (array_keys($original) as $file) {
            if (! array_key_exists($file, $surface['files'])) {
                $changed[] = (string) $file;
            }
        }

        sort($changed);

        return array_values(array_unique($changed));
    }
}
