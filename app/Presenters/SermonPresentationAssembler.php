<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;

/**
 * Packs already-resolved sermon facts into the view/API output shapes.
 *
 * SermonViewPresenter resolves and memoizes individual facts (URLs, formatted
 * dates, preacher name, scripture reference, …); this collaborator owns only the
 * orthogonal concern of assembling those facts into the three array shapes the
 * application consumes. It reads every value back through the presenter, so the
 * presenter remains the single source of truth (and single memoization layer)
 * for each field — the assembler adds no caching of its own.
 *
 * Keeping the shapes here means each `array{...}` is declared once, rather than
 * being restated at every memoized return site inside the presenter.
 */
class SermonPresentationAssembler
{
    /**
     * The lightweight field subset used by API resources.
     *
     * @return array{
     *     audio_url: ?string,
     *     display_reference: ?string,
     *     duration_iso8601: ?string,
     *     formatted_duration: ?string,
     *     human_date: string,
     *     preacher_image_url: ?string,
     *     preacher_name: ?string,
     *     preacher_url: ?string,
     *     series_url: ?string,
     *     thumbnail_url: ?string,
     *     video_url: ?string
     * }
     */
    public function forApi(SermonViewPresenter $presenter, Sermon $sermon): array
    {
        return [
            'audio_url' => $presenter->audioUrl($sermon),
            'display_reference' => $presenter->displayReference($sermon),
            'duration_iso8601' => $presenter->durationIso8601($sermon),
            'formatted_duration' => $presenter->formattedDuration($sermon),
            'human_date' => $presenter->humanDate($sermon),
            'preacher_image_url' => $presenter->preacherImageUrl($sermon),
            'preacher_name' => $presenter->displayPreacherName($sermon),
            'preacher_url' => $presenter->preacherUrl($sermon),
            'series_url' => $presenter->seriesUrl($sermon),
            'thumbnail_url' => $presenter->thumbnailUrl($sermon),
            'video_url' => $presenter->videoUrl($sermon),
        ];
    }

    /**
     * The full field set used by sermon listings.
     *
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     card_thumbnail_url: ?string,
     *     date_iso: string,
     *     date_string: string,
     *     display_reference: ?string,
     *     duration_iso8601: ?string,
     *     formatted_duration: ?string,
     *     has_transcript: bool,
     *     human_date: string,
     *     plain_thumbnail_url: ?string,
     *     preacher_image_url: ?string,
     *     preacher_name: ?string,
     *     preacher_url: ?string,
     *     public_url: string,
     *     series_url: ?string,
     *     service_label: ?string,
     *     thumbnail_url: ?string,
     *     transcript_url: ?string,
     *     video_url: ?string
     * }
     */
    public function forList(SermonViewPresenter $presenter, Sermon $sermon): array
    {
        $hasTranscript = $sermon->hasTranscript();
        $dates = $presenter->formattedDates($sermon);

        return [
            'audio_url' => $presenter->audioUrl($sermon),
            'canonical_url' => $presenter->canonicalUrl($sermon),
            'card_thumbnail_url' => $presenter->cardThumbnailUrl($sermon),
            'date_iso' => $dates['iso'],
            'date_string' => $dates['short'],
            'display_reference' => $presenter->displayReference($sermon),
            'duration_iso8601' => $presenter->durationIso8601($sermon),
            'formatted_duration' => $presenter->formattedDuration($sermon),
            'has_transcript' => $hasTranscript,
            'human_date' => $dates['human'],
            'plain_thumbnail_url' => $presenter->plainThumbnailUrl($sermon),
            'preacher_image_url' => $presenter->preacherImageUrl($sermon),
            'preacher_name' => $presenter->displayPreacherName($sermon),
            'preacher_url' => $presenter->preacherUrl($sermon),
            'public_url' => $presenter->publicUrl($sermon),
            'series_url' => $presenter->seriesUrl($sermon),
            'service_label' => $presenter->serviceLabel($sermon),
            'thumbnail_url' => $presenter->thumbnailUrl($sermon),
            'transcript_url' => $hasTranscript ? route('sermons.transcript', ['sermon' => $sermon->slug]) : null,
            'video_url' => $presenter->videoUrl($sermon),
        ];
    }

    /**
     * The full field set plus the transcript and plain-text outline used by the
     * single-sermon view.
     *
     * @return array{
     *     audio_url: ?string,
     *     canonical_url: string,
     *     card_thumbnail_url: ?string,
     *     date_iso: string,
     *     date_string: string,
     *     display_reference: ?string,
     *     duration_iso8601: ?string,
     *     formatted_duration: ?string,
     *     has_transcript: bool,
     *     human_date: string,
     *     plain_thumbnail_url: ?string,
     *     preacher_image_url: ?string,
     *     preacher_name: ?string,
     *     preacher_url: ?string,
     *     public_url: string,
     *     series_url: ?string,
     *     service_label: ?string,
     *     thumbnail_url: ?string,
     *     transcript: ?string,
     *     transcript_url: ?string,
     *     plain_text_outline: ?string,
     *     video_url: ?string
     * }
     */
    public function forFull(SermonViewPresenter $presenter, Sermon $sermon): array
    {
        return array_merge(
            $presenter->presentForList($sermon),
            [
                'transcript' => $presenter->transcript($sermon),
                'plain_text_outline' => $presenter->plainTextOutline($sermon),
            ]
        );
    }
}
