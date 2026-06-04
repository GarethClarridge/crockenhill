<?php

declare(strict_types=1);

namespace App\Services\Processing;

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
        $date = $processingLog->extracted_date?->toDateString();
        $service = $processingLog->extracted_service;

        if (! is_string($date) || ! $service instanceof SermonService) {
            return null;
        }

        return ['date' => $date, 'service' => $service];
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
        return $query->whereDate('extracted_date', $date)
            ->where('extracted_service', $service->value);
    }
}
