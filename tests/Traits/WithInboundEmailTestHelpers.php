<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Data\OosEmailItemExtractionResult;
use App\Services\Email\OosSemanticParserCandidate;
use Tests\Support\FixedOosSemanticParserCandidate;

trait WithInboundEmailTestHelpers
{
    /**
     * Build a standard processing_metadata array shaped like a stored parse result.
     *
     * The `disposition` is written explicitly because a stored parse without one is treated as
     * predating OosEmailExtractionValidator and is refused an unattended import. Pass a different
     * value to exercise the held/invalid paths, or `null` to mimic a pre-validator cache.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    protected function processingMetadata(
        string $resolvedDate,
        string $resolvedService,
        array $items,
        array $warnings = [],
        float $confidenceScore = 0.70,
        bool $needsReview = false,
        bool $shouldImport = true,
        ?string $disposition = 'auto_importable',
    ): array {
        $plan = [
            'plan_key' => "{$resolvedService}:{$resolvedDate}",
            'service' => $resolvedService,
            'date' => $resolvedDate,
            'items' => $items,
            'confidence' => $confidenceScore,
            'needs_review' => $needsReview,
            'should_import' => $shouldImport,
        ];

        $parsing = [
            'confidence_score' => $confidenceScore,
            'warnings' => $warnings,
            'resolved_date' => $resolvedDate,
            'resolved_service' => $resolvedService,
            'items' => $items,
            'needs_review' => $needsReview,
            'should_import' => $shouldImport,
        ];

        if ($disposition !== null) {
            $parsing['disposition'] = $disposition;
            $plan['disposition'] = $disposition;
        }

        $parsing['service_plans'] = [$plan];

        return ['parsing' => $parsing];
    }

    /**
     * Build processing_metadata for a multi-service email. The parser stores the primary plan's
     * fields at the top level as well, so the flattened `items` cover only the first plan.
     *
     * @param  array<int, array{date:string,service:string,items:array<int, array<string, mixed>>}>  $plans
     * @return array<string, mixed>
     */
    protected function multiServiceProcessingMetadata(
        array $plans,
        float $confidenceScore = 0.70,
        ?string $disposition = 'auto_importable',
    ): array {
        $primary = $plans[0];
        $metadata = $this->processingMetadata(
            $primary['date'],
            $primary['service'],
            $primary['items'],
            confidenceScore: $confidenceScore,
            disposition: $disposition,
        );

        $metadata['parsing']['service_plans'] = array_map(static function (array $plan) use ($confidenceScore, $disposition): array {
            $storedPlan = [
                'plan_key' => "{$plan['service']}:{$plan['date']}",
                'service' => $plan['service'],
                'date' => $plan['date'],
                'items' => $plan['items'],
                'confidence' => $confidenceScore,
                'needs_review' => false,
                'should_import' => true,
            ];

            if ($disposition !== null) {
                $storedPlan['disposition'] = $disposition;
            }

            return $storedPlan;
        }, $plans);

        return $metadata;
    }

    protected function bindExtractor(OosEmailItemExtractionResult $result): void
    {
        $this->app->bind(
            OosSemanticParserCandidate::class,
            fn () => FixedOosSemanticParserCandidate::returning($result),
        );
    }

    protected function bindFailingExtractor(): void
    {
        $this->app->bind(
            OosSemanticParserCandidate::class,
            fn () => FixedOosSemanticParserCandidate::unreachable(
                'Stored parse data should have been used instead of reparsing.',
            ),
        );
    }
}
