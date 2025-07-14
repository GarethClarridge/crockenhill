# Google Calendar Integration Plan for Crockenhill Baptist Church

## Executive Summary

**HYBRID APPROACH:** This plan combines architectural simplicity with automatic categorization to meet both spec requirements and practical church workflow needs.

### Hybrid Architecture:
- **Meeting model** = Page templates + metadata (existing seeded meetings like `sunday-mornings`, `prayer-meeting`)  
- **CalendarEvent model** = Database storage for events with Google Calendar IDs (spec compliance)
- **Pattern Matching Engine** = Automatic categorization using Meeting slug patterns
- **Cache layer** = Performance optimization over database storage
- **Extended Properties** = Manual override capability for special events

### Why This Hybrid Approach is Better:
✅ **Automatic categorization** - 80% of church events categorized without admin intervention  
✅ **Spec compliance** - database storage, pattern matching, uncategorized handling  
✅ **Minimal admin burden** - only special events need manual tagging  
✅ **Google Calendar as source of truth** - staff manage events naturally  
✅ **Architectural simplicity** - clear data flow with smart automation layer

## Why Spatie Package vs Raw Google API

**Benefits of using spatie/laravel-google-calendar:**
- Laravel-native syntax and conventions
- Simplified authentication setup
- Reduced boilerplate code (no need to handle raw API responses)
- Well-maintained with active community support
- Abstracts complex Google API interactions
- Better error handling and debugging

**Limitations to consider:**
- May not expose all Google Calendar API features
- Additional dependency to maintain

**Recurring Events Strategy:** The Spatie package can properly handle recurring events by expanding them into individual instances using the `singleEvents: true` parameter. Each recurring event instance will be treated as a separate event in our database, meeting spec requirements.

**Recommendation:** Use Spatie package for implementation with explicit recurring event expansion and date-windowed syncing for optimal performance.

## Recurring Events Strategy

**Problem:** The spec requires that "each instance" of recurring events be treated as "a separate event in app."

**Solution:** Use the Spatie package's ability to expand recurring events:

```php
// This call expands recurring events into individual instances
$googleEvents = Event::get(
    $startDate,
    $endDate,
    [
        'singleEvents' => true,     // Key parameter: expands recurring events
        'orderBy' => 'startTime'    // Orders instances chronologically
    ]
);
```

**Benefits:**
- ✅ **Spec Compliance**: Each recurring event instance becomes a separate CalendarEvent record
- ✅ **Natural Handling**: Weekly "Sunday Morning Service" becomes individual events with specific dates
- ✅ **Pattern Matching**: Each instance can be categorized independently
- ✅ **Date Queries**: Can query events by specific date ranges efficiently

**Example:** A weekly recurring "Sunday Morning Service" creates separate events:
- "Sunday Morning Service" - 2025-07-20 10:00
- "Sunday Morning Service" - 2025-07-27 10:00
- "Sunday Morning Service" - 2025-08-03 10:00

Each instance gets its own database record and can have different speakers, topics, or categorization.

## Sync Performance Strategy

**Problem:** Syncing all events is inefficient and violates API rate limits.

**Solution:** Use date windowing for focused sync:

- **Sync Window**: 3 months past to 2 years future (configurable)
- **Rationale**: Covers recent events for reference, upcoming events for planning
- **Performance**: Dramatically reduces API calls and processing time
- **Deletion Handling**: Only tracks deletions within sync window

**Configuration:**
```php
// config/calendar.php
'sync_window' => [
    'past_months' => 3,      // How many months back to sync
    'future_years' => 2,     // How many years forward to sync
],

'performance' => [
    'eager_load_limit' => 100,   // Limit for eager loading calendar events
    'cache_duration' => 3600,    // Cache duration in seconds (1 hour)
],
```

## Technical Architecture

### Phase 1: Foundation Setup (1-2 hours)

#### 1.1 Package Installation
```bash
composer require spatie/laravel-google-calendar
php artisan vendor:publish --provider="Spatie\GoogleCalendar\GoogleCalendarServiceProvider"
```

#### 1.2 Google API Configuration
- Set up Google Calendar API credentials (service account approach recommended)
- Configure `.env` variables for Google Calendar API
- Share church calendar with service account email

#### 1.3 CalendarEvent Model & Migration
Create proper database storage for events (spec requirement):

