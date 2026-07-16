<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
use App\Support\SermonContentFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SermonViewPresenter
{
    private readonly SermonUrlBuilder $urlBuilder;

    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
        SermonStorageService $storageService,
        private readonly SermonTranscriptReader $transcriptReader,
        private readonly SermonDateFormatter $dateFormatter = new SermonDateFormatter,
        ?SermonUrlBuilder $urlBuilder = null,
    ) {
        $this->urlBuilder = $urlBuilder ?? new SermonUrlBuilder($storageService, $exposurePolicy);
    }

    public function formattedDuration(Sermon $sermon): ?string
    {
        return $this->dateFormatter->formattedDuration($sermon);
    }

    public function durationIso8601(Sermon $sermon): ?string
    {
        return $this->dateFormatter->durationIso8601($sermon);
    }

    /** @return array{human: string, iso: string, short: string} */
    public function formattedDates(Sermon $sermon): array
    {
        return $this->dateFormatter->formattedDates($sermon);
    }

    public function humanDate(Sermon $sermon): string
    {
        return $this->dateFormatter->humanDate($sermon);
    }

    public function audioUrl(Sermon $sermon): ?string
    {
        return $this->urlBuilder->audioUrl($sermon);
    }

    public function plainTextOutline(Sermon $sermon): ?string
    {
        return SermonContentFormatter::plainTextOutline($sermon->points);
    }

    public function preacherImageUrl(Sermon $sermon): ?string
    {
        if (! $sermon->relationLoaded('preacherProfile')) {
            return null;
        }

        return $sermon->preacherProfile?->profile_image_url;
    }

    public function canonicalUrl(Sermon $sermon): string
    {
        return $this->urlBuilder->canonicalUrl($sermon);
    }

    public function cardThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->urlBuilder->cardThumbnailUrl($sermon);
    }

    public function plainThumbnailUrl(Sermon $sermon): ?string
    {
        return $this->urlBuilder->plainThumbnailUrl($sermon);
    }

    public function preacherUrl(Sermon $sermon): ?string
    {
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            return route('sermons.preacher', ['preacher' => $sermon->preacherProfile->slug]);
        }

        $preacherName = $this->displayPreacherName($sermon);

        return filled($preacherName)
            ? route('sermons.preacher', ['preacher' => Str::slug($preacherName)])
            : null;
    }

    public function serviceLabel(Sermon $sermon): ?string
    {
        return $sermon->service?->label();
    }

    public function seriesUrl(Sermon $sermon): ?string
    {
        if (! $sermon->series) {
            return null;
        }

        return route('sermons.series.show', ['series' => Str::slug($sermon->series)]);
    }

    /**
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
    public function presentForApi(Sermon $sermon): array
    {
        return [
            'audio_url' => $this->audioUrl($sermon),
            'display_reference' => $this->displayReference($sermon),
            'duration_iso8601' => $this->durationIso8601($sermon),
            'formatted_duration' => $this->formattedDuration($sermon),
            'human_date' => $this->humanDate($sermon),
            'preacher_image_url' => $this->preacherImageUrl($sermon),
            'preacher_name' => $this->displayPreacherName($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'series_url' => $this->seriesUrl($sermon),
            'thumbnail_url' => $this->thumbnailUrl($sermon),
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    /**
     * @param  Collection<int, Sermon>  $sermons
     * @return array<int, array<string, mixed>>
     */
    public function presentCollection(Collection $sermons): array
    {
        return $sermons
            ->keyBy('id')
            ->map(fn (Sermon $sermon): array => $this->presentForList($sermon))
            ->all();
    }

    /**
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
    public function presentForList(Sermon $sermon): array
    {
        $dates = $this->formattedDates($sermon);

        return [
            'audio_url' => $this->audioUrl($sermon),
            'canonical_url' => $this->canonicalUrl($sermon),
            'card_thumbnail_url' => $this->cardThumbnailUrl($sermon),
            'date_iso' => $dates['iso'],
            'date_string' => $dates['short'],
            'display_reference' => $this->displayReference($sermon),
            'duration_iso8601' => $this->durationIso8601($sermon),
            'formatted_duration' => $this->formattedDuration($sermon),
            'has_transcript' => $sermon->hasTranscript(),
            'human_date' => $dates['human'],
            'plain_thumbnail_url' => $this->plainThumbnailUrl($sermon),
            'preacher_image_url' => $this->preacherImageUrl($sermon),
            'preacher_name' => $this->displayPreacherName($sermon),
            'preacher_url' => $this->preacherUrl($sermon),
            'public_url' => $this->publicUrl($sermon),
            'series_url' => $this->seriesUrl($sermon),
            'service_label' => $this->serviceLabel($sermon),
            'thumbnail_url' => $this->thumbnailUrl($sermon),
            'transcript_url' => $this->transcriptUrl($sermon),
            'video_url' => $this->videoUrl($sermon),
        ];
    }

    /**
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
    public function present(Sermon $sermon): array
    {
        return array_merge(
            $this->presentForList($sermon),
            [
                'transcript' => $this->transcript($sermon),
                'plain_text_outline' => $this->plainTextOutline($sermon),
            ],
        );
    }

    public function publicUrl(Sermon $sermon): string
    {
        return $this->urlBuilder->publicUrl($sermon);
    }

    public function thumbnailUrl(Sermon $sermon): ?string
    {
        return $this->urlBuilder->thumbnailUrl($sermon);
    }

    public function transcript(Sermon $sermon): ?string
    {
        return $this->transcriptReader->read($sermon);
    }

    public function transcriptUrl(Sermon $sermon): ?string
    {
        return $this->urlBuilder->transcriptUrl($sermon);
    }

    public function displayPreacherName(Sermon $sermon): ?string
    {
        if ($sermon->relationLoaded('preacherProfile') && $sermon->preacherProfile !== null) {
            return $sermon->preacherProfile->name ?: null;
        }

        $preacherName = trim((string) $sermon->preacher);

        return $preacherName === '' ? null : $preacherName;
    }

    public function displayReference(Sermon $sermon): ?string
    {
        if ($sermon->relationLoaded('scripturePassage') && $sermon->scripturePassage instanceof ScripturePassage) {
            $displayReference = $sermon->scripturePassage->display_reference
                ?: $sermon->scripturePassage->normalized_reference;

            if (filled($displayReference)) {
                return $displayReference;
            }
        }

        $reference = trim((string) $sermon->reference);

        return $reference === '' ? null : $reference;
    }

    public function imageAlt(Sermon $sermon): string
    {
        $preacherName = $this->displayPreacherName($sermon);

        return 'Sermon: '.$sermon->title.($preacherName ? ' by '.$preacherName : '');
    }

    public function childrensTalkImageAlt(Sermon $sermon): string
    {
        $preacherName = $this->displayPreacherName($sermon);

        return "Children's Corner: ".$sermon->title.($preacherName ? ' by '.$preacherName : '');
    }

    public function metaDescription(Sermon $sermon): string
    {
        $attributes = $sermon->getAttributes();

        if (filled($attributes['meta_description'] ?? null)) {
            return (string) $attributes['meta_description'];
        }

        $summary = ($sermon->show_summary && filled($sermon->summary))
            ? strip_tags((string) $sermon->summary)
            : null;

        return SermonContentFormatter::metaDescription(
            title: (string) $sermon->title,
            preacherName: $this->displayPreacherName($sermon) ?? 'Unknown preacher',
            humanDate: $this->humanDate($sermon),
            reference: $this->displayReference($sermon),
            series: filled($sermon->series) ? (string) $sermon->series : null,
            serviceLabel: $this->serviceLabel($sermon),
            hasVideo: $this->exposurePolicy->shouldExposeVideo($sermon),
            hasAudio: filled($sermon->audio_file_path),
            summary: $summary,
        );
    }

    public function videoUrl(Sermon $sermon): ?string
    {
        return $this->urlBuilder->videoUrl($sermon);
    }
}
