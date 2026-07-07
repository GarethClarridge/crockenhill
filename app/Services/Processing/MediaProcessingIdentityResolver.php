<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Service for resolving sermon identity (date and service) from processing logs.
 *
 * This resolver provides structured access to the metadata extracted during
 * the media processing pipeline, allowing other services to associate
 * processing runs with specific church services or sermon records.
 */
class MediaProcessingIdentityResolver
{
    /**
     * Resolve the date and service identity from a processing log.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to resolve
     * @return array{date: string, service: SermonService}|null The resolved identity or null if incomplete
     */
    public function resolve(MediaProcessingLog $processingLog): ?array
    {
        $date = $processingLog->extracted_date?->toDateString();
        $service = $processingLog->extracted_service;

        if (! is_string($date) || ! $service instanceof SermonService) {
            return null;
        }

        return ['date' => $date, 'service' => $service];
    }

    /**
     * Parse and validate a raw date string into a canonical ISO format.
     *
     * @param  mixed  $value  The raw date value to parse
     * @return string|null The validated 'Y-m-d' date string or null if invalid
     */
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

    /**
     * Parse and validate a raw service identifier into a SermonService enum.
     *
     * @param  mixed  $value  The raw service value to parse
     * @return SermonService|null The matched SermonService or null if invalid
     */
    public function parseService(mixed $value): ?SermonService
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return SermonService::tryFrom($value);
    }

    /**
     * Determine if a processing log matches a specific date and service.
     *
     * @param  MediaProcessingLog  $processingLog  The log record to check
     * @param  string  $date  The ISO date string to match
     * @param  SermonService  $service  The service type to match
     * @return bool True if the log identity matches the criteria
     */
    public function matchesService(MediaProcessingLog $processingLog, string $date, SermonService $service): bool
    {
        $identity = $this->resolve($processingLog);

        return $identity !== null
            && $identity['date'] === $date
            && $identity['service'] === $service;
    }

    /**
     * Scope a query to MediaProcessingLog records matching a specific identity.
     *
     * @param  Builder<MediaProcessingLog>  $query
     * @param  string  $date  The ISO date string to filter by
     * @param  SermonService  $service  The service type to filter by
     * @return Builder<MediaProcessingLog>
     */
    public function scopeMatchesIdentity(Builder $query, string $date, SermonService $service): Builder
    {
        return $query->whereDate('extracted_date', $date)
            ->where('extracted_service', $service->value);
    }
}
