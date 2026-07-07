<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Presenters\RelatedPagePresenter;
use App\Presenters\SermonViewPresenter;
use App\Seo\SermonItemListPresenter;
use App\Services\Public\SermonRepository;
use Illuminate\View\View;

class ChildrensCornerController extends Controller
{
    public function __construct(
        private readonly RelatedPagePresenter $relatedPagePresenter,
        private readonly SermonViewPresenter $sermonViewPresenter,
        private readonly SermonItemListPresenter $itemListPresenter,
        private readonly SermonRepository $sermonRepository,
    ) {}

    public function index(): View
    {
        $talks = $this->sermonRepository
            ->basePublicSermonQuery(SermonContentType::ChildrensTalk)
            ->orderBy('date', 'desc')
            ->paginate(12);

        $collection = $talks->getCollection();
        $presented = $this->sermonViewPresenter->presentCollection($collection);

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
            'presentedTalks' => $presented,
            'json_ld_data' => $this->itemListPresenter->toItemList($collection),
        ]);
    }

    public function show(Sermon $sermon): View
    {
        abort_unless($sermon->content_type === SermonContentType::ChildrensTalk, 404);

        /**
         * Performance Optimization: Limits retrieved columns for related models to
         * required fields to reduce memory usage and DB I/O on the single talk view.
         */
        $sermon->loadMissing([
            'preacherProfile:id,name,slug,image_path',
            'scripturePassage:id,display_reference,normalized_reference',
        ]);

        $sermonView = $this->sermonViewPresenter->present($sermon);
        $speakerName = $sermonView['preacher_name'];
        $fullTitle = $sermon->title.($speakerName ? ' | '.$speakerName : '');

        return view('childrens-corner.show', [
            'heading' => $sermon->title,
            'area' => 'christ',
            'slug' => 'childrens-corner',
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