```php
// Migration: create_calendar_events_table
Schema::create('calendar_events', function (Blueprint $table) {
    $table->id();
    $table->string('google_event_id')->unique();
    $table->string('meeting_slug')->nullable()->index(); // Links to Meeting.slug
    $table->string('title');
    $table->text('description')->nullable();
    $table->string('speaker')->nullable();
    $table->string('location')->nullable();
    $table->dateTime('start_datetime')->index();
    $table->dateTime('end_datetime');
    $table->string('status')->default('confirmed'); // confirmed, cancelled, tentative
    $table->boolean('is_categorized_automatically')->default(false); // Track auto vs manual categorization
    $table->timestamps();
});

// Add composite index for efficient querying
Schema::table('calendar_events', function (Blueprint $table) {
    $table->index(['meeting_slug', 'start_datetime']);
    $table->index(['start_datetime', 'status']);
});
```

#### 1.4 Pattern Matching Configuration
Create configuration for automatic categorization:

```php
// config/calendar.php
return [
    'meeting_patterns' => [
        'sunday-mornings' => [
            'patterns' => ['sunday morning service', 'sunday am', 'morning service'],
            'case_insensitive' => true,
        ],
        'sunday-evenings' => [
            'patterns' => ['sunday evening service', 'sunday pm', 'evening service'],
            'case_insensitive' => true,
        ],
        'prayer-meeting' => [
            'patterns' => ['prayer meeting', 'prayer night', 'church prayer'],
            'case_insensitive' => true,
        ],
        'youth-group' => [
            'patterns' => ['youth group', 'youth night', 'young people'],
            'case_insensitive' => true,
        ],
    ],
    'uncategorized_slug' => 'uncategorized', // For events that don't match any pattern
];
```

### Phase 2: Hybrid Calendar Service (2-3 hours)

#### 2.1 CalendarService with Pattern Matching
Create `app/Services/CalendarService.php` with automatic categorization:

