<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;

class HistoricProcessingResultSectionKey
{
    public function for(string $processingId, ServiceSection $section, ?string $serviceItemIdentity): string
    {
        $signaturePayload = [
            'service_item_identity' => $serviceItemIdentity,
            'section_type' => $section->section_type->value,
            'title' => $section->title,
            'start_time' => (float) $section->start_time,
            'end_time' => (float) $section->end_time,
        ];

        if ($section->section_type === ServiceSectionType::ChildrensTalk) {
            $speaker = $section->publicationChildrensTalkSpeaker();
            $signaturePayload['publication_speaker'] = $speaker === null ? null : [
                'preacher_name' => $speaker['preacher_name'],
                'preacher_slug' => str($speaker['preacher_name'])->slug()->toString(),
                'source' => $speaker['source'],
            ];
        }

        $signature = hash('sha256', json_encode($signaturePayload, JSON_THROW_ON_ERROR));

        return "{$processingId}:section:{$section->section_order}:{$signature}";
    }
}
