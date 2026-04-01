<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SermonService;
use App\Http\Controllers\Controller;
use App\Http\Resources\SermonResource;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Services\SermonExposurePolicy;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SermonApiController extends Controller
{
    use EscapesLikeWildcards;

    public function __construct(
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Display a listing of sermons.
     *
     * Security: Strict input validation is enforced on query parameters to provide
     * Defense in Depth against malformed input and potential Denial of Service (DoS)
     * attacks by ensuring all inputs are bounded and correctly typed.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'service' => 'nullable|string|max:50',
            'preacher' => 'nullable|string|max:255',
            'preacher_id' => 'nullable|integer',
            'series' => 'nullable|string|max:255',
            'sort' => 'nullable|string|in:date,title,preacher,series,service',
            'order' => 'nullable|string|in:asc,desc,ASC,DESC',
            'per_page' => 'nullable|integer|min:1|max:100',
            'with_thumbnail' => 'nullable|boolean',
        ]);

        /**
         * Performance Optimization: Eager load preacherProfile and limit retrieved columns
         * for both Sermon and Preacher models to required fields for the API resource
         * to reduce memory usage and DB I/O.
         */
        $query = Sermon::query()
            ->whereSermon()
            ->select([
                'id', 'title', 'slug', 'date', 'service', 'preacher', 'preacher_id',
                'preacher_source', 'preacher_confidence', 'needs_preacher_review',
                'series', 'reference', 'scripture_passage_id', 'points', 'show_summary', 'show_points', 'audio_file_path', 'filetype', 'thumbnail_file_path',
                'thumbnail_metadata',
            ])
            ->with([
                'preacherProfile:id,name,slug,image_path',
                'scripturePassage:id,display_reference,normalized_reference',
            ]);

        // Search functionality
        if ($request->has('search')) {
            $search = (string) $request->get('search');
            // Escape special characters to prevent LIKE injection (Defense in Depth)
            $escapedSearch = $this->escapeLike($search);

            $searchPattern = '%'.$escapedSearch.'%';

            $query->where(function ($q) use ($searchPattern) {
                $q->where('title', 'like', $searchPattern)
                    ->orWhere('preacher', 'like', $searchPattern)
                    ->orWhereHas('preacherProfile', fn ($preacherQuery) => $preacherQuery->where('name', 'like', $searchPattern))
                    ->orWhere('series', 'like', $searchPattern)
                    ->orWhere('reference', 'like', $searchPattern)
                    ->orWhereHas('scripturePassage', fn ($passageQuery) => $passageQuery
                        ->where('display_reference', 'like', $searchPattern)
                        ->orWhere('normalized_reference', 'like', $searchPattern));
            });
        }

        // Filter by service if provided
        if ($request->has('service')) {
            $serviceEnum = SermonService::tryFrom((string) $request->get('service'));
            if ($serviceEnum !== null) {
                $query->forService($serviceEnum);
            }
        }

        // Filter by preacher if provided
        if ($request->has('preacher')) {
            $query->byPreacher($request->get('preacher'));
        }

        // Filter by preacher_id if provided
        if ($request->has('preacher_id')) {
            $query->where('preacher_id', $request->get('preacher_id'));
        }

        // Filter by series if provided
        if ($request->has('series')) {
            $query->inSeries($request->get('series'));
        }

        // Filter to only sermons with thumbnails if requested
        if ($request->boolean('with_thumbnail')) {
            $query->withThumbnail();
        }

        // Sorting functionality
        $sortField = $request->get('sort', 'date');
        $sortOrder = $request->get('order', 'desc');

        // Validate sort field to prevent SQL injection
        $allowedSortFields = ['date', 'title', 'preacher', 'series', 'service'];
        $sortField = in_array($sortField, $allowedSortFields) ? $sortField : 'date';
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? $sortOrder : 'desc';

        if ($sortField === 'preacher') {
            $query->orderByPreacherName($sortOrder);
        } else {
            $query->orderBy($sortField, $sortOrder);
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $sermons = $query->paginate($perPage);
        $sermons->through(fn (Sermon $sermon): Sermon => $this->withSermonView($sermon));

        return SermonResource::collection($sermons);
    }

    /**
     * Display the specified sermon
     */
    public function show(Sermon $sermon, SermonExposurePolicy $exposurePolicy): SermonResource
    {
        abort_unless($exposurePolicy->shouldExposeOnSermonApi($sermon), 404);

        $sermon->load('preacherProfile', 'scripturePassage');

        return new SermonResource($this->withSermonView($sermon));
    }

    private function withSermonView(Sermon $sermon): Sermon
    {
        $sermon->setAttribute('sermon_view', $this->sermonViewPresenter->present($sermon));

        return $sermon;
    }
}
