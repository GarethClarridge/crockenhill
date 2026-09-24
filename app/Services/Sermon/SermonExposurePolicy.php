<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\SermonPublicationState;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Enums\TalkType;
use App\Exceptions\MissingExposureAttribute;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Centralised authority for sermon visibility, routing, and exposure rules.
 *
 * This policy governs how each talk type (sermons, children's talks, testimonies…)
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
     * Determine if talks of a given type are visible to the general public.
     *
     * Sermons are always public; every other type is members-only unless it is
     * listed in `church.talks.public_types`, protecting content intended for the
     * church family.
     */
    public function isTypePublic(TalkType $type): bool
    {
        return $type->isSermon()
            || in_array($type->value, array_map(trim(...), (array) config('church.talks.public_types', [])), true);
    }

    /**
     * Check if a user is permitted to see talks of a given type.
     *
     * Non-public types are guarded by the same verified-email requirement that
     * defines the site's members-area boundary.
     */
    public function canAccessType(TalkType $type, ?Authenticatable $user): bool
    {
        return $this->isTypePublic($type) || ($user instanceof User && $user->hasVerifiedEmail());
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
     * Determine if a sermon should be included in the public-facing API.
     *
     * Currently restricted to primary sermons to keep the API focused
     * on the main teaching archive.
     */
    public function shouldExposeOnSermonApi(Sermon $sermon): bool
    {
        return $this->isWholeContentPublic($sermon)
            && $sermon->content_type === TalkType::Sermon;
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
    public function exposesContentTypeOnChurchService(TalkType $contentType): bool
    {
        return $this->isTypePublic($contentType);
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

        return $this->isTypePublic($sermon->content_type);
    }

    /**
     * Generate the absolute public URL for a talk: its dated page, whatever its type.
     */
    public function publicUrl(Sermon $sermon): string
    {
        return $this->canonicalUrl($sermon);
    }

    /**
     * Generate the canonical, date-prefixed URL for a talk.
     *
     * Returns an empty string for unpublished talks and for records without a
     * slug, preventing RouteGenerationExceptions on incomplete records.
     */
    public function canonicalUrl(Sermon $sermon): string
    {
        if (! $this->isWholeContentPublic($sermon) || ! filled($sermon->slug)) {
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
