<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\ChurchServiceSourceRevision;
use App\Data\OpenLpCurationPlan;
use App\Services\ChurchService\SourceAdapters\OpenLpSourceAdapter;
use App\Support\CanonicalJson;
use App\Support\ChurchServiceSourceKey;

/**
 * The OpenLP half of the F1 corpus expectation — IC5 step 2, and the reason a
 * census could previously only ever declare `email`.
 *
 * {@see OosApprovedCorpus} explains why an expectation must be derived from the
 * approved manifest rather than read back out of `church_service_source_records`:
 * a producer that queries the staged corpus cannot fail for an approved entry
 * that never staged, because the entry is absent from both sides of the
 * comparison. That argument is lane-independent, so the OpenLP lane needs its
 * own producer for exactly the same reason, not a variant of it.
 *
 * **Where this differs from the Email producer, and why.**
 *
 * `batch_hash` is the plan's **manifest** hash, not its plan hash.
 * {@see ImportOpenLpDirectoryCommand} passes `$plan->manifestHash` as the batch
 * hash to every import, where the Email importer records the curation *plan*
 * hash. The expectation must bind to whatever the importer actually wrote or it
 * would reconcile against nothing, so this asymmetry is restated here rather
 * than normalised away — the importer is the authority on what is staged.
 *
 * `source_key` is the entry's `logical_upload_filename`, **canonicalised**.
 * {@see OpenLpSourceAdapter::adapt()} records the uploaded file's original name
 * and the command builds that upload from the logical filename, so an aliased
 * archive stages under its alias rather than its on-disk name — but
 * {@see ChurchServiceSourceRevision} then lowercases and ASCII-folds it through
 * {@see ChurchServiceSourceKey::canonical()} before it is ever written. Emitting
 * the raw filename reconciles only the archives whose names happen to be
 * lowercase already: measured against the staged 2026-08-23 corpus that was 99
 * of 614, with the other 515 reported as `approved_source_unstaged` even though
 * every identity had staged correctly.
 *
 * `origin` is the entry's own item key and is never used to widen anything. One
 * `.osz` is exactly one service and the importer is handed that service's
 * approved date and slot, so unlike Email there is no second order for an
 * approved entry to explain. {@see ChurchServiceCorpusExpectation::originOf()}
 * declines to trace OpenLP source keys for that reason, which makes any staged
 * OpenLP identity the manifest does not name unexplained by construction.
 *
 * `content_scope` is always `full`: an OpenLP archive is a whole planned order
 * of service. Email carries the field because a partial order is retained as
 * evidence and deliberately never projected; the OpenLP lane has no such state,
 * and says so explicitly rather than leaving the field to be inferred.
 */
class OpenLpApprovedCorpus
{
    public const string Format = ChurchServiceCorpusExpectation::Format;

    public const int Version = ChurchServiceCorpusExpectation::Version;

    /** This producer speaks for the OpenLP lane; the Email manifest owns its own. */
    public const string Source = 'openlp';

    /** An `.osz` is a whole planned order; there is no partial-scope state here. */
    private const ContentScope = 'full';

    /**
     * @return array{
     *     format:string,
     *     version:int,
     *     source:string,
     *     batch_key:string,
     *     batch_hash:string,
     *     manifest_hash:string,
     *     approved_sources:list<array<string, mixed>>,
     *     expectation_hash:string,
     * }
     */
    public function expectation(OpenLpCurationPlan $plan): array
    {
        $sources = [];

        foreach ($plan->includes as $include) {
            $sources[] = [
                'item_key' => $include['item_key'],
                'origin' => $include['item_key'],
                'source_key' => ChurchServiceSourceKey::canonical($include['logical_upload_filename']),
                'input_hash' => $include['sha256'],
                'identity' => [
                    'date' => $include['resolved_date'],
                    'service' => $include['resolved_service'],
                ],
                'content_scope' => self::ContentScope,
            ];
        }

        usort(
            $sources,
            static fn (array $left, array $right): int => $left['source_key'] <=> $right['source_key'],
        );

        $expectation = [
            'format' => self::Format,
            'version' => self::Version,
            'source' => self::Source,
            'batch_key' => $plan->batchKey,
            'batch_hash' => $plan->manifestHash,
            'manifest_hash' => $plan->manifestHash,
            'approved_sources' => $sources,
        ];

        $expectation['expectation_hash'] = CanonicalJson::hash($expectation);

        return $expectation;
    }
}
