<?php

declare(strict_types=1);

namespace App\Actions\InboundEmail;

use App\Enums\SermonService;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailHtmlSanitizer;

class InboundEmailPreviewFactory
{
    public function __construct(
        private readonly InboundEmailHtmlSanitizer $htmlSanitizer,
    ) {}

    /**
     * Assemble the preview read-model for a single inbound email review card.
     *
     * Extracts and validates metadata from the stored parsing result, normalises
     * body content, and sanitizes HTML for safe inline rendering.
     *
     * @return array{
     *     preview_items: array<int, array<string, mixed>>,
     *     warnings: array<int, string>,
     *     raw_warnings_json: ?string,
     *     failure_message: ?string,
     *     resolved_date: mixed,
     *     resolved_service: mixed,
     *     confidence_score: ?float,
     *     can_approve: bool,
     *     plain_body: ?string,
     *     has_plain_body: bool,
     *     sanitized_html: ?string,
     *     has_html_body: bool,
     *     raw_parsing_json: ?string,
     *     reparsed_at: ?string,
     *     service_plans: array<int, array<string, mixed>>,
     *     is_legacy_flattened: bool
     * }
     */
    public function build(InboundEmail $inboundEmail): array
    {
        $metadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];
        $parsing = is_array($metadata['parsing'] ?? null) ? $metadata['parsing'] : [];
        $failure = is_array($metadata['failure'] ?? null) ? $metadata['failure'] : [];
        $previewItems = array_values(array_filter(
            is_array($parsing['items'] ?? null) ? $parsing['items'] : [],
            static fn (mixed $item): bool => is_array($item),
        ));
        $warnings = array_values(array_filter(
            is_array($parsing['warnings'] ?? null) ? $parsing['warnings'] : [],
            static fn (mixed $warning): bool => is_string($warning) && $warning !== '',
        ));
        $plainBody = $this->normalisePlainBody($inboundEmail->body_plain);
        $rawWarningsJson = $this->encodeJson($warnings !== [] ? $warnings : null);

        $storedPlans = is_array($parsing['service_plans'] ?? null) ? $parsing['service_plans'] : null;
        $servicePlans = $storedPlans !== null ? $this->buildServicePlanPreviews($storedPlans) : [];
        $resolvedPlanKeys = array_values(array_filter(
            is_array($metadata['resolved_plan_keys'] ?? null) ? $metadata['resolved_plan_keys'] : [],
            static fn (mixed $key): bool => is_string($key),
        ));
        // A stored parse with a flattened item list but no service_plans predates multi-service
        // support and cannot be approved directly — the UI must offer a re-parse instead.
        $isLegacyFlattened = $storedPlans === null && is_array($parsing['items'] ?? null) && $parsing['items'] !== [];

        return [
            'preview_items' => $previewItems,
            'warnings' => $warnings,
            'raw_warnings_json' => $rawWarningsJson,
            'failure_message' => isset($failure['message']) && is_string($failure['message']) ? $failure['message'] : null,
            'resolved_date' => $parsing['resolved_date'] ?? null,
            'resolved_service' => $parsing['resolved_service'] ?? null,
            'confidence_score' => is_numeric($parsing['confidence_score'] ?? null) ? (float) $parsing['confidence_score'] : null,
            'can_approve' => $this->canApprovePreview(
                $previewItems,
                $parsing['resolved_date'] ?? null,
                $parsing['resolved_service'] ?? null,
            ),
            'plain_body' => $plainBody,
            'has_plain_body' => $plainBody !== null,
            'sanitized_html' => $this->htmlSanitizer->sanitize($inboundEmail->body_html),
            'has_html_body' => is_string($inboundEmail->body_html) && trim($inboundEmail->body_html) !== '',
            'raw_parsing_json' => $this->encodeJson($parsing !== [] ? $parsing : null),
            'reparsed_at' => is_string($metadata['reparsed_at'] ?? null) ? $metadata['reparsed_at'] : null,
            'service_plans' => $this->markResolvedPlans($servicePlans, $resolvedPlanKeys),
            'is_legacy_flattened' => $isLegacyFlattened,
        ];
    }

    /**
     * @param  array<int, mixed>  $storedPlans
     * @return array<int, array{plan_key:string,service:?string,date:?string,confidence:?float,needs_review:bool,should_import:bool,preview_items:array<int,array<string,mixed>>,can_approve:bool,resolved:bool}>
     */
    private function buildServicePlanPreviews(array $storedPlans): array
    {
        $previews = [];

        foreach ($storedPlans as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $service = is_string($plan['service'] ?? null) ? $plan['service'] : null;
            $date = is_string($plan['date'] ?? null) && $plan['date'] !== '' ? $plan['date'] : null;
            $items = array_values(array_filter(
                is_array($plan['items'] ?? null) ? $plan['items'] : [],
                static fn (mixed $item): bool => is_array($item),
            ));

            $previews[] = [
                'plan_key' => is_string($plan['plan_key'] ?? null) ? $plan['plan_key'] : "{$service}:{$date}",
                'service' => $service,
                'date' => $date,
                'confidence' => is_numeric($plan['confidence'] ?? null) ? (float) $plan['confidence'] : null,
                'needs_review' => (bool) ($plan['needs_review'] ?? false),
                'should_import' => (bool) ($plan['should_import'] ?? false),
                'preview_items' => $items,
                'can_approve' => $this->canApprovePreview($items, $date, $service),
                'resolved' => false,
            ];
        }

        return $previews;
    }

    /**
     * @param  array<int, array<string, mixed>>  $servicePlans
     * @param  list<string>  $resolvedPlanKeys
     * @return array<int, array<string, mixed>>
     */
    private function markResolvedPlans(array $servicePlans, array $resolvedPlanKeys): array
    {
        return array_map(static function (array $plan) use ($resolvedPlanKeys): array {
            $plan['resolved'] = in_array($plan['plan_key'] ?? null, $resolvedPlanKeys, true);

            return $plan;
        }, $servicePlans);
    }

    /**
     * @param  array<int, array<string, mixed>>  $previewItems
     */
    private function canApprovePreview(array $previewItems, mixed $resolvedDate, mixed $resolvedService): bool
    {
        return is_string($resolvedDate)
            && $resolvedDate !== ''
            && is_string($resolvedService)
            && SermonService::tryFrom($resolvedService) instanceof SermonService
            && $previewItems !== [];
    }

    private function normalisePlainBody(?string $plainBody): ?string
    {
        if (! is_string($plainBody) || trim($plainBody) === '') {
            return null;
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $plainBody));
    }

    /**
     * @param  array<int, string>|array<string, mixed>|null  $data
     */
    private function encodeJson(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }
}
