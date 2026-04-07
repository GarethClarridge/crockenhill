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
    public function childrensTalksArePublic(): bool
    {
        return (bool) config('sermons.childrens_talks.public', false);
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
        if (! $sermon->hasVideo()) {
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
        if (! (bool) config('media-processing.video_quality.enforce_public_visibility', true)) {
            return true;
        }

        return match ($sermon->videoQualityStatus()) {
            SermonVideoQualityStatus::Approved => true,
            SermonVideoQualityStatus::Rejected => false,
            SermonVideoQualityStatus::NeedsReview => ! (bool) config('media-processing.video_quality.hide_needs_review', false),
            SermonVideoQualityStatus::Unassessed => true,
        };
    }
}
