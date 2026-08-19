<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailSourceDocument;
use RuntimeException;

/**
 * Rebuilds the exact source document a frozen corpus record describes, and proves it is the same one.
 *
 * The corpus stores a portable line inventory rather than the email body, so every surface that
 * parses or scores a corpus source has to reconstitute the body from it. Reconstruction is only
 * trustworthy if it reproduces the record's own `input_hash`: a normalisation change anywhere in
 * {@see OosEmailSourceDocument} would otherwise silently re-line an email and rebind every item's
 * source lines to different text. Three surfaces did this independently; the rule lives here so a
 * change to it cannot reach one of them and not the others.
 */
class OosSemanticEvaluationSource
{
    /** @param array<string, mixed> $record */
    public function document(array $record): OosEmailSourceDocument
    {
        $itemKey = is_string($record['item_key'] ?? null) ? $record['item_key'] : 'unknown';
        $portable = $record['source_document'] ?? null;

        if (! is_array($portable) || ! is_array($portable['lines'] ?? null) || ! is_string($portable['input_hash'] ?? null)) {
            throw new RuntimeException("Semantic evaluation source {$itemKey} has no hash-bound source document.");
        }

        $physical = [];

        foreach ($portable['lines'] as $line) {
            $physical[(int) $line['physical_position']] = (string) $line['exact_text'];
        }

        if ($physical === []) {
            throw new RuntimeException("Semantic source {$itemKey} contains no physical lines.");
        }

        $last = max(array_keys($physical));
        $body = [];

        for ($position = 1; $position <= $last; $position++) {
            $body[] = $physical[$position] ?? '';
        }

        $document = OosEmailSourceDocument::fromContext(
            is_string($portable['subject'] ?? null) ? $portable['subject'] : null,
            implode("\n", $body),
            is_string($portable['received_date'] ?? null) ? $portable['received_date'] : null,
        );

        if (! hash_equals($portable['input_hash'], $document->inputHash())) {
            throw new RuntimeException("Reconstructed semantic source {$itemKey} changed hash.");
        }

        return $document;
    }
}
