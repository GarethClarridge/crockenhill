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

    public function adapt(
        InboundEmail $email,
        OosEmailServicePlan $plan,
        ?string $inputHash = null,
    ): ChurchServiceSourceRevision {
        $metadata = $email->processing_metadata ?? [];
        $batchHash = Arr::get($metadata, 'archive.curation_plan_hash');
        $sourceKey = $email->message_id.'|'.$plan->key();
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
                'version' => 1,
                'manifest_supersedes_source_key' => $supersedesSourceKey,
            ],
            supersedesSourceKey: $supersedesSourceKey,
            batchHash: $batchHash,
            capturedAt: $email->received_at,
            payloadComplete: $plan->contentScope->payloadComplete(),
        );
    }
}
