<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SermonContentType;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\Sermon;
use Illuminate\Contracts\Auth\Authenticatable;

class SermonExposurePolicy
{
    private bool $childrensTalksArePublic;

    private bool $enforceVideoQuality;

    private bool $hideNeedsReviewVideo;

    public function __construct()
    {
        /**
         * Performance Optimization: Resolve and store configuration values in the constructor
         * to avoid redundant config() lookups during high-frequency request cycles.
         */
        $this->childrensTalksArePublic = (bool) config('sermons.childrens_talks.public', false);
        $this->enforceVideoQuality = (bool) config('media-processing.video_quality.enforce_public_visibility', true);
        $this->hideNeedsReviewVideo = (bool) config('media-processing.video_quality.hide_needs_review', false);
    }

    public function childrensTalksArePublic(): bool
    {
        if (app()->environment('testing')) {
            return (bool) config('sermons.childrens_talks.public', false);
        }

        return $this->childrensTalksArePublic;
    }

    public function canAccessChildrensCorner(?Authenticatable $user): bool
    {
        // This matches the current members-area policy: any authenticated account
        // may access non-public Children's Corner content.
        return $this->childrensTalksArePublic() || $user !== null;
    }

    public function isChildrensTalk(Sermon $sermon): bool
    {
        return $sermon->content_type === SermonContentType::ChildrensTalk;
    }

    public function shouldRedirectGenericSermonRoute(Sermon $sermon): bool
    {
        return $sermon->content_type === SermonContentType::ChildrensTalk
            && $this->childrensTalksArePublic();
    }

    public function shouldExposeOnSermonApi(Sermon $sermon): bool
    {
        return $sermon->content_type === SermonContentType::Sermon;
    }

    public function shouldExposeVideo(Sermon $sermon): bool
    {
        if (! $sermon->hasVideo()) {
            return false;
        }

        return match ($sermon->videoVisibilityOverride()) {
            SermonVideoVisibilityOverride::ForceHide => false,
            SermonVideoVisibilityOverride::ForceShow => true,
            SermonVideoVisibilityOverride::Default => $this->automaticVideoVisibility($sermon),
        };
    }

    public function shouldExposeVideoThumbnail(Sermon $sermon): bool
    {
        return $this->shouldExposeThumbnail($sermon);
    }

    public function shouldExposeThumbnail(Sermon $sermon): bool
    {
        if (! $sermon->hasVideo()) {
            return true;
        }

        if (! $sermon->hasVideoGeneratedThumbnail()) {
            return true;
        }

        return $this->shouldExposeVideo($sermon);
    }

    public function shouldGenerateVideoThumbnail(Sermon $sermon): bool
    {
        return $this->shouldExposeVideo($sermon);
    }

    public function shouldIncludeInSitemap(Sermon $sermon): bool
    {
        if ($sermon->content_type === SermonContentType::Sermon) {
            return true;
        }

        return $this->childrensTalksArePublic();
    }

    public function publicRouteName(Sermon $sermon): string
    {
        return $sermon->content_type === SermonContentType::ChildrensTalk
            ? 'childrens-corner.show'
            : 'sermons.show';
    }

    /**
     * @return array<string, string>
     */
    public function publicRouteParameters(Sermon $sermon): array
    {
        return ['sermon' => $sermon->slug];
    }

    public function publicUrl(Sermon $sermon): string
    {
        if (! filled($sermon->slug)) {
            return '';
        }

        return route($this->publicRouteName($sermon), $this->publicRouteParameters($sermon));
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        if ($sermon->content_type === SermonContentType::ChildrensTalk) {
            return $this->publicUrl($sermon);
        }

        $year = $sermon->date->format('Y');
        $month = $sermon->date->format('m');

        return url("/christ/sermons/{$year}/{$month}/{$sermon->slug}");
    }

    private function automaticVideoVisibility(Sermon $sermon): bool
    {
        $enforceVideoQuality = app()->environment('testing')
            ? (bool) config('media-processing.video_quality.enforce_public_visibility', true)
            : $this->enforceVideoQuality;

        if (! $enforceVideoQuality) {
            return true;
        }

        $hideNeedsReviewVideo = app()->environment('testing')
            ? (bool) config('media-processing.video_quality.hide_needs_review', false)
            : $this->hideNeedsReviewVideo;

        return match ($sermon->videoQualityStatus()) {
            SermonVideoQualityStatus::Approved => true,
            SermonVideoQualityStatus::Rejected => false,
            SermonVideoQualityStatus::NeedsReview => ! $hideNeedsReviewVideo,
            SermonVideoQualityStatus::Unassessed => true,
        };
    }
}
