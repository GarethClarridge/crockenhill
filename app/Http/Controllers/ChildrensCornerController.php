<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Presenters\RelatedPagePresenter;
use App\Presenters\SermonItemListPresenter;
use App\Presenters\SermonViewPresenter;
use Illuminate\View\View;

class ChildrensCornerController extends Controller
{
    public function __construct(
        private readonly RelatedPagePresenter $relatedPagePresenter,
        private readonly SermonViewPresenter $sermonViewPresenter,
        private readonly SermonItemListPresenter $itemListPresenter,
    ) {}

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
                'video_quality_status',
                'video_visibility_override',
                'thumbnail_file_path',
                'thumbnail_metadata',
                'scripture_passage_id',
            ])
            ->with([
                'preacherProfile:id,name,slug',
                'scripturePassage:id,display_reference,normalized_reference',
            ])
            ->orderBy('date', 'desc')
            ->paginate(12);

        return view('childrens-corner.index', [
            'heading' => "Children's Corner",
            'area' => 'christ',
            'slug' => 'childrens-corner',
            'description' => "Short Bible talks for children from Crockenhill Baptist Church. Browse recent Children's Corner videos and audio.",
            'links' => $this->relatedPagePresenter->ordered(
                linkArea: 'christ',
                slugToExclude: 'childrens-corner',
                secondSlugToExclude: 'christ',
                excludeAdminPages: true,
                extraExcludedSlugs: ['privacy-policy'],
            ),
            'talks' => $talks,
            'json_ld_data' => $this->itemListPresenter->toItemList($talks->getCollection()),
        ]);
    }

    public function show(Sermon $sermon): View
    {
        abort_unless($sermon->content_type === SermonContentType::ChildrensTalk, 404);

        $sermon->loadMissing('preacherProfile:id,name,slug');

        $sermonView = $this->sermonViewPresenter->present($sermon);
        $speakerName = $sermonView['preacher_name'];
        $fullTitle = $sermon->title.($speakerName ? ' | '.$speakerName : '');

        return view('childrens-corner.show', [
            'heading' => $sermon->title,
            'area' => 'christ',
            'slug' => 'childrens-corner',
            'description' => $sermon->meta_description ?: $sermon->title,
            'links' => $this->relatedPagePresenter->ordered(
                linkArea: 'christ',
                slugToExclude: 'childrens-corner',
                secondSlugToExclude: 'christ',
                excludeAdminPages: true,
                extraExcludedSlugs: ['privacy-policy'],
            ),
            'sermon' => $sermon,
            'sermonView' => $sermonView,
            'speakerName' => $speakerName,
            'fullTitle' => $fullTitle,
            'metaDescription' => $this->sermonViewPresenter->metaDescription($sermon),
        ]);
    }
}
