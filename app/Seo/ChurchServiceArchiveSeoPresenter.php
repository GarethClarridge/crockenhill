<?php

declare(strict_types=1);

namespace App\Seo;

use App\Enums\SermonService;
use App\Models\ChurchService;

class ChurchServiceArchiveSeoPresenter
{
    public function title(?int $year, ?SermonService $service, int $page = 1): string
    {
        $base = match (true) {
            $year !== null && $service instanceof SermonService => "{$service->label()} services in {$year}",
            $year !== null => "Services in {$year}",
            $service instanceof SermonService => "{$service->label()} services",
            default => 'Church services',
        };

        return $page > 1 ? "{$base} (Page {$page})" : $base;
    }

    public function description(?int $year, ?SermonService $service, int $page = 1): string
    {
        $scope = match (true) {
            $year !== null && $service instanceof SermonService => "{$service->label()} services from {$year}",
            $year !== null => "services from {$year}",
            $service instanceof SermonService => "{$service->label()} services",
            default => 'services',
        };

        $description = "Browse the public history of {$scope} at Crockenhill Baptist Church.";

        return $page > 1 ? "{$description} - Page {$page}" : $description;
    }

    /**
     * Canonical for a listing view.
     *
     * Filters and pages each get their own canonical rather than collapsing onto
     * page one, so paginated history is indexable instead of invisible.
     */
    public function canonical(?int $year, ?SermonService $service, int $page = 1): string
    {
        return route('church.services.index', array_filter([
            'year' => $year,
            'service' => $service?->value,
            'page' => $page > 1 ? $page : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function detailTitle(ChurchService $churchService): string
    {
        return $churchService->date->format('j F Y').' — '.$churchService->service->label().' service';
    }

    /**
     * A service with no sermon must not be described as having one. The
     * 2024-02-11 evening was a visiting mission's presentation from beginning to
     * end; naming a sermon in its meta description is simply false (D3,
     * 2026-09-03).
     *
     * `$includesSermon` is the page's own answer rather than anything derived
     * here: what the page shows is what the description should describe, and
     * only the caller has already resolved which items are publicly exposable.
     */
    public function detailDescription(ChurchService $churchService, bool $includesSermon): string
    {
        $contents = $includesSermon
            ? 'The order of service, sermon, songs and readings'
            : 'The order of service, songs and readings';

        return $contents.' for the '
            .strtolower($churchService->service->label()).' service on '
            .$churchService->date->format('j F Y').' at Crockenhill Baptist Church.';
    }

    public function detailCanonical(ChurchService $churchService): string
    {
        return route('church.services.show', [
            'date' => $churchService->date->format('Y-m-d'),
            'service' => $churchService->service->value,
        ]);
    }
}