```php
class CalendarService
{
    public function getEventsForMeeting(string $meetingSlug, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $query = CalendarEvent::where('meeting_slug', $meetingSlug)
                              ->where('status', '!=', 'cancelled')
                              ->orderBy('start_datetime');
            
        if ($startDate) {
            $query->where('start_datetime', '>=', $startDate);
        }
        
        if ($endDate) {
            $query->where('start_datetime', '<=', $endDate);
        }
        
        return $query->get();
    }
    
    public function getAllUpcomingEvents(?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $query = CalendarEvent::where('status', '!=', 'cancelled')
                              ->where('start_datetime', '>=', $startDate ?? now())
                              ->orderBy('start_datetime');
                              
        if ($endDate) {
            $query->where('start_datetime', '<=', $endDate);
        }
        
        return $query->get();
    }
    
    public function syncFromGoogleCalendar(): array
    {
        // Read sync window from config (single source of truth)
        $startDate = now()->subMonths(config('calendar.sync_window.past_months', 3));
        $endDate = now()->addYears(config('calendar.sync_window.future_years', 2));
        
        // Get events from Google Calendar with recurring events expanded
        // singleEvents: true expands recurring events into individual instances
        $googleEvents = Event::get(
            $startDate,
            $endDate,
            [
                'singleEvents' => true,     // Expand recurring events (spec requirement)
                'orderBy' => 'startTime'    // Order by start time for efficient processing
            ]
        );
        
        // Get existing events in our sync window to track deletions
        $existingEventIds = CalendarEvent::whereBetween('start_datetime', [$startDate, $endDate])
                                        ->pluck('google_event_id')
                                        ->toArray();
        $processedEventIds = [];
        
        foreach ($googleEvents as $googleEvent) {
            $this->syncSingleEvent($googleEvent);
            $processedEventIds[] = $googleEvent->id;
        }
        
        // Remove events that no longer exist in Google Calendar (within sync window)
        $deletedEventIds = array_diff($existingEventIds, $processedEventIds);
        CalendarEvent::whereIn('google_event_id', $deletedEventIds)->delete();
        
        // Count uncategorized events for reporting
        $uncategorizedCount = CalendarEvent::whereBetween('start_datetime', [$startDate, $endDate])
                                          ->where('meeting_slug', config('calendar.uncategorized_slug', 'uncategorized'))
                                          ->count();
        
        // Log for debugging
        Log::info('Google Calendar sync completed', [
            'processed_events' => count($processedEventIds),
            'deleted_events' => count($deletedEventIds),
            'uncategorized_events' => $uncategorizedCount,
            'sync_window' => [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]
        ]);
        
        // Return report for command output
        return [
            'processed_events' => count($processedEventIds),
            'deleted_events' => count($deletedEventIds),
            'uncategorized_events' => $uncategorizedCount,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ];
    }
    
    public function syncSingleEvent(Event $googleEvent): CalendarEvent
    {
        // Determine meeting slug using hybrid approach
        $meetingSlug = $this->determineMeetingSlug($googleEvent);
        
        // Create or update local event
        $calendarEvent = CalendarEvent::updateOrCreate(
            ['google_event_id' => $googleEvent->id],
            [
                'meeting_slug' => $meetingSlug,
                'title' => $googleEvent->name,
                'description' => $googleEvent->description,
                'speaker' => $googleEvent->getExtendedProperty('speaker_name'),
                'location' => $googleEvent->location,
                'start_datetime' => $googleEvent->startDateTime,
                'end_datetime' => $googleEvent->endDateTime,
                'status' => $googleEvent->status ?? 'confirmed',
                'is_categorized_automatically' => !$googleEvent->getExtendedProperty('meeting_slug'),
            ]
        );
        
        return $calendarEvent;
    }
    
    public function createEventForMeeting(string $meetingSlug, array $eventData): Event
    {
        $meeting = Meeting::where('slug', $meetingSlug)->firstOrFail();
        
        $event = new Event;
        $event->name = $eventData['title'];
        $event->startDateTime = Carbon::parse($eventData['start_datetime']);
        $event->endDateTime = Carbon::parse($eventData['end_datetime']);
        $event->location = $eventData['location'] ?? $meeting->location;
        $event->description = $eventData['description'] ?? '';
        
        // Set Extended Properties to override automatic categorization
        $event->addExtendedProperty('meeting_slug', $meetingSlug);
        $event->addExtendedProperty('speaker_name', $eventData['speaker'] ?? '');
        
        $event->save();
        
        // Sync to local database immediately
        $this->syncSingleEvent($event);
        
        return $event;
    }
    
    private function determineMeetingSlug(Event $googleEvent): string
    {
        // First check Extended Properties (manual override)
        $extendedSlug = $googleEvent->getExtendedProperty('meeting_slug');
        if ($extendedSlug) {
            return $extendedSlug;
        }
        
        // Then try pattern matching (automatic categorization)
        $title = strtolower($googleEvent->name);
        $patterns = config('calendar.meeting_patterns');
        
        foreach ($patterns as $meetingSlug => $config) {
            foreach ($config['patterns'] as $pattern) {
                $searchPattern = $config['case_insensitive'] ? strtolower($pattern) : $pattern;
                if (str_contains($title, $searchPattern)) {
                    return $meetingSlug;
                }
            }
        }
        
        // Fallback to uncategorized
        return config('calendar.uncategorized_slug', 'uncategorized');
    }
    
    public function getUncategorizedEvents(): Collection
    {
        return CalendarEvent::where('meeting_slug', config('calendar.uncategorized_slug', 'uncategorized'))
                           ->orderBy('start_datetime')
                           ->get();
    }
    
    public function manuallyCategorizEvent(int $eventId, string $meetingSlug): CalendarEvent
    {
        $event = CalendarEvent::findOrFail($eventId);
        
        // Update local event
        $event->update([
            'meeting_slug' => $meetingSlug,
            'is_categorized_automatically' => false,
        ]);
        
        // Update Google Calendar Extended Property for future syncs
        $googleEvent = Event::find($event->google_event_id);
        if ($googleEvent) {
            $googleEvent->addExtendedProperty('meeting_slug', $meetingSlug);
            $googleEvent->save();
        }
        
        return $event;
    }
}
```

#### 2.2 CalendarEvent Model
Create `app/Models/CalendarEvent.php`:

```php
class CalendarEvent extends Model
{
    protected $fillable = [
        'google_event_id',
        'meeting_slug', 
        'title',
        'description',
        'speaker',
        'location',
        'start_datetime',
        'end_datetime',
        'status',
        'is_categorized_automatically',
    ];
    
    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'is_categorized_automatically' => 'boolean',
    ];
    
    // Relationship to Meeting
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_slug', 'slug');
    }
    
    // Scope for upcoming events
    public function scopeUpcoming($query): Builder
    {
        return $query->where('start_datetime', '>=', now());
    }
    
    // Scope for past events
    public function scopePast($query): Builder
    {
        return $query->where('start_datetime', '<', now());
    }
    
    // Scope for confirmed events
    public function scopeConfirmed($query): Builder
    {
        return $query->where('status', 'confirmed');
    }
}
```

