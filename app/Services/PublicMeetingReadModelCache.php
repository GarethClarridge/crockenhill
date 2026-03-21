<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\PublicMeetingReadModel;
use App\Models\CalendarEvent;
use App\Models\Meeting;
use App\Presenters\MeetingShowPresenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PublicMeetingReadModelCache
{
    public function __construct(
        private readonly MeetingShowPresenter $meetingShowPresenter,
        private readonly PublicPageReadModelCache $publicPageReadModelCache,
    ) {}

    public function get(Meeting $meeting): PublicMeetingReadModel
    {
        /** @var PublicMeetingReadModel */
        return Cache::rememberForever($this->cacheKey($meeting->id), function () use ($meeting): PublicMeetingReadModel {
            $meeting->loadMissing(['page.media', 'media']);

            $pageReadModel = $meeting->page === null
                ? null
                : $this->publicPageReadModelCache->get($meeting->page);

            $heading = $pageReadModel === null
                ? Str::title(str_replace('-', ' ', $meeting->slug))
                : $pageReadModel->heading;

            return new PublicMeetingReadModel(
                area: 'community',
                content: $pageReadModel === null ? '' : $pageReadModel->content,
                description: $pageReadModel === null ? $heading : $pageReadModel->description,
                heading: $heading,
                headingpicture: $pageReadModel?->headingpicture,
                headingpictureMobile: $pageReadModel?->headingpictureMobile,
                headingpictureTablet: $pageReadModel?->headingpictureTablet,
                metaDescription: $pageReadModel === null ? $heading : $pageReadModel->metaDescription,
                pageDescription: $meeting->page?->description,
                photos: $this->meetingShowPresenter->photos($meeting),
                pastEvents: $this->pastEvents($meeting),
                slug: $meeting->slug,
                upcomingEvents: $this->upcomingEvents($meeting),
            );
        });
    }

    public function forget(Meeting|int $meeting): void
    {
        $meetingId = $meeting instanceof Meeting ? $meeting->id : $meeting;

        Cache::forget($this->cacheKey($meetingId));
    }

    public function forgetBySlug(?string $meetingSlug): void
    {
        if (! is_string($meetingSlug) || $meetingSlug === '') {
            return;
        }

        $meetingId = Meeting::query()
            ->where('slug', $meetingSlug)
            ->value('id');

        if ($meetingId !== null) {
            $this->forget((int) $meetingId);
        }
    }

    private function cacheKey(int $meetingId): string
    {
        return "public_meeting_view_{$meetingId}";
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, CalendarEvent>
     */
    private function pastEvents(Meeting $meeting): \Illuminate\Database\Eloquent\Collection
    {
        return CalendarEvent::query()
            ->select(['id', 'title', 'speaker', 'start_datetime'])
            ->where('meeting_slug', $meeting->slug)
            ->past()
            ->confirmed()
            ->orderBy('start_datetime', 'desc')
            ->limit(3)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, CalendarEvent>
     */
    private function upcomingEvents(Meeting $meeting): \Illuminate\Database\Eloquent\Collection
    {
        return CalendarEvent::query()
            ->select(['id', 'meeting_slug', 'title', 'description', 'speaker', 'location', 'start_datetime', 'end_datetime'])
            ->where('meeting_slug', $meeting->slug)
            ->upcoming()
            ->confirmed()
            ->orderBy('start_datetime')
            ->limit(6)
            ->get();
    }
}
