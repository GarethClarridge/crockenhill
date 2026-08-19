<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Support\CanonicalJson;
use RuntimeException;

class OosSemanticEvaluationCorpusGate
{
    /** @param array<string, mixed> $corpus */
    public function assertScoreable(array $corpus): void
    {
        if (($corpus['format'] ?? null) !== FreezeOosSemanticEvaluationCorpus::Format
            || ($corpus['version'] ?? null) !== FreezeOosSemanticEvaluationCorpus::Version) {
            throw new RuntimeException('Semantic evaluation corpus has an unsupported format or version.');
        }

        $recordedHash = $corpus['corpus_hash'] ?? null;
        unset($corpus['corpus_hash']);

        if (! is_string($recordedHash) || ! hash_equals($recordedHash, CanonicalJson::hash($corpus))) {
            throw new RuntimeException('Semantic evaluation corpus hash does not match its contents.');
        }

        $sources = $corpus['sources'] ?? null;

        if (! is_array($sources) || $sources === []) {
            throw new RuntimeException('Semantic evaluation corpus contains no sources.');
        }

        $pending = [];

        foreach ($sources as $source) {
            if (! is_array($source) || ! is_string($source['item_key'] ?? null)) {
                throw new RuntimeException('Semantic evaluation corpus contains an invalid source record.');
            }

            $this->assertSourceHash($source);
            $truth = $source['truth'] ?? null;

            if (! is_array($truth)
                || ($truth['adjudication_state'] ?? null) !== 'adjudicated'
                || ! is_array($truth['services'] ?? null)
                || ! is_array($truth['annotations'] ?? null)
                || ! is_array($truth['expected_plans'] ?? null)
                || ! is_string($truth['adjudicated_by'] ?? null)
                || ! is_string($truth['adjudicated_at'] ?? null)) {
                $pending[] = $source['item_key'];
            }
        }

        $completeness = $corpus['completeness'] ?? null;

        if ($pending !== []
            || ! is_array($completeness)
            || ($completeness['scoreable'] ?? null) !== true
            || ($completeness['fully_adjudicated_sources'] ?? null) !== count($sources)
            || ($completeness['pending_sources'] ?? null) !== 0) {
            $suffix = $pending === [] ? '' : ' Pending: '.implode(', ', $pending).'.';

            throw new RuntimeException('Semantic evaluation corpus is not fully adjudicated; refusing paid model calls.'.$suffix);
        }
    }

    /** @param array<string, mixed> $source */
    private function assertSourceHash(array $source): void
    {
        $document = $source['source_document'] ?? null;

        if (! is_array($document)
            || ! is_array($document['lines'] ?? null)
            || ! is_string($document['input_hash'] ?? null)) {
            throw new RuntimeException("Semantic evaluation source {$source['item_key']} has no hash-bound source document.");
        }

        $hash = CanonicalJson::hash([
            'format_version' => OosEmailSourceDocument::PortableFormatVersion,
            'subject' => $document['subject'] ?? null,
            'received_date' => $document['received_date'] ?? null,
            'lines' => $document['lines'],
        ]);

        if (! hash_equals($document['input_hash'], $hash)) {
            throw new RuntimeException("Semantic evaluation source {$source['item_key']} does not match its source hash.");
        }
    }
}
