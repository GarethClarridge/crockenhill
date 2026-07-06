<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SermonService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SermonIndexRequest;
use App\Http\Resources\SermonResource;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Services\Sermon\SermonExposurePolicy;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SermonApiController extends Controller
{
    use EscapesLikeWildcards;

    public function __construct(
        private readonly SermonViewPresenter $sermonViewPresenter,
    ) {}

    /**
     * Display a listing of sermons.
     *
     * Security: Strict input validation is enforced on query parameters via SermonIndexRequest
     * to provide Defense in Depth against malformed input and potential Denial of Service (DoS)
     * attacks by ensuring all inputs are bounded and correctly typed.
     *
     * @throws ValidationException
     */
    public function index(SermonIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

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
                'series', 'reference', 'scripture_passage_id', 'duration', 'points', 'show_summary', 'show_points', 'audio_file_path', 'video_file_path',
                'video_quality_status', 'video_visibility_override', 'filetype', 'thumbnail_file_path',
                'thumbnail_metadata', 'updated_at', 'meta_description',
            ])
            ->with([
                'preacherProfile:id,name,slug,image_path',
                'scripturePassage:id,display_reference,normalized_reference',
            ]);

        // Search functionality
        if (isset($validated['search'])) {
            $search = (string) $validated['search'];
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
        if (isset($validated['service'])) {
            $serviceEnum = SermonService::tryFrom((string) $validated['service']);
            if ($serviceEnum !== null) {
                $query->forService($serviceEnum);
            }
        }

        // Filter by preacher if provided
        if (isset($validated['preacher'])) {
            $query->byPreacher($validated['preacher']);
        }

        // Filter by preacher_id if provided
        if (isset($validated['preacher_id'])) {
            $query->where('preacher_id', $validated['preacher_id']);
        }

        // Filter by series if provided
        if (isset($validated['series'])) {
            $query->inSeries($validated['series']);
        }

        // Filter to only sermons with thumbnails if requested
        if ($request->boolean('with_thumbnail')) {
            $query->withThumbnail();
        }

        // Sorting functionality
        $sortField = $validated['sort'] ?? 'date';
        $sortOrder = $validated['order'] ?? 'desc';

        if ($sortField === 'preacher') {
            $query->orderByPreacherName($sortOrder);
        } else {
            $query->orderBy($sortField, $sortOrder);
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $sermons = $query->paginate($perPage);
        $sermons->through(fn (Sermon $sermon): Sermon => $this->withSermonViewForApi($sermon));

        return SermonResource::collection($sermons);
    }

    /**
     * Display the specified sermon.
     *
     * @throws NotFoundHttpException If the sermon is not found or not exposed.
     */
    public function show(Sermon $sermon, SermonExposurePolicy $exposurePolicy): SermonResource
    {
        abort_unless($exposurePolicy->shouldExposeOnSermonApi($sermon), 404);

        $sermon->load([
            'preacherProfile:id,name,slug,image_path',
            'scripturePassage:id,display_reference,normalized_reference',
        ]);

        return new SermonResource($this->withSermonViewForApi($sermon));
    }

    private function withSermonViewForApi(Sermon $sermon): Sermon
    {
        $sermon->setAttribute('sermon_view', $this->sermonViewPresenter->presentForApi($sermon));

        return $sermon;
    }
}
