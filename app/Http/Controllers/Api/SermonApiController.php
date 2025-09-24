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
        $query = Sermon::query();

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

        $sermons = $query->paginate($request->get('per_page', 15));

        return SermonResource::collection($sermons);
    }

    /**
     * Display the specified sermon
     */
    public function show(Sermon $sermon): SermonResource
    {
        return new SermonResource($sermon);
    }
}
