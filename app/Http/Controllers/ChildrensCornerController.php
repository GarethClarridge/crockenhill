<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use Illuminate\View\View;

class ChildrensCornerController extends Controller
{
    public function index(): View
    {
        $talks = Sermon::query()
            ->whereChildrensTalk()
            ->select([
                'id',
                'title',
                'slug',
                'date',
                'preacher',
                'preacher_id',
                'audio_file_path',
                'video_file_path',
                'thumbnail_file_path',
                'thumbnail_metadata',
            ])
            ->with('preacherProfile:id,name,slug')
            ->orderBy('date', 'desc')
            ->paginate(12);

        return view('childrens-corner.index', [
            'heading' => "Children's Corner",
            'area' => 'christ',
            'slug' => 'childrens-corner',
            'description' => "Short Bible talks for children from Crockenhill Baptist Church. Browse recent Children's Corner videos and audio.",
            'talks' => $talks,
        ]);
    }

    public function show(Sermon $sermon): View
    {
        abort_unless($sermon->content_type === SermonContentType::ChildrensTalk, 404);

        $sermon->loadMissing('preacherProfile:id,name,slug');

        return view('childrens-corner.show', [
            'heading' => $sermon->title,
            'area' => 'christ',
            'slug' => 'childrens-corner',
            'description' => $sermon->meta_description ?: $sermon->title,
            'sermon' => $sermon,
        ]);
    }
}