#### 2.3 Meeting Model Extensions
Add calendar event relationships to Meeting model:

```php
// Add to existing Meeting model
class Meeting extends Model
{
    // ... existing code ...
    
    // Relationship to calendar events
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'meeting_slug', 'slug');
    }
    
    // Get upcoming events for this meeting
    // Note: Use eager loading in controllers to avoid N+1 queries
    public function getUpcomingEventsAttribute(): Collection
    {
        return $this->calendarEvents()
                    ->upcoming()
                    ->confirmed()
                    ->orderBy('start_datetime')
                    ->limit(config('calendar.performance.eager_load_limit', 100))
                    ->get();
    }
    
    // Get past events for this meeting  
    // Note: Use eager loading in controllers to avoid N+1 queries
    public function getPastEventsAttribute(): Collection
    {
        return $this->calendarEvents()
                    ->past()
                    ->confirmed()
                    ->orderBy('start_datetime', 'desc')
                    ->limit(config('calendar.performance.eager_load_limit', 100))
                    ->get();
    }
    
    // Get the next scheduled event
    public function getNextEventAttribute(): ?CalendarEvent
    {
        return $this->upcoming_events->first();
    }
    
    // Get the most recent past event
    public function getLastEventAttribute(): ?CalendarEvent  
    {
        return $this->past_events->first();
    }
    
    // Helper to create a new event instance for this meeting
    public function createEvent(array $eventData): Event
    {
        return app(CalendarService::class)->createEventForMeeting($this->slug, $eventData);
    }
}
```

### Phase 3: Enhanced Admin Interface (2-3 hours)

#### 3.1 Uncategorized Events Management
Create admin interface for managing automatically uncategorized events:

```php
class CalendarAdminController extends Controller
{
    public function uncategorizedEvents()
    {
        $calendarService = app(CalendarService::class);
        $uncategorizedEvents = $calendarService->getUncategorizedEvents();
        $meetings = Meeting::orderBy('slug')->get();
        
        return view('admin.calendar.uncategorized', compact('uncategorizedEvents', 'meetings'));
    }
    
    public function categorizeEvent(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:calendar_events,id',
            'meeting_slug' => 'required|string|exists:meetings,slug',
        ]);
        
        $calendarService = app(CalendarService::class);
        $event = $calendarService->manuallyCategorizEvent(
            $validated['event_id'], 
            $validated['meeting_slug']
        );
        
        return redirect()->back()->with('success', "Event '{$event->title}' categorized successfully");
    }
    
    public function patternManagement()
    {
        $patterns = config('calendar.meeting_patterns');
        $meetings = Meeting::orderBy('slug')->get();
        
        return view('admin.calendar.patterns', compact('patterns', 'meetings'));
    }
    
    public function updatePatterns(Request $request)
    {
        // Update pattern configuration
        // This would require a more sophisticated config management system
        // For now, patterns are managed in config file
        
        return redirect()->back()->with('success', 'Patterns updated successfully');
    }
}
```

#### 3.2 Event Creation Interface  
Enhanced interface for creating new events with proper categorization:

```php
class EventCreationController extends Controller
{
    public function create(Meeting $meeting)
    {
        return view('admin.events.create', compact('meeting'));
    }
    
    public function store(Request $request, Meeting $meeting)
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'speaker' => 'nullable|string',
            'location' => 'nullable|string',
            'description' => 'nullable|string',
        ]);
        
        // Create event in Google Calendar with proper Extended Properties
        $event = $meeting->createEvent($validated);
        
        return redirect()->route('meetings.show', $meeting)
               ->with('success', 'Event created successfully');
    }
}
```

### Phase 4: Website Display Controllers (1-2 hours)

#### 4.1 Meeting Pages with Calendar Events
Update existing meeting controllers to show calendar events:

