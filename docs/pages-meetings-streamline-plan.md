# Pages & Meetings Streamlining - Implementation Plan

## Overview

This plan consolidates Pages and Meetings into a cleaner architecture where:
- **Meeting** belongs to **Page** for content (heading, body, images)
- Routing is simplified with clear controller responsibility
- Meeting-specific URL-parsing is removed from ViewServiceProvider
- Meeting admin moves to Filament

**Out of scope (for now):**
- Landing pages (`/christ`, `/church`, `/community`) remain as hardcoded Blade files in `full-width-pages/` - they have complex custom logic that would require a page builder approach to make fully editable
- Other dynamic content pages (sermons, calendar, auth) - these continue to use ViewServiceProvider for page chrome composition

## Current State

| Aspect | Pages | Meetings |
|--------|-------|----------|
| Admin UI | Filament ✅ | Legacy Laravel forms |
| Content | Self-contained | Self-contained (no body text) |
| Images | Spatie MediaLibrary | No images |
| Routing | `/{area}/{slug}` | `/community/{slug}` |
| Relationship | None | None |

## Target State

| Aspect | Pages | Meetings |
|--------|-------|----------|
| Admin UI | Filament ✅ | Filament (new) |
| Content | Self-contained | **Delegates to Page** |
| Images | Spatie MediaLibrary | **Via related Page** |
| Routing | `/{area}/{slug}` (unchanged) | `/community/{slug}` (unchanged) |
| Relationship | `hasOne(Meeting)` | **belongsTo(Page)** |

## URL Structure (Unchanged)

The routing stays separate - these are **different URL patterns**:

| URL Pattern | Handled By | Example |
|-------------|------------|---------|
| `/community/{slug}` | MeetingController | `/community/coffee-cup` → Meeting |
| `/{area}/{slug}` | PageController | `/church/history` → Page |
| `/{area}` | Full-width Blade | `/community` → `full-width-pages/community.blade.php` |

The key simplification is **removing the hybrid `showCommunityContent`** method that checks both models. After this change:
- `/community/{slug}` always loads a Meeting (which gets content from its related Page)
- Pages in the community area would need a Meeting record to be accessible (or use a different URL pattern)

---

## Phase 1: Database Schema Changes

### 1.1 Add `page_id` to meetings table

```php
// database/migrations/xxxx_add_page_id_to_meetings_table.php
Schema::table('meetings', function (Blueprint $table) {
    $table->foreignId('page_id')->nullable()->after('id')->constrained()->nullOnDelete();
});
```

---

## Phase 2: Model Updates

### 2.1 Update Meeting Model

**File:** `app/Models/Meeting.php`

```php
// Add relationship
public function page(): BelongsTo
{
    return $this->belongsTo(Page::class);
}

// Add content accessors that delegate to Page
public function getHeadingAttribute(): ?string
{
    return $this->page?->heading ?? Str::title(str_replace('-', ' ', $this->slug));
}

public function getDescriptionAttribute(): ?string
{
    return $this->page?->description;
}

public function getBodyAttribute(): ?string
{
    return $this->page?->body;
}

public function getMarkdownAttribute(): ?string
{
    return $this->page?->markdown;
}

public function getHeadingImageUrlAttribute(): ?string
{
    return $this->page?->heading_image_url;
}

// Helper to check if meeting has rich content
public function hasContent(): bool
{
    return $this->page !== null;
}
```

### 2.2 Update Page Model

**File:** `app/Models/Page.php`

```php
// Add relationship
public function meeting(): HasOne
{
    return $this->hasOne(Meeting::class);
}

// Check if page has associated meeting
public function hasMeeting(): bool
{
    return $this->meeting()->exists();
}
```

---

## Phase 3: Data Migration

### 3.1 Create Pages for Existing Meetings

**File:** `database/migrations/xxxx_create_pages_for_meetings.php`

This migration creates a Page record for each Meeting and links them:

