<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TalkType;
use App\Models\Sermon;
use App\Services\Sermon\SermonExposurePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Permanent redirects from the URLs the talks archive answered at before it
 * moved to `/christ/talks`. Kept indefinitely: inbound links and podcast apps
 * still hold the old paths.
 */
class LegacyTalkRedirectController extends Controller
{
    public function sermons(Request $request, SermonExposurePolicy $exposurePolicy, string $path = ''): RedirectResponse
    {
        $target = url('christ/talks'.($path === '' ? '' : '/'.$path));
        $query = $request->getQueryString();

        return redirect()->to(
            $this->slugOnlyTalkUrl($target, $exposurePolicy) ?? ($query === null ? $target : $target.'?'.$query),
            301,
        );
    }

    /**
     * The new slug-only URL would itself 301 to the dated URL, so an old slug-only
     * URL goes to the dated one directly: one redirect, not two. Anything that is
     * not a public talk's slug is left to the new path to answer.
     */
    private function slugOnlyTalkUrl(string $target, SermonExposurePolicy $exposurePolicy): ?string
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($target));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return null;
        }

        $slug = $route->parameter('sermon');

        if ($route->getName() !== 'sermons.show' || ! is_string($slug)) {
            return null;
        }

        $sermon = Sermon::query()->where('slug', $slug)->first();

        return $sermon === null ? null : ($exposurePolicy->canonicalUrl($sermon) ?: null);
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