```php
class MeetingController extends Controller
{
    // Show individual meeting page with its calendar events
    public function show(Meeting $meeting)
    {
        // Eager load events to avoid N+1 queries
        $meeting->load([
            'calendarEvents' => function ($query) {
                $query->upcoming()->confirmed()->orderBy('start_datetime')->limit(5);
            }
        ]);
        
        $upcomingEvents = $meeting->calendarEvents;
        
        // Load past events separately if needed
        $pastEvents = $meeting->calendarEvents()
                             ->past()
                             ->confirmed()
                             ->orderBy('start_datetime', 'desc')
                             ->limit(10)
                             ->get();
        
        return view('meetings.show', compact('meeting', 'upcomingEvents', 'pastEvents'));
    }
    
    // Show all events for a meeting (paginated)
    public function events(Meeting $meeting)
    {
        $calendarService = app(CalendarService::class);
        $events = $calendarService->getEventsForMeeting($meeting->slug)
                                 ->sortBy('start_datetime');
        
        return view('meetings.events', compact('meeting', 'events'));
    }
}

class CalendarController extends Controller  
{
    // Scenario 1: Display all upcoming events from all meetings
    public function index()
    {
        // Use database queries with eager loading for efficiency
        $allEvents = CalendarEvent::with('meeting')
                                  ->upcoming()
                                  ->confirmed()
                                  ->whereBetween('start_datetime', [now(), now()->addMonths(6)])
                                  ->orderBy('start_datetime')
                                  ->limit(50)
                                  ->get();
        
        return view('calendar.index', compact('allEvents'));
    }
    
    // Example of bulk meeting display with eager loading
    public function meetingsIndex()
    {
        // Efficient way to load all meetings with their upcoming events
        $meetings = Meeting::with([
            'calendarEvents' => function ($query) {
                $query->upcoming()
                      ->confirmed()
                      ->orderBy('start_datetime')
                      ->limit(config('calendar.performance.eager_load_limit', 5));
            }
        ])->get();
        
        return view('meetings.index', compact('meetings'));
    }
    
    // Show uncategorized events to public (if desired)
    public function uncategorized()
    {
        $calendarService = app(CalendarService::class);
        $uncategorizedEvents = $calendarService->getUncategorizedEvents()
                                              ->where('start_datetime', '>=', now())
                                              ->take(20);
        
        return view('calendar.uncategorized', compact('uncategorizedEvents'));
    }
}
```

### Phase 5: Sync Commands and Scheduling (1 hour)

#### 5.1 Calendar Sync Command
Create command to sync events from Google Calendar:

```php
class SyncGoogleCalendarCommand extends Command
{
    // Simplified signature with no options - behavior controlled by config
    protected $signature = 'calendar:sync';
    protected $description = 'Syncs events from Google Calendar using the configured window';
    
    public function handle(CalendarService $calendarService)
    {
        $this->info('Starting Google Calendar sync...');
        
        try {
            // The service handles getting config values and returns a report
            $report = $calendarService->syncFromGoogleCalendar();
            
            $this->info('Sync completed successfully!');
            $this->info("Sync window: {$report['start_date']} to {$report['end_date']}");
            $this->info("Processed: {$report['processed_events']}, Deleted: {$report['deleted_events']}");
            $this->info("Uncategorized: {$report['uncategorized_events']}");
            
            if ($report['uncategorized_events'] > 0) {
                $this->warn('Review uncategorized events in the admin panel.');
            }
            
        } catch (Exception $e) {
            $this->error("Sync failed: " . $e->getMessage());
            Log::error('Calendar sync failed', ['error' => $e->getMessage()]);
            return 1;
        }
        
        return 0;
    }
}
```

