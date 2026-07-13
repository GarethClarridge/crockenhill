<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dedicated query builder for the Sermon model.
 *
 * Holds every reusable Sermon query constraint so the model stays focused on
 * attributes, casts, and relationships. Attached via the #[UseEloquentBuilder]
 * attribute on the model, so `Sermon::query()` and static forwarding
 * (`Sermon::forPodcast()`) both return this builder.
 *
 * @extends Builder<Sermon>
 */
class SermonBuilder extends Builder
{
    public function last12Months(): self
    {
        return $this->where('date', '>=', now()->subMonths(12)->startOfDay());
    }

    public function forService(SermonService $serviceType): self
    {
        return $this->where('service', $serviceType);
    }

    public function inSeries(string $seriesTitle): self
    {
        return $this->where('series', $seriesTitle);
    }

    public function byPreacher(string $preacherName): self
    {
        return $this->where(function (Builder $builder) use ($preacherName): void {
            $builder->where('preacher', $preacherName)
                ->orWhereHas('preacherProfile', fn (Builder $preacherQuery): Builder => $preacherQuery->where('name', $preacherName));
        });
    }

    public function needsPreacherReview(): self
    {
        return $this->where('needs_preacher_review', true);
    }

    public function whereSermon(): self
    {
        return $this->where($this->qualifyColumn('content_type'), SermonContentType::Sermon);
    }

    public function whereChildrensTalk(): self
    {
        return $this->where($this->qualifyColumn('content_type'), SermonContentType::ChildrensTalk);
    }

    /**
     * Constrain to sermons visible in the public sitemap.
     */
    public function whereVisibleInSitemap(): self
    {
        // A sermon's canonical URL is keyed on its slug, so a record without one
        // has no public page to point to and must never reach URL generation.
        $this->whereNotNull($this->qualifyColumn('slug'))
            ->where($this->qualifyColumn('slug'), '!=', '');

        if ((bool) config('church.sermons.childrens_talks.public', false)) {
            return $this;
        }

        return $this->whereSermon();
    }

    /**
     * Constrain to sermons created through automated processing.
     */
    public function automated(): self
    {
        return $this->where(function (Builder $q): void {
            $q->whereNotNull('transcript_file_path')
                ->where('transcript_file_path', '!=', '')
                ->orWhereHas('processingLogs');
        });
    }

    /**
     * Constrain to manually created sermons.
     */
    public function manual(): self
    {
        return $this->where(function (Builder $q): void {
            $q->where(function (Builder $sub): void {
                $sub->whereNull('transcript_file_path')
                    ->orWhere('transcript_file_path', '=', '');
            })
                ->whereDoesntHave('processingLogs');
        });
    }

    public function processingCompleted(): self
    {
        return $this->whereHas('processingLogs', function (Builder $q): void {
            $q->where('status', ProcessingStatus::Completed);
        });
    }

    public function processingFailed(): self
    {
        return $this->whereHas('processingLogs', function (Builder $q): void {
            $q->where('status', ProcessingStatus::Failed);
        });
    }

    public function processingInProgress(): self
    {
        return $this->whereHas('processingLogs', function (Builder $q): void {
            $q->where('status', ProcessingStatus::Processing);
        });
    }

    public function fromLivestream(): self
    {
        return $this->where('source_type', SermonSourceType::Livestream);
    }

    public function withVideo(): self
    {
        return $this->whereNotNull('video_file_path');
    }

    public function bySourceType(SermonSourceType $sourceType): self
    {
        return $this->where('source_type', $sourceType);
    }

    public function withThumbnail(): self
    {
        return $this->whereNotNull('thumbnail_file_path');
    }

    public function orderByPreacherName(string $direction = 'asc'): self
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        return $this
            ->orderBy(
                Preacher::query()
                    ->select('name')
                    ->whereColumn('preachers.id', 'sermons.preacher_id')
                    ->limit(1),
                $direction
            )
            ->orderBy('preacher', $direction);
    }

    /**
     * Constrain to podcast-ready sermons (must have an audio file), newest first.
     */
    public function forPodcast(): self
    {
        return $this->whereNotNull('audio_file_path')
            ->where('audio_file_path', '!=', '')
            ->orderBy('date', 'desc');
    }
}
