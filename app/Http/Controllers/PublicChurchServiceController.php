<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonService;
use App\Seo\ChurchServiceArchiveSeoPresenter;
use App\Services\Public\PublicChurchServiceArchiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PublicChurchServiceController extends Controller
{
    public function __construct(
        private readonly PublicChurchServiceArchiveService $archiveService,
        private readonly ChurchServiceArchiveSeoPresenter $seoPresenter,
    ) {}

    public function index(Request $request): View
    {
        $year = $this->year($request->query('year'));
        $service = $this->service($request->query('service'));
        $page = max(1, $request->integer('page', 1));

        return view('church.services.index', [
            'heading' => $this->seoPresenter->title($year, $service, $page),
            'description' => $this->seoPresenter->description($year, $service, $page),
            'canonical_url' => $this->seoPresenter->canonical($year, $service, $page),
            'area' => 'church',
            'slug' => 'services',
            'links' => collect(),
            'services' => $this->archiveService->paginate($year, $service),
            'years' => $this->archiveService->years(),
            'selectedYear' => $year,
            'selectedService' => $service,
        ]);
    }

    public function show(string $date, string $service): View
    {
        $dateValue = Carbon::createFromFormat('!Y-m-d', $date);
        abort_unless($dateValue instanceof Carbon && $dateValue->format('Y-m-d') === $date, 404);

        $serviceValue = SermonService::tryFrom($service);
        abort_unless($serviceValue instanceof SermonService, 404);

        $churchService = $this->archiveService->find($dateValue, $serviceValue);
        $publicItems = $this->archiveService->publicItems($churchService);

        return view('church.services.show', [
            'heading' => $this->seoPresenter->detailTitle($churchService),
            'description' => $this->seoPresenter->detailDescription(
                $churchService,
                $publicItems->contains(fn (array $item): bool => $item['kind'] === 'sermon'),
            ),
            'canonical_url' => $this->seoPresenter->detailCanonical($churchService),
            'area' => 'church',
            'slug' => 'services',
            'links' => collect(),
            'churchService' => $churchService,
            'publicItems' => $publicItems,
        ]);
    }

    private function year(mixed $value): ?int
    {
        if (is_array($value) || (! is_int($value) && ! is_string($value))) {
            return null;
        }

        $year = (int) $value;

        return $year >= 1900 && $year <= 2200 ? $year : null;
    }

    private function service(mixed $value): ?SermonService
    {
        return is_string($value) ? SermonService::tryFrom($value) : null;
    }
}