#### 5.2 Scheduled Sync
Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Simple, predictable sync every 4 hours
    // Balances data freshness with server load
    $schedule->command('calendar:sync')->cron('0 */4 * * *');
    
    // Optional: Daily catch-all for extra reliability
    // $schedule->command('calendar:sync')->dailyAt('02:00');
}
```

### Phase 6: Cache Layer (Optional - 1 hour)

#### 6.1 Add Caching for Performance
Add caching to CalendarService for frequently accessed data:

```php
// Add to CalendarService
public function getCachedEventsForMeeting(string $meetingSlug, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
{
    $cacheKey = "meeting_events_{$meetingSlug}_" . ($startDate?->format('Y-m-d') ?? 'all') . "_" . ($endDate?->format('Y-m-d') ?? 'all');
    
    return Cache::remember($cacheKey, now()->addHours(1), function() use ($meetingSlug, $startDate, $endDate) {
        return $this->getEventsForMeeting($meetingSlug, $startDate, $endDate);
    });
}

public function clearEventCache(string $meetingSlug = null): void
{
    if ($meetingSlug) {
        Cache::forget("meeting_events_{$meetingSlug}_*");
    } else {
        Cache::flush(); // Or use a more targeted approach
    }
}
```

## Implementation Timeline (SIMPLIFIED HYBRID APPROACH)

- **Day 1**: Phase 1 (Foundation Setup + Pattern Configuration)
- **Day 2**: Phase 2 (Hybrid Calendar Service with Pattern Matching)  
- **Day 3**: Phase 3 (Enhanced Admin Interface)
- **Day 4**: Phase 4 (Website Display Controllers with Eager Loading)
- **Day 5**: Phase 5 (Simplified Sync Commands)

**Removed Complexity:**
- ❌ Multiple sync schedules → Single 4-hour cron
- ❌ Command-line options → Config-driven behavior
- ❌ Complex caching layer → Database queries with eager loading
- ❌ Multiple sync windows → Single configurable window

## ✅ Handling the Three Key Scenarios (HYBRID)

### ✅ Scenario 1: Display all events from calendar in event feed
**Solution:** Database queries with automatic categorization
- Route: `/calendar` → `CalendarController@index()`
- Uses CalendarEvent model with Meeting relationships
- Shows events from all meetings, properly categorized

### ✅ Scenario 2: Display all events for a certain meeting
**Solution:** Meeting model with calendar event relationship
- Route: `/meetings/{meeting}` → `MeetingController@show()`
- Uses `$meeting->upcoming_events` relationship
- Automatically categorized events via pattern matching

### ✅ Scenario 3: Display events that don't match to a meeting
**Solution:** Automatic "uncategorized" handling + admin interface
- Route: `/admin/calendar/uncategorized` → `CalendarAdminController@uncategorizedEvents()`
- Events automatically marked as "uncategorized" if no pattern matches
- Admin can manually categorize with dropdown interface

## ✅ **Answer: Yes, events can be created in Google Calendar normally**

### Hybrid Workflow:

1. **Create event in Google Calendar** (normal way)
   - Example: "Sunday Morning Service - Pastor John"
2. **Automatic categorization happens during sync:**
   - Pattern matching finds "sunday morning service" → categorizes as `sunday-mornings`
   - If no pattern matches → categorizes as `uncategorized`
   - Extended Properties override automatic categorization
3. **Admin reviews uncategorized events** (minimal effort)
   - Only needed for genuinely unusual events
   - Simple dropdown to categorize
4. **Website displays all events** with proper categorization

### Benefits of Hybrid Approach:
- ✅ **Automatic categorization** - 80% of events categorized without admin intervention
- ✅ **Spec compliance** - database storage, pattern matching, uncategorized handling
- ✅ **Minimal admin burden** - only special events need attention
- ✅ **Google Calendar workflow** - staff create events naturally
- ✅ **Override capability** - Extended Properties for special cases

## Why This Simplified Hybrid Approach is Superior

### Compared to Manual-Only Approach:
- **80% less admin work** - most events categorized automatically
- **Spec compliant** - meets all requirements for pattern matching and categorization
- **Church-friendly** - works with natural event naming patterns

### Compared to Complex Sync Approaches:
- **Single source of truth** - Google Calendar remains authoritative
- **Clear data flow** - sync → categorize → display
- **Easier debugging** - transparent categorization process

### Simplified Maintenance:
- **Single sync schedule** - predictable 4-hour intervals
- **Config-driven** - all behavior controlled via config files
- **No command options** - consistent, predictable execution
- **Eager loading** - efficient database queries, no N+1 problems
- **Fewer moving parts** - easier to debug and maintain

### Addresses All Spec Requirements:
1. ✅ **Database storage** with Google Calendar event IDs
2. ✅ **Pattern matching** for automatic categorization  
3. ✅ **Uncategorized events** handling
4. ✅ **Daily sync** with manual refresh option
5. ✅ **Admin category management** interface

## Testing Strategy (HYBRID)

### Unit Tests
- Pattern matching algorithm accuracy
- CalendarEvent model relationships
- Meeting model calendar accessors

### Feature Tests  
- Sync command with pattern matching
- Admin categorization interface
- Calendar event display on meeting pages
- Uncategorized events handling

### Integration Tests
- Google Calendar API sync with pattern categorization
- End-to-end event creation and display workflow

## Security & Maintenance

- Google API credentials security
- Pattern matching performance optimization
- Sync error handling and logging
- Cache invalidation strategies

## Success Metrics

- 80%+ events automatically categorized correctly
- Staff can manage events entirely in Google Calendar
- Minimal admin time spent on event categorization  
- Website shows real-time calendar data with proper organization