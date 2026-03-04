<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SermonResource;
use App\Models\Sermon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SermonApiController extends Controller
{
    /**
     * Display a listing of sermons
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /**
         * Performance Optimization: Eager load preacherProfile and limit retrieved columns
         * for both Sermon and Preacher models to required fields for the API resource
         * to reduce memory usage and DB I/O.
         */
        $query = Sermon::query()
            ->select([
                'id', 'title', 'slug', 'date', 'service', 'preacher', 'preacher_id',
                'preacher_source', 'preacher_confidence', 'needs_preacher_review',
                'series', 'reference', 'points', 'audio_file_path', 'thumbnail_file_path',
                'thumbnail_metadata',
            ])
            ->with('preacherProfile:id,name,slug,image_path');

        // Search functionality
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhere('preacher', 'like', '%'.$search.'%')
                    ->orWhere('series', 'like', '%'.$search.'%')
                    ->orWhere('reference', 'like', '%'.$search.'%');
            });
        }

        // Filter by service if provided
        if ($request->has('service')) {
            $query->forService($request->get('service'));
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

        $query->orderBy($sortField, $sortOrder);

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $sermons = $query->paginate($perPage);

        return SermonResource::collection($sermons);
    }

    /**
     * Display the specified sermon
     */
    public function show(Sermon $sermon): SermonResource
    {
        $sermon->load('preacherProfile');

        return new SermonResource($sermon);
    }
}
