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

class EmailSourceAdapter
{
    public function __construct(
        private readonly ChurchServiceAssertionNormalizer $normalizer,
    ) {}

    public function adapt(InboundEmail $email, OosEmailServicePlan $plan): ChurchServiceSourceRevision
    {
        return new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: $email->message_id.'|'.$plan->key(),
            inputHash: CanonicalJson::hash([
                'body_html' => $email->body_html,
                'body_plain' => $email->body_plain,
                'subject' => $email->subject,
            ]),
            assertions: $this->normalizer->normalize($plan->items, ChurchServiceEvidenceKind::Planned),
            processingFingerprint: [
                'format' => 'email-plan',
                'version' => 1,
            ],
            capturedAt: $email->received_at,
        );
    }
}