```php
public function up(): void
{
    // Get all meetings
    $meetings = DB::table('meetings')->get();

    foreach ($meetings as $meeting) {
        // Check if a page with this slug already exists in community area
        $existingPage = DB::table('pages')
            ->where('slug', $meeting->slug)
            ->where('area', 'community')
            ->first();

        if ($existingPage) {
            // Link meeting to existing page
            $pageId = $existingPage->id;
        } else {
            // Create a page for this meeting
            $pageId = DB::table('pages')->insertGetId([
                'slug' => $meeting->slug,
                'heading' => Str::title(str_replace('-', ' ', $meeting->slug)),
                'area' => 'community',
                'navigation' => false,
                'published' => true,
                'description' => "Details for {$meeting->who}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Link meeting to page
        DB::table('meetings')
            ->where('id', $meeting->id)
            ->update(['page_id' => $pageId]);
    }
}

public function down(): void
{
    // Remove page_id references
    DB::table('meetings')->update(['page_id' => null]);

    // Optionally delete auto-created pages (be careful here)
}
```

---

## Phase 4: Filament MeetingResource

### 4.1 Create MeetingResource

**File:** `app/Filament/Resources/MeetingResource.php`

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MeetingResource\Pages;
use App\Models\Meeting;
use App\Models\Page;
use App\Enums\MeetingType;
use App\Enums\MeetingFrequency;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MeetingResource extends Resource
{
    protected static ?string $model = Meeting::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Content';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Meeting Details')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\Select::make('type')
                        ->options(MeetingType::class)
                        ->required(),

                    Forms\Components\TextInput::make('day')
                        ->placeholder('e.g., Sundays, First Tuesday of month')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('location')
                        ->placeholder('e.g., Church Hall')
                        ->maxLength(255),

                    Forms\Components\TimePicker::make('StartTime')
                        ->label('Start Time')
                        ->seconds(false),

                    Forms\Components\TimePicker::make('EndTime')
                        ->label('End Time')
                        ->seconds(false),

                    Forms\Components\TextInput::make('who')
                        ->label('Who is it for?')
                        ->placeholder('e.g., Everyone, Children aged 5-11')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Recurring Schedule')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('is_recurring')
                        ->label('Is this a recurring meeting?')
                        ->reactive(),

                    Forms\Components\Select::make('frequency')
                        ->options(MeetingFrequency::class)
                        ->visible(fn ($get) => $get('is_recurring')),

                    Forms\Components\DateTimePicker::make('meeting_date')
                        ->label('Specific Date (if not recurring)')
                        ->visible(fn ($get) => !$get('is_recurring')),
                ]),

            Forms\Components\Section::make('Contact Information')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('LeadersPhone')
                        ->label('Leader\'s Phone')
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('LeadersEmail')
                        ->label('Leader\'s Email')
                        ->email()
                        ->maxLength(255),
                ]),

            Forms\Components\Section::make('Content Page')
                ->description('Link to a Page for heading, description, body content, and images')
                ->schema([
                    Forms\Components\Select::make('page_id')
                        ->label('Content Page')
                        ->relationship('page', 'heading')
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('heading')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn ($state, callable $set) =>
                                    $set('slug', \Str::slug($state))
                                ),
                            Forms\Components\TextInput::make('slug')
                                ->required()
                                ->maxLength(255),
                            Forms\Components\Hidden::make('area')
                                ->default('community'),
                            Forms\Components\Textarea::make('description')
                                ->label('SEO Description')
                                ->maxLength(155),
                            Forms\Components\MarkdownEditor::make('markdown')
                                ->label('Content'),
                        ])
                        ->editOptionForm([
                            Forms\Components\TextInput::make('heading')
                                ->required(),
                            Forms\Components\Textarea::make('description')
                                ->maxLength(155),
                            Forms\Components\MarkdownEditor::make('markdown'),
                        ])
                        ->helperText('Select an existing page or create a new one for this meeting\'s content.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('page.heading')
                    ->label('Heading')
                    ->default(fn ($record) => \Str::title(str_replace('-', ' ', $record->slug)))
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge(),

                Tables\Columns\TextColumn::make('day')
                    ->searchable(),

                Tables\Columns\TextColumn::make('StartTime')
                    ->label('Time')
                    ->formatStateUsing(fn ($state, $record) =>
                        $state ? $state->format('g:ia') . ($record->EndTime ? ' - ' . $record->EndTime->format('g:ia') : '') : '-'
                    ),

                Tables\Columns\IconColumn::make('is_recurring')
                    ->boolean()
                    ->label('Recurring'),

                Tables\Columns\TextColumn::make('calendarEvents_count')
                    ->counts('calendarEvents')
                    ->label('Events'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(MeetingType::class),

                Tables\Filters\TernaryFilter::make('is_recurring')
                    ->label('Recurring'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Meeting $record) => "/community/{$record->slug}")
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // CalendarEventsRelationManager::class, // Optional: create if needed
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMeetings::route('/'),
            'create' => Pages\CreateMeeting::route('/create'),
            'edit' => Pages\EditMeeting::route('/{record}/edit'),
        ];
    }
}
```

### 4.2 Create Resource Pages

**Files to create:**
- `app/Filament/Resources/MeetingResource/Pages/ListMeetings.php`
- `app/Filament/Resources/MeetingResource/Pages/CreateMeeting.php`
- `app/Filament/Resources/MeetingResource/Pages/EditMeeting.php`

Standard Filament resource pages.

---

## Phase 5: Update PageResource

### 5.1 Add meeting indicator to table

**File:** `app/Filament/Resources/PageResource.php`

```php
Tables\Columns\IconColumn::make('meeting')
    ->label('Has Meeting')
    ->boolean()
    ->getStateUsing(fn ($record) => $record->meeting()->exists())
    ->toggleable(isToggledHiddenByDefault: true),
