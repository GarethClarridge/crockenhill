<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SourceAdapters;

use App\Data\ChurchServiceSourceRevision;
use App\Data\OosEmailServicePlan;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Models\InboundEmail;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Support\CanonicalJson;
use Illuminate\Support\Arr;

class EmailSourceAdapter
{
    public function __construct(
        private readonly ChurchServiceAssertionNormalizer $normalizer,
    ) {}

    /**
     * The identity of one email's assertion about one service slot, and the key the source
     * revision is stored under. Defined here rather than rebuilt by each caller: the release
     * gate records evidence against exactly the key the revision it describes was written with.
     */
    public static function sourceKeyFor(InboundEmail $email, OosEmailServicePlan $plan): string
    {
        return $email->message_id.'|'.$plan->key();
    }

    /**
     * @param  bool  $reviewedByPerson  A person approved this import, so the plan carries human
     *                                  authority rather than the unattended parser's verdict.
     */
    public function adapt(
        InboundEmail $email,
        OosEmailServicePlan $plan,
        ?string $inputHash = null,
        bool $reviewedByPerson = false,
    ): ChurchServiceSourceRevision {
        $metadata = $email->processing_metadata ?? [];
        $batchHash = Arr::get($metadata, 'archive.curation_plan_hash');
        $sourceKey = self::sourceKeyFor($email, $plan);
        $planIdentities = Arr::get($metadata, 'archive.plan_identities', []);
        $supersedesSourceKey = null;

        if (is_array($planIdentities)) {
            foreach ($planIdentities as $identity) {
                if (! is_array($identity)
                    || ($identity['source_key'] ?? null) !== $sourceKey
                    || ($identity['plan_key'] ?? null) !== $plan->key()) {
                    continue;
                }

                $candidate = $identity['supersedes_source_key'] ?? null;
                $supersedesSourceKey = is_string($candidate) && $candidate !== '' ? $candidate : null;

                break;
            }
        }

        if (! is_string($batchHash) || preg_match('/^[a-f0-9]{64}$/', $batchHash) !== 1) {
            $batchHash = null;
        }

        return new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: $sourceKey,
            inputHash: $inputHash ?? CanonicalJson::hash([
                'body_html' => $email->body_html,
                'body_plain' => $email->body_plain,
                'subject' => $email->subject,
            ]),
            assertions: $this->normalizer->normalize($plan->items, ChurchServiceEvidenceKind::Planned),
            processingFingerprint: [
                'format' => 'email-plan',
                'version' => 2,
                'manifest_supersedes_source_key' => $supersedesSourceKey,
                // This is provenance for the shared projector, not a parser verdict it may
                // infer later. A ReviewRequired plan can enter as evidence, but only an
                // auto-importable plan cleared the existing confidence-and-consensus route.
                //
                // A person's approval clears it too, and by a stronger authority than either
                // confidence or cross-source corroboration. Without this, §2.5's dimension
                // corroboration reopened an import an admin had just approved, which is
                // invariant 5 — manual authority is never overridden by machine evidence —
                // read backwards.
                'unattended_content_finalization' => $reviewedByPerson || $plan->isAutoImportable(),
            ],
            supersedesSourceKey: $supersedesSourceKey,
            batchHash: $batchHash,
            capturedAt: $email->received_at,
            payloadComplete: $plan->contentScope->payloadComplete(),
        );
    }
}
