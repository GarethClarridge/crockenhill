<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TalkType;
use App\Models\Sermon;
use App\Services\Sermon\SermonExposurePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Permanent redirects from the URLs the talks archive answered at before it
 * moved to `/christ/talks`. Kept indefinitely: inbound links and podcast apps
 * still hold the old paths.
 */
class LegacyTalkRedirectController extends Controller
{
    public function sermons(Request $request, string $path = ''): RedirectResponse
    {
        $target = url('christ/talks'.($path === '' ? '' : '/'.$path));
        $query = $request->getQueryString();

        return redirect()->to($query === null ? $target : $target.'?'.$query, 301);
    }

    public function childrensCorner(): RedirectResponse
    {
        return redirect()->to(route('sermons.index', ['type' => TalkType::ChildrensTalk->value]), 301);
    }

    public function childrensTalk(string $slug, SermonExposurePolicy $exposurePolicy): RedirectResponse
    {
        $sermon = Sermon::query()->where('slug', $slug)->firstOrFail();
        $url = $exposurePolicy->canonicalUrl($sermon);

        abort_if($sermon->content_type->isSermon() || $url === '', 404);

        return redirect()->to($url, 301);
    }
}