```

---

## Phase 6: Routing Simplification

### 6.1 Update routes/web.php

The URL patterns stay the same, but the logic is cleaner:

```php
// Landing pages (level 1) - keep existing full-width-pages approach
Route::get('/{area}', [PageController::class, 'showArea'])
    ->where('area', 'christ|church|community')
    ->name('area.show');

// Community meetings - always loads a Meeting
Route::get('/community/{slug}', [MeetingController::class, 'show'])
    ->name('meetings.show');

// Standard pages (other areas)
Route::get('/{area}/{slug}', [PageController::class, 'show'])
    ->where('area', 'christ|church|members')
    ->name('pages.show');
```

**Key change:** Remove the hybrid `showCommunityContent` method that checks both Meeting and Page models. Now `/community/{slug}` always expects a Meeting.

### 6.2 Update MeetingController

**File:** `app/Http/Controllers/MeetingController.php`

```php
public function show(Meeting $meeting)
{
    // Eager load page and calendar events
    $meeting->load([
        'page',
        'calendarEvents' => fn($q) => $q->upcoming()->limit(6),
    ]);

    return view('meetings.show', [
        'meeting' => $meeting,
        'page' => $meeting->page,
        'upcomingEvents' => $meeting->calendarEvents,
        'pastEvents' => $meeting->calendarEvents()->past()->limit(3)->get(),
    ]);
}
```

### 6.3 PageController remains mostly unchanged

The `showArea` method continues to render the appropriate `full-width-pages/*.blade.php` template. No changes needed there.

---

## Phase 7: View Updates

### 7.1 Update meetings/show.blade.php

The meeting view now receives both `$meeting` and `$page`:

```blade
@extends('layouts.page')

@section('content')
  {{-- Heading from page --}}
  @if($page?->heading_image_url)
    <x-h1-picture :headingpicture="$page->heading_image_url">
      {{ $page->heading ?? $meeting->heading }}
    </x-h1-picture>
  @else
    <x-h1>{{ $page->heading ?? $meeting->heading }}</x-h1>
  @endif

  {{-- Page content if exists --}}
  @if($page?->body)
    <div class="prose max-w-none mb-8">
      {!! $page->body !!}
    </div>
  @endif

  {{-- Meeting details table --}}
  @include('meetings.partials.details-table', ['meeting' => $meeting])

  {{-- Calendar events --}}
  @if($upcomingEvents->isNotEmpty())
    @include('meetings.partials.upcoming-events', ['events' => $upcomingEvents])
  @endif

  @if($pastEvents->isNotEmpty())
    @include('meetings.partials.past-events', ['events' => $pastEvents])
  @endif
@endsection
```

---

## Phase 8: ViewServiceProvider Cleanup

### 8.1 What changes

**File:** `app/Providers/ViewServiceProvider.php`

The `layouts/page` view composer currently has complex URL-parsing logic that:
1. Parses URL segments to determine content type
2. Looks up Pages by slug/area
3. **Looks up Meetings for `/community/{slug}` URLs** ← Remove this
4. Composes variables like `$heading`, `$content`, `$headingpicture`, `$area`, `$links`

**Only remove the meeting-specific lookup.** The rest of the URL-parsing logic must remain because many other views depend on it:

- `sermons/sermon.blade.php` - Uses `@section('dynamic_content')` with ViewServiceProvider-composed chrome
- `calendar/*.blade.php` - Same pattern
- `auth/*.blade.php` - Same pattern
- `pages/*.blade.php` - Admin views
- And 20+ other views using `@extends('layouts/page')`

### 8.2 What to remove

In the `layouts/page` composer, find and remove the section that:
- Checks if the URL is `/community/{slug}`
- Queries the Meeting model
- Falls back to Page if no meeting found

This is the "hybrid `showCommunityContent`" logic duplicated in ViewServiceProvider.

### 8.3 What to keep

Retain all of:
- `includes.header` composer - Navigation pages
- `includes.footer` composer - Latest sermons
- `full-width-pages.*` composers - Landing page data
- **Page lookup logic** in `layouts/page` composer - Still needed for sermons, calendar, etc.
- **Variable composition** (`$heading`, `$content`, `$headingpicture`, etc.) - Still needed

### 8.4 Why this is safe

After this change:
- `/community/{slug}` → MeetingController passes all data directly, ViewServiceProvider not invoked for meetings
- `/christ/sermons/{year}/{month}/{slug}` → SermonController + ViewServiceProvider still works (unchanged)
- `/{area}/{slug}` → PageController + ViewServiceProvider still works (unchanged)

The key insight: **MeetingController will pass explicit view data**, so ViewServiceProvider's meeting lookup becomes dead code for that route.

---

## Phase 9: Cleanup

### 9.1 Remove legacy admin views

Delete these files (after Filament fully handles meetings):
- `resources/views/meetings/index.blade.php`
- `resources/views/meetings/create.blade.php`
- `resources/views/meetings/edit.blade.php`

### 9.2 Remove legacy routes

Remove from `routes/web.php`:
- `/church/members/meetings` CRUD routes
- Old `showCommunityContent` hybrid route (replace with simple `show`)

### 9.3 Add redirects

Add redirects from old admin URLs to Filament:

```php
// In RouteServiceProvider or web.php
Route::redirect('/church/members/meetings', '/admin/meetings');
Route::redirect('/church/members/meetings/{meeting}/edit', '/admin/meetings/{meeting}/edit');
```

---

## Implementation Order

| Step | Task | Files Changed |
|------|------|---------------|
| 1 | Create migration (page_id) | `database/migrations/` |
| 2 | Run migration | - |
| 3 | Update Meeting model | `app/Models/Meeting.php` |
| 4 | Update Page model | `app/Models/Page.php` |
| 5 | Create data migration | `database/migrations/` |
| 6 | Create MeetingResource | `app/Filament/Resources/MeetingResource.php` |
| 7 | Create MeetingResource pages | `app/Filament/Resources/MeetingResource/Pages/` |
| 8 | Update PageResource (optional indicator) | `app/Filament/Resources/PageResource.php` |
| 9 | Update routes | `routes/web.php` |
| 10 | Update MeetingController | `app/Http/Controllers/MeetingController.php` |
| 11 | Update meeting views | `resources/views/meetings/` |
| 12 | Clean ViewServiceProvider | `app/Providers/ViewServiceProvider.php` |
| 13 | Remove legacy views | `resources/views/meetings/*.blade.php` |
| 14 | Add redirects | `routes/web.php` |
| 15 | Test thoroughly | - |

---

## Testing Checklist

### Meeting changes
- [ ] Meetings display correctly with page content
- [ ] Meetings without pages show fallback heading (from slug)
- [ ] MeetingResource CRUD works in Filament
- [ ] Creating page inline from MeetingResource works
- [ ] `/community/{slug}` resolves to meetings
- [ ] Calendar events still display on meetings
- [ ] Old admin URLs redirect to Filament

### Unchanged functionality (regression tests)
- [ ] `/{area}/{slug}` resolves to pages (unchanged)
- [ ] Landing pages still work (`/christ`, `/church`, `/community`)
- [ ] Sermon pages display correctly (`/christ/sermons/{year}/{month}/{slug}`)
- [ ] Calendar pages display correctly (`/calendar/*`)
- [ ] Auth pages display correctly (`/login`, `/register`, etc.)
- [ ] Navigation still works
- [ ] Sitemap generates correctly

---

## Rollback Plan

If issues arise:
1. Revert migration (drop page_id column)
2. Restore `showCommunityContent` hybrid method
3. Restore legacy meeting admin routes
4. Keep MeetingResource but make page_id optional

---

## Benefits Summary

1. **Single source of truth** - Content in Pages, schedule in Meetings
2. **Unified admin** - Meetings managed in Filament alongside Pages
3. **Simpler routing** - No hybrid lookup, clear controller responsibility
4. **Consistent UX** - Same image/content patterns across Pages and Meetings
5. **Maintainable** - Clear relationships, less implicit behavior

## Impact on Dynamic Content Pages

Many views use `@extends('layouts/page')` and `@section('dynamic_content')` to inject custom UI below the standard page chrome. These pages are **NOT affected** by this plan:

| View | URL Pattern | Impact |
|------|-------------|--------|
| `sermons/sermon.blade.php` | `/christ/sermons/{year}/{month}/{slug}` | ✅ No change - SermonController + ViewServiceProvider unchanged |
| `calendar/*.blade.php` | `/calendar/*` | ✅ No change |
| `auth/*.blade.php` | `/login`, `/register`, etc. | ✅ No change |
| `meetings/show.blade.php` | `/community/{slug}` | ⚠️ **Updated** - Now receives `$page` from MeetingController |

### How dynamic content pages work

1. Controller renders a view that `@extends('layouts/page')`
2. ViewServiceProvider's `layouts/page` composer runs and sets variables like `$heading`, `$content`, `$headingpicture`
3. The view fills `@section('dynamic_content')` with custom UI (e.g., audio player, transcript)
4. `layouts/page.blade.php` renders everything together

### Why they're unaffected

This plan only changes:
- **Meeting model** - adds `page_id` relationship
- **MeetingController** - now passes `$page` explicitly
- **ViewServiceProvider** - removes meeting lookup (but keeps everything else)
- **meetings/show.blade.php** - updated to use `$page` from controller

Other dynamic content pages continue to work because:
- Their controllers don't change
- ViewServiceProvider still composes page chrome for their URLs
- They don't use the meeting lookup logic being removed

## Future Considerations

- **Landing pages**: Could be made editable via Filament with a `layout` column and page builder approach, but this is out of scope for now due to their complex custom logic
- **Community pages without meetings**: Currently `/community/{slug}` expects a Meeting. If standalone community pages are needed, could add a fallback or use a different URL pattern
- **Migrate other dynamic content pages**: Eventually, other pages (sermons, calendar) could be migrated to pass all data from controllers, eliminating more ViewServiceProvider complexity. This would be a separate effort.
