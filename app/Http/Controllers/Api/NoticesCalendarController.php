<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Services\Calendar\CalendarService;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Upcoming meeting dates for the projector notices player (GarethClarridge/cbc-notices).
 *
 * The player runs from files on the projector PC, so it loads this with a plain
 * script tag rather than fetch(): a script needs no CORS, only a
 * Cross-Origin-Resource-Policy that lets another origin embed it. The response
 * is therefore JavaScript that assigns `window.CBC_CALENDAR`, not JSON.
 *
 * Times are the wall-clock times shown on the website (the calendar's own
 * Europe/London times), written without an offset.
 */
class NoticesCalendarController extends Controller
{
    private const int MONTHS_AHEAD = 6;

    public function __construct(
        private readonly CalendarService $calendarService,
    ) {}

    public function __invoke(): Response
    {
        $events = $this->calendarService->getCategorizedEventsBetween(
            now()->startOfDay(),
            now()->addMonths(self::MONTHS_AHEAD),
        );

        $payload = [
            'generated' => now()->toIso8601String(),
            // An object even when empty, so the player can always look a slug up.
            'meetings' => (object) $events
                ->groupBy('meeting_slug')
                ->map(fn (Collection $meetingEvents): array => $meetingEvents
                    ->map(fn (CalendarEvent $event): array => [
                        'start' => $event->start_datetime->format('Y-m-d\TH:i'),
                        'end' => $event->end_datetime->format('Y-m-d\TH:i'),
                    ])
                    ->values()
                    ->all())
                ->all(),
        ];

        $json = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES,
        );

        return response('window.CBC_CALENDAR = '.$json.";\n", 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=900',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
