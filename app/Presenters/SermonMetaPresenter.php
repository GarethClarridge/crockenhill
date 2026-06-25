<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Models\Sermon;
use App\Services\Sermon\SermonExposurePolicy;
use App\Support\SermonContentFormatter;

/**
 * Builds the SEO/meta surface for a sermon: the meta description and the image
 * alt-text variants.
 *
 * Like SermonPresentationAssembler, this collaborator reads already-resolved
 * facts (preacher name, human date, scripture reference, service label) back
 * through the presenter so the presenter remains the single memoization layer.
 * The meta description's own pure string assembly lives in SermonContentFormatter;
 * this class only gathers the inputs and applies the database-stored-description
 * short-circuit.
 */
class SermonMetaPresenter
{
    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
    ) {}

    /**
     * Get the descriptive alt text for a sermon thumbnail image.
     */
    public function imageAlt(SermonViewPresenter $presenter, Sermon $sermon): string
    {
        $preacherName = $presenter->displayPreacherName($sermon);

        return 'Sermon: '.$sermon->title.($preacherName ? ' by '.$preacherName : '');
    }

    /**
     * Get the descriptive alt text for a Children's Corner talk thumbnail image.
     */
    public function childrensTalkImageAlt(SermonViewPresenter $presenter, Sermon $sermon): string
    {
        $preacherName = $presenter->displayPreacherName($sermon);

        return "Children's Corner: ".$sermon->title.($preacherName ? ' by '.$preacherName : '');
    }

    /**
     * Generate the SEO meta description for a sermon.
     *
     * Prefers an explicit database-stored description; otherwise assembles one
     * from the sermon's resolved facts via SermonContentFormatter.
     */
    public function metaDescription(SermonViewPresenter $presenter, Sermon $sermon): string
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
            preacherName: $presenter->displayPreacherName($sermon) ?? 'Unknown preacher',
            humanDate: $presenter->humanDate($sermon),
            reference: $presenter->displayReference($sermon),
            series: filled($sermon->series) ? (string) $sermon->series : null,
            serviceLabel: $presenter->serviceLabel($sermon),
            hasVideo: $this->exposurePolicy->shouldExposeVideo($sermon),
            hasAudio: filled($sermon->audio_file_path),
            summary: $summary,
        );
    }
}
