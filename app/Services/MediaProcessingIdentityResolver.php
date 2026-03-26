<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;

class MediaProcessingIdentityResolver
{
    /**
     * @return array{date: string, service: SermonService}|null
     */
    public function resolve(MediaProcessingLog $processingLog): ?array
    {
        $metadata = $processingLog->processing_metadata?->toArray() ?? [];

        return $this->resolveFromValues(
            $processingLog->extracted_date?->toDateString(),
            $processingLog->extracted_service,
            $metadata['extracted_date'] ?? null,
            $metadata['extracted_service'] ?? null
        );
    }

    /**
     * @return array{date: string, service: SermonService}|null
     */
    public function resolveFromValues(
        ?string $columnDate,
        ?SermonService $columnService,
        mixed $metadataDate,
        mixed $metadataService
    ): ?array {
        $resolvedDate = $columnDate ?? $this->parseDate($metadataDate);
        $resolvedService = $columnService ?? $this->parseService($metadataService);

        if (! is_string($resolvedDate) || ! $resolvedService instanceof SermonService) {
            return null;
        }

        return [
            'date' => $resolvedDate,
            'service' => $resolvedService,
        ];
    }

    public function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (InvalidFormatException) {
            return null;
        }

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        if ($date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    public function parseService(mixed $value): ?SermonService
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return SermonService::tryFrom($value);
    }

    public function matchesService(MediaProcessingLog $processingLog, string $date, SermonService $service): bool
    {
        $identity = $this->resolve($processingLog);

        return $identity !== null
            && $identity['date'] === $date
            && $identity['service'] === $service;
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeMatchesIdentity(Builder $query, string $date, SermonService $service): Builder
    {
        $serviceValue = $service->value;

        return $query->where(function (Builder $query) use ($date, $serviceValue): void {
            $query->where(function (Builder $query) use ($date, $serviceValue): void {
                $query->whereDate('extracted_date', $date)
                    ->where('extracted_service', $serviceValue);
            })->orWhere(function (Builder $query) use ($date, $serviceValue): void {
                $query->where(function (Builder $query): void {
                    $query->whereNull('extracted_date')
                        ->orWhereNull('extracted_service');
                })->where('processing_metadata->extracted_date', $date)
                    ->where('processing_metadata->extracted_service', $serviceValue);
            });
        });
    }
}
