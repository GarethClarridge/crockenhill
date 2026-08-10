<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\SermonContentType;
use App\Enums\SermonPublicationState;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Exceptions\MissingExposureAttribute;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Centralised authority for sermon visibility, routing, and exposure rules.
 *
 * This policy governs how different content types (Sermons vs Children's Talks)
 * are exposed to the public API, sitemaps, and search engines. It also enforces
 * video quality standards and handles members-only content boundaries.
 */
class SermonExposurePolicy
{
    /**
     * The sermon attributes this policy's exposure rules consult.
     *
     * SermonObserver evicts warm public listing/feed caches when any of these
     * change; a new exposure input added to this policy must be added here so
     * flipping it evicts immediately instead of waiting out the stale window.
     *
     * @var list<string>
     */
    public const EXPOSURE_ATTRIBUTES = [
        'content_type',
        'publication_state',
        'video_visibility_override',
        'video_quality_status',
    ];

    /**
     * Determine if Children's Talks should be visible to the general public.
     *
     * When false (default), these talks are restricted to authenticated members
     * with verified emails, protecting content intended for the church family.
     */
    public function childrensTalksArePublic(): bool
    {
        return (bool) config('church.sermons.childrens_talks.public', false);
    }

    /**
     * Check if a user is permitted to access the Children's Corner area.
     *
     * Non-public content is guarded by the same verified-email requirement
     * that defines the site's members-area boundary.
     *
     * @param  Authenticatable|null  $user  The user to verify
     */
    public function canAccessChildrensCorner(?Authenticatable $user): bool
    {
        // Non-public Children's Corner content requires authenticated + verified email,
        // consistent with the members-area boundary.
        return $this->childrensTalksArePublic() || ($user instanceof User && $user->hasVerifiedEmail());
    }

    /**
     * Determine if a given sermon is classified as a Children's Talk.
     */
    public function isChildrensTalk(Sermon $sermon): bool
    {
        return $sermon->content_type === SermonContentType::ChildrensTalk;
    }

    /**
     * The whole-content publication decision, consulted by every read surface.
     *
     * A column-restricted `select()` that omits `publication_state` leaves the
     * attribute unloaded, which would silently read as "not published" and
     * withhold content that is in fact public — the failure would look like a
     * working fail-closed gate. A persisted row missing the column is therefore
     * a programming error rather than a quarantine decision.
     */
    public function isWholeContentPublic(Sermon $sermon): bool
    {
        if ($sermon->exists && ! array_key_exists('publication_state', $sermon->getAttributes())) {
            throw new MissingExposureAttribute(
                'Sermon publication_state was not loaded; add it to the query select before consulting exposure.',
            );
        }

        return $sermon->publication_state === SermonPublicationState::Published;
    }

    /**
     * Determine if a request for a generic sermon route should be redirected.
     *
     * When Children's Talks are public, they have their own dedicated routing
     * (e.g., childrens-corner.show) and should not be accessed via the standard
     * sermon archive routes to maintain clear content separation.
     */
    public function shouldRedirectGenericSermonRoute(Sermon $sermon): bool
    {
        return $this->isWholeContentPublic($sermon)
            && $sermon->content_type === SermonContentType::ChildrensTalk
            && $this->childrensTalksArePublic();
    }

    /**
     * Determine if a sermon should be included in the public-facing API.
     *
     * Currently restricted to primary sermons to keep the API focused
     * on the main teaching archive.
     */
    public function shouldExposeOnSermonApi(Sermon $sermon): bool
    {
        return $this->isWholeContentPublic($sermon)
            && $sermon->content_type === SermonContentType::Sermon;
    }

    /**
     * Determine whether a publication attached to a public service may be shown.
     */
    public function shouldExposeOnChurchService(Sermon $sermon): bool
    {
        return $this->isWholeContentPublic($sermon)
            && $this->exposesContentTypeOnChurchService($sermon->content_type);
    }

    /**
     * The content-type half of {@see shouldExposeOnChurchService()}, so the public
     * service archive can push the same rule into SQL without loading sermons.
     */
    public function exposesContentTypeOnChurchService(SermonContentType $contentType): bool
    {
        return match ($contentType) {
            SermonContentType::Sermon => true,
            SermonContentType::ChildrensTalk => $this->childrensTalksArePublic(),
        };
    }

    /**
     * Determine if a sermon's video should be visible to the public.
     *
     * Evaluates manual visibility overrides before falling back to automated
     * quality-score enforcement. This ensures that only high-quality video
     * is exposed by default.
     */
    public function shouldExposeVideo(Sermon $sermon): bool
    {
        if (! $this->isWholeContentPublic($sermon) || ! $sermon->hasVideo()) {
            return false;
        }

        return match ($sermon->videoVisibilityOverride()) {
            SermonVideoVisibilityOverride::ForceHide => false,
            SermonVideoVisibilityOverride::ForceShow => true,
            SermonVideoVisibilityOverride::Default => $this->automaticVideoVisibility($sermon),
        };
    }

    /**
     * Determine if the video thumbnail should be visible.
     *
     * Aligned with video exposure to prevent "ghost" thumbnails for
     * rejected or hidden videos.
     */
    public function shouldExposeVideoThumbnail(Sermon $sermon): bool
    {
        return $this->shouldExposeThumbnail($sermon);
    }

    /**
     * Determine if a sermon thumbnail should be visible.
     *
     * If a video is present, the thumbnail visibility follows the video
     * visibility rules.
     */
    public function shouldExposeThumbnail(Sermon $sermon): bool
    {
        if (! $this->isWholeContentPublic($sermon)) {
            return false;
        }

        if (! $sermon->hasVideo()) {
            return true;
        }

        if (! $sermon->hasVideoGeneratedThumbnail()) {
            return true;
        }

        return $this->shouldExposeVideo($sermon);
    }

    /**
     * Determine if a video thumbnail should be generated for this sermon.
     */
    public function shouldGenerateVideoThumbnail(Sermon $sermon): bool
    {
        return $this->shouldExposeVideo($sermon);
    }

    /**
     * Determine if a sermon should be indexed by search engines via the sitemap.
     */
    public function shouldIncludeInSitemap(Sermon $sermon): bool
    {
        if (! $this->isWholeContentPublic($sermon)) {
            return false;
        }

        if ($sermon->content_type === SermonContentType::Sermon) {
            return true;
        }

        return $this->childrensTalksArePublic();
    }

    /**
     * Resolve the canonical public route name for a sermon.
     */
    public function publicRouteName(Sermon $sermon): string
    {
        return $sermon->content_type === SermonContentType::ChildrensTalk
            ? 'childrens-corner.show'
            : 'sermons.show';
    }

    /**
     * Generate the parameters required for the sermon's public route.
     *
     * @return array{sermon: string}
     */
    public function publicRouteParameters(Sermon $sermon): array
    {
        return ['sermon' => $sermon->slug];
    }

    /**
     * Generate the absolute public URL for a sermon.
     *
     * Returns an empty string if the sermon lacks a slug, preventing
     * RouteGenerationExceptions on incomplete records.
     */
    public function publicUrl(Sermon $sermon): string
    {
        if (! $this->isWholeContentPublic($sermon) || ! filled($sermon->slug)) {
            return '';
        }

        return route($this->publicRouteName($sermon), $this->publicRouteParameters($sermon));
    }

    /**
     * Generate the canonical, date-prefixed URL for a sermon.
     *
     * Favors the SEO-friendly YYYY/MM/slug format for primary sermons.
     */
    public function canonicalUrl(Sermon $sermon): string
    {
        if (! $this->isWholeContentPublic($sermon)) {
            return '';
        }

        if ($sermon->content_type === SermonContentType::ChildrensTalk) {
            return $this->publicUrl($sermon);
        }

        // The dated route is keyed on the slug; without one there is no canonical
        // page, so return empty rather than throwing UrlGenerationException — the
        // same contract publicUrl() already honours.
        if (! filled($sermon->slug)) {
            return '';
        }

        return route('sermons.show.dated', [
            'year' => $sermon->date->format('Y'),
            'month' => $sermon->date->format('m'),
            'sermon' => $sermon->slug,
        ]);
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
