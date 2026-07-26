<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;

trait WithInboundEmailTestHelpers
{
    /**
     * Build a standard processing_metadata array shaped like a stored parse result.
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
    ): array {
        return [
            'parsing' => [
                'confidence_score' => $confidenceScore,
                'warnings' => $warnings,
                'resolved_date' => $resolvedDate,
                'resolved_service' => $resolvedService,
                'items' => $items,
                'needs_review' => $needsReview,
                'should_import' => $shouldImport,
                'service_plans' => [
                    [
                        'plan_key' => "{$resolvedService}:{$resolvedDate}",
                        'service' => $resolvedService,
                        'date' => $resolvedDate,
                        'items' => $items,
                        'confidence' => $confidenceScore,
                        'needs_review' => $needsReview,
                        'should_import' => $shouldImport,
                    ],
                ],
            ],
        ];
    }

    /**
     * Build processing_metadata for a multi-service email. The parser stores the primary plan's
     * fields at the top level as well, so the flattened `items` cover only the first plan.
     *
     * @param  array<int, array{date:string,service:string,items:array<int, array<string, mixed>>}>  $plans
     * @return array<string, mixed>
     */
    protected function multiServiceProcessingMetadata(array $plans, float $confidenceScore = 0.70): array
    {
        $primary = $plans[0];
        $metadata = $this->processingMetadata(
            $primary['date'],
            $primary['service'],
            $primary['items'],
            confidenceScore: $confidenceScore,
        );

        $metadata['parsing']['service_plans'] = array_map(static fn (array $plan): array => [
            'plan_key' => "{$plan['service']}:{$plan['date']}",
            'service' => $plan['service'],
            'date' => $plan['date'],
            'items' => $plan['items'],
            'confidence' => $confidenceScore,
            'needs_review' => false,
            'should_import' => true,
        ], $plans);

        return $metadata;
    }

    protected function bindExtractor(OosEmailItemExtractionResult $result): void
    {
        $this->app->bind(OosEmailItemExtractor::class, fn () => new class($result) implements OosEmailItemExtractor
        {
            public function __construct(
                private readonly OosEmailItemExtractionResult $result,
            ) {}

            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                return $this->result;
            }
        });
    }

    protected function bindFailingExtractor(): void
    {
        $this->app->bind(OosEmailItemExtractor::class, fn () => new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body, string $receivedDate): OosEmailItemExtractionResult
            {
                throw new \RuntimeException('Stored parse data should have been used instead of reparsing.');
            }
        });
    }
}
