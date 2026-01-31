# Filament Admin Interface Conversion Plan

This document outlines the plan to convert the remaining resources to use Filament as the admin interface.

## Current State

| Resource | Status | Notes |
|----------|--------|-------|
| **Pages** | Complete | Full CRUD with Media Library, markdown editor, filters |
| **Meetings** | Partial | Basic CRUD exists, needs Media Library for photos |
| **Sermons** | Not Started | Most complex - requires custom upload handling |
| **Calendar Events** | Not Started | Read-only with Google Calendar sync |
| **Users** | Not Started | Basic user management |

---

## Phase 1: Complete MeetingResource Enhancement

**Priority**: High
**Complexity**: Medium
**Dependencies**: None (already partially implemented)

### Current Implementation

The MeetingResource already has:
- Basic form with all fields
- Type and recurring filters
- Contact information section
- Inline page creation

### Missing Features

1. **Media Library Integration for Photos**
   - Add `SpatieMediaLibraryFileUpload` for meeting photos
   - Support for photo galleries (multiple images)

2. **Calendar Events Relationship Display**
   - Add relation manager to show linked CalendarEvents
   - Display upcoming events for each meeting

3. **Enhanced Recurring Logic**
   - Show next occurrence calculation in view
   - Add preview of upcoming occurrences

### Implementation Tasks

```php
// 1. Add to MeetingResource form()
SpatieMediaLibraryFileUpload::make('photos')
    ->collection('photos')
    ->multiple()
    ->reorderable()
    ->image()
    ->imageEditor()
    ->columnSpanFull(),

// 2. Create CalendarEventsRelationManager
// app/Filament/Resources/MeetingResource/RelationManagers/CalendarEventsRelationManager.php

// 3. Add computed column for next occurrence
Tables\Columns\TextColumn::make('next_occurrence')
    ->state(fn (Meeting $record) => $record->getNextOccurrence())
    ->dateTime('D, j M Y g:ia'),
```

### Files to Modify/Create

- `app/Filament/Resources/MeetingResource.php` - Add media upload, relation manager
- `app/Filament/Resources/MeetingResource/RelationManagers/CalendarEventsRelationManager.php` - New file

---

## Phase 2: CalendarEventResource (Read-Mostly)

**Priority**: Medium
**Complexity**: Low
**Dependencies**: None

### Considerations

- Events are synced from Google Calendar, not created locally
- Should be primarily read-only (or read + delete)
- Important to show meeting association
- Need to handle automatic categorization display

### Implementation

```php
// app/Filament/Resources/CalendarEventResource.php

class CalendarEventResource extends Resource
{
    protected static ?string $model = CalendarEvent::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Calendar';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Event Details')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->disabled(),
                    Forms\Components\Textarea::make('description')
                        ->disabled()
                        ->columnSpanFull(),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\DateTimePicker::make('start_datetime')
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('end_datetime')
                            ->disabled(),
                    ]),
                    Forms\Components\TextInput::make('location')
                        ->disabled(),
                    Forms\Components\TextInput::make('speaker')
                        ->disabled(),
                ]),

            Forms\Components\Section::make('Meeting Association')
                ->schema([
                    Forms\Components\Select::make('meeting_slug')
                        ->relationship('meeting', 'slug')
                        ->searchable()
                        ->preload()
                        ->helperText('Link this event to a church meeting'),
                    Forms\Components\Toggle::make('is_categorized_automatically')
                        ->disabled()
                        ->helperText('Was this automatically categorized?'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('start_datetime')
                    ->dateTime('D, j M Y g:ia')
                    ->sortable(),
                Tables\Columns\TextColumn::make('meeting.page.heading')
                    ->label('Meeting')
                    ->searchable()
                    ->placeholder('Uncategorized'),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'confirmed',
                        'warning' => 'tentative',
                        'danger' => 'cancelled',
                    ]),
                Tables\Columns\IconColumn::make('is_categorized_automatically')
                    ->label('Auto')
                    ->boolean(),
            ])
            ->defaultSort('start_datetime', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status'),
                Tables\Filters\SelectFilter::make('meeting_slug')
                    ->relationship('meeting', 'slug')
                    ->label('Meeting'),
                Tables\Filters\TernaryFilter::make('is_categorized_automatically')
                    ->label('Auto-categorized'),
                Tables\Filters\Filter::make('upcoming')
                    ->query(fn ($query) => $query->upcoming())
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(), // Only for meeting association
            ]);
    }
}
```

### Google Calendar Sync Integration

Add a custom Filament Page for managing calendar sync:

```php
// app/Filament/Pages/CalendarSync.php

class CalendarSync extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationGroup = 'Calendar';
    protected static ?string $title = 'Calendar Sync';

    // Show last sync time
    // Manual sync trigger button
    // OAuth status display
    // Error log display
}
```

### Files to Create

- `app/Filament/Resources/CalendarEventResource.php`
- `app/Filament/Resources/CalendarEventResource/Pages/ListCalendarEvents.php`
- `app/Filament/Resources/CalendarEventResource/Pages/EditCalendarEvent.php`
- `app/Filament/Pages/CalendarSync.php` (optional admin page)

---

## Phase 3: UserResource

**Priority**: Medium
**Complexity**: Low
**Dependencies**: None

### Considerations

- Users implement `FilamentUser` interface
- Access control: `@crockenhill.org` email + verified
- `is_admin` boolean for admin access
- Should NOT allow self-deletion or self-admin-removal

### Implementation

```php
// app/Filament/Resources/UserResource.php

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Administration';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('User Details')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\DateTimePicker::make('email_verified_at')
                        ->label('Email Verified')
                        ->helperText('Set to verify email manually'),
                ]),

            Forms\Components\Section::make('Password')
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->dehydrateStateUsing(fn ($state) =>
                            filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $operation) => $operation === 'create')
                        ->confirmed()
                        ->minLength(8),
                    Forms\Components\TextInput::make('password_confirmation')
                        ->password()
                        ->requiredWith('password'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Permissions')
                ->schema([
                    Forms\Components\Toggle::make('is_admin')
                        ->label('Administrator')
                        ->helperText('Grant full admin panel access')
                        ->disabled(fn ($record) =>
                            $record && $record->id === auth()->id()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->email_verified_at !== null),
                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('email_verified_at')
                    ->label('Email verified')
                    ->nullable(),
                Tables\Filters\TernaryFilter::make('is_admin')
                    ->label('Administrator'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn ($record) => $record->id === auth()->id()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records) {
                            $records = $records->reject(fn ($r) => $r->id === auth()->id());
                        }),
                ]),
            ]);
    }
}
```

### Files to Create

- `app/Filament/Resources/UserResource.php`
- `app/Filament/Resources/UserResource/Pages/ListUsers.php`
- `app/Filament/Resources/UserResource/Pages/CreateUser.php`
- `app/Filament/Resources/UserResource/Pages/EditUser.php`

---

## Phase 4: SermonResource (Most Complex)

**Priority**: High
**Complexity**: Very High
**Dependencies**: Understanding of processing pipeline

### Architecture Decision

**Recommendation: CRUD-Only (No Upload Processing in Filament)**

The existing API pipeline (`POST /api/sermons/{audio|video|livestream}`) already handles:
- Large file uploads with chunking
- Background processing jobs
- Transcription and AI analysis
- Livestream segmentation
- S3/Spaces hybrid storage

**Why not integrate uploads into Filament:**
1. Videos up to 2GB need custom chunked upload handling
2. Processing is async with background jobs - requires polling/WebSocket
3. Maintaining two upload paths doubles testing and bug surface
4. Filament's FileUpload component isn't designed for this scale

**Filament should handle:**
- Viewing and editing sermon metadata
- Managing thumbnails via Media Library
- Toggling visibility settings (show_summary, show_points)
- Filtering and searching sermons
- Viewing processing status (read-only)

**Uploads continue via:**
- Existing API endpoints
- Optional: Add a link in Filament nav to upload interface

### Implementation Plan

#### 4.1 Basic SermonResource

```php
// app/Filament/Resources/SermonResource.php

class SermonResource extends Resource
{
    protected static ?string $model = Sermon::class;
    protected static ?string $navigationIcon = 'heroicon-o-microphone';
    protected static ?string $navigationGroup = 'Content';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Group::make()
                ->schema([
                    Forms\Components\Section::make('Sermon Details')
                        ->schema([
                            Forms\Components\TextInput::make('title')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn ($state, callable $set) =>
                                    $set('slug', Str::slug($state))),
                            Forms\Components\TextInput::make('slug')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(255),
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\DatePicker::make('date')
                                    ->required()
                                    ->default(now()),
                                Forms\Components\Select::make('service')
                                    ->options(SermonService::class)
                                    ->required()
                                    ->default(SermonService::MORNING),
                            ]),
                            Forms\Components\TextInput::make('preacher')
                                ->required()
                                ->default('Mark Drury')
                                ->maxLength(255),
                            Forms\Components\TextInput::make('reference')
                                ->label('Bible Reference')
                                ->placeholder('e.g., John 3:16-21')
                                ->maxLength(255),
                            Forms\Components\TextInput::make('series')
                                ->placeholder('Sermon series name')
                                ->maxLength(255),
                        ]),

                    Forms\Components\Section::make('AI Analysis')
                        ->schema([
                            Forms\Components\Textarea::make('summary')
                                ->rows(4)
                                ->columnSpanFull(),
                            Forms\Components\KeyValue::make('points')
                                ->label('Key Points')
                                ->addActionLabel('Add Point')
                                ->keyLabel('Point Number')
                                ->valueLabel('Content')
                                ->columnSpanFull(),
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\Toggle::make('show_summary')
                                    ->label('Show Summary on Website')
                                    ->default(true),
                                Forms\Components\Toggle::make('show_points')
                                    ->label('Show Points on Website')
                                    ->default(true),
                            ]),
                        ])
                        ->collapsible()
                        ->collapsed(fn (?Sermon $record) => $record === null),
                ])
                ->columnSpan(['lg' => 2]),

            Forms\Components\Group::make()
                ->schema([
                    Forms\Components\Section::make('Media Files')
                        ->schema([
                            Forms\Components\FileUpload::make('audio_file_path')
                                ->label('Audio File')
                                ->disk('public')
                                ->directory('sermons')
                                ->acceptedFileTypes(['audio/mpeg', 'audio/mp3', 'audio/wav'])
                                ->maxSize(512000) // 500MB
                                ->visibility('public'),
                            Forms\Components\FileUpload::make('video_file_path')
                                ->label('Video File')
                                ->disk('public')
                                ->directory('sermons')
                                ->acceptedFileTypes(['video/mp4', 'video/webm'])
                                ->maxSize(2048000) // 2GB
                                ->visibility('public'),
                            Forms\Components\Select::make('source_type')
                                ->options([
                                    'manual' => 'Manual Upload',
                                    'livestream' => 'From Livestream',
                                    'upload' => 'API Upload',
                                ])
                                ->default('manual'),
                        ]),

                    Forms\Components\Section::make('Thumbnail')
                        ->schema([
                            SpatieMediaLibraryFileUpload::make('thumbnail')
                                ->collection('thumbnails')
                                ->image()
                                ->imageEditor()
                                ->imageResizeMode('cover')
                                ->imageCropAspectRatio('16:9'),
                        ]),

                    Forms\Components\Section::make('Processing Status')
                        ->schema([
                            Forms\Components\Placeholder::make('processing_status')
                                ->content(function (?Sermon $record) {
                                    if (!$record) return 'New sermon';
                                    if ($record->processingFailed()) return 'Processing failed';
                                    if ($record->processingInProgress()) return 'Processing...';
                                    if ($record->processingCompleted()) return 'Complete';
                                    return 'Pending';
                                }),
                            Forms\Components\Placeholder::make('duration_display')
                                ->label('Duration')
                                ->content(fn (?Sermon $record) =>
                                    $record?->duration
                                        ? gmdate('H:i:s', $record->duration)
                                        : 'Unknown'),
                        ])
                        ->visible(fn (?Sermon $record) => $record !== null),
                ])
                ->columnSpan(['lg' => 1]),
        ])
        ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('thumbnail')
                    ->collection('thumbnails')
                    ->conversion('thumbnail')
                    ->circular()
                    ->defaultImageUrl(url('/images/default-sermon.png')),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->tooltip(fn (Sermon $record) => $record->title),
                Tables\Columns\TextColumn::make('date')
                    ->date('j M Y')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('service')
                    ->colors([
                        'primary' => SermonService::MORNING,
                        'warning' => SermonService::EVENING,
                        'gray' => SermonService::OTHER,
                    ]),
                Tables\Columns\TextColumn::make('preacher')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reference')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('series')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('has_audio')
                    ->label('Audio')
                    ->getStateUsing(fn (Sermon $record) => filled($record->audio_file_path))
                    ->boolean(),
                Tables\Columns\IconColumn::make('has_video')
                    ->label('Video')
                    ->getStateUsing(fn (Sermon $record) => filled($record->video_file_path))
                    ->boolean(),
                Tables\Columns\TextColumn::make('source_type')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('service')
                    ->options(SermonService::class),
                Tables\Filters\SelectFilter::make('preacher')
                    ->options(fn () => Sermon::distinct()->pluck('preacher', 'preacher')),
                Tables\Filters\SelectFilter::make('series')
                    ->options(fn () => Sermon::whereNotNull('series')
                        ->distinct()
                        ->pluck('series', 'series')),
                Tables\Filters\Filter::make('has_video')
                    ->query(fn ($query) => $query->withVideo())
                    ->toggle(),
                Tables\Filters\Filter::make('from_livestream')
                    ->query(fn ($query) => $query->fromLivestream())
                    ->toggle(),
                Tables\Filters\Filter::make('last_12_months')
                    ->query(fn ($query) => $query->last12Months())
                    ->default(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->url(fn (Sermon $record) => route('sermons.show', $record))
                    ->icon('heroicon-o-eye')
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
}
```

#### 4.2 Link to External Upload (Optional)

Add a navigation link to your existing upload interface rather than rebuilding it:

```php
// In AdminPanelProvider.php
->navigationItems([
    NavigationItem::make('Upload Sermon')
        ->url('/upload') // or wherever your upload UI lives
        ->icon('heroicon-o-cloud-arrow-up')
        ->group('Content')
        ->sort(2),
])

#### 4.3 MediaProcessingLogResource (Monitoring)

```php
// app/Filament/Resources/MediaProcessingLogResource.php

class MediaProcessingLogResource extends Resource
{
    protected static ?string $model = MediaProcessingLog::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-8-tooth';
    protected static ?string $navigationGroup = 'System';
    protected static ?string $navigationLabel = 'Processing Jobs';

    public static function canCreate(): bool
    {
        return false; // Read-only
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('processing_id')
                    ->label('ID')
                    ->limit(8)
                    ->copyable(),
                Tables\Columns\BadgeColumn::make('processing_type')
                    ->colors([
                        'primary' => 'audio',
                        'success' => 'video',
                        'warning' => 'livestream',
                    ]),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => 'pending',
                        'warning' => 'processing',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('current_step')
                    ->limit(30),
                Tables\Columns\TextColumn::make('original_filename')
                    ->limit(30)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('processing_type'),
                Tables\Filters\SelectFilter::make('status'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('retry')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn ($record) => $record->status === 'failed')
                    ->action(fn ($record) => /* retry logic */),
            ]);
    }
}
```

### Files to Create

- `app/Filament/Resources/SermonResource.php`
- `app/Filament/Resources/SermonResource/Pages/ListSermons.php`
- `app/Filament/Resources/SermonResource/Pages/CreateSermon.php`
- `app/Filament/Resources/SermonResource/Pages/EditSermon.php`
- `app/Filament/Resources/MediaProcessingLogResource.php` (optional, for monitoring)

---

## Phase 5: Admin Dashboard

**Priority**: Low
**Complexity**: Low
**Dependencies**: All resources complete

### Custom Dashboard Widgets

```php
// app/Filament/Widgets/StatsOverview.php

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Sermons', Sermon::count())
                ->description('Last 12 months: ' . Sermon::last12Months()->count())
                ->icon('heroicon-o-microphone'),
            Stat::make('Active Meetings', Meeting::count())
                ->icon('heroicon-o-calendar'),
            Stat::make('Upcoming Events', CalendarEvent::upcoming()->count())
                ->icon('heroicon-o-clock'),
            Stat::make('Processing Jobs', MediaProcessingLog::where('status', 'processing')->count())
                ->description('Pending: ' . MediaProcessingLog::where('status', 'pending')->count())
                ->icon('heroicon-o-cog'),
        ];
    }
}

// app/Filament/Widgets/RecentSermons.php

class RecentSermons extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Sermon::query()->latest('date')->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\TextColumn::make('date')->date(),
                Tables\Columns\TextColumn::make('preacher'),
            ]);
    }
}
```

---

## Implementation Order

### Recommended Sequence

1. **Phase 1: MeetingResource Enhancement** (1-2 days)
   - Low risk, builds on existing work
   - Adds Media Library familiarity

2. **Phase 3: UserResource** (1 day)
   - Essential for admin management
   - Simple, standalone resource

3. **Phase 2: CalendarEventResource** (1-2 days)
   - Mostly read-only
   - Good introduction to external integration display

4. **Phase 4: SermonResource** (2-3 days)
   - CRUD and metadata management only
   - Uploads remain in existing API pipeline
   - Optional: MediaProcessingLogResource for job monitoring

5. **Phase 5: Dashboard** (1 day)
   - Polish and overview
   - Can be done incrementally

---

## Testing Strategy

### For Each Resource

1. **Feature Tests**
   - Create/Read/Update/Delete operations
   - Filter functionality
   - Search functionality
   - Relationship loading

2. **Example Test Structure**

```php
// tests/Feature/Filament/SermonResourceTest.php

class SermonResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_can_list_sermons(): void
    {
        Sermon::factory()->count(5)->create();

        Livewire::test(ListSermons::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Sermon::all());
    }

    public function test_can_create_sermon(): void
    {
        Livewire::test(CreateSermon::class)
            ->fillForm([
                'title' => 'Test Sermon',
                'date' => now(),
                'service' => SermonService::MORNING,
                'preacher' => 'Test Preacher',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sermons', [
            'title' => 'Test Sermon',
        ]);
    }

    public function test_can_filter_by_service(): void
    {
        Sermon::factory()->morning()->count(3)->create();
        Sermon::factory()->evening()->count(2)->create();

        Livewire::test(ListSermons::class)
            ->filterTable('service', SermonService::MORNING)
            ->assertCanSeeTableRecords(Sermon::where('service', SermonService::MORNING)->get())
            ->assertCanNotSeeTableRecords(Sermon::where('service', SermonService::EVENING)->get());
    }
}
```

---

## Risk Considerations

### High Risk Areas

1. **Sermon File Uploads**
   - Large file handling (videos up to 2GB)
   - Processing integration complexity
   - Storage disk configuration (local vs S3)

   **Mitigation**: Keep uploads in existing API pipeline, use Filament for CRUD only

2. **Google Calendar Integration**
   - OAuth token management
   - Sync timing and conflicts
   - Data consistency

   **Mitigation**: Keep CalendarEvent mostly read-only, sync via existing artisan command

3. **Meeting-Page Relationship**
   - Existing inline editing may have edge cases
   - Orphaned meetings if pages deleted

   **Mitigation**: Add cascade/restrict rules, test thoroughly

### Medium Risk Areas

1. **Media Library Integration**
   - Multiple conversion sizes for images

2. **User Self-Modification**
   - Prevent accidental admin lockout
   - Email domain restrictions

---

## Configuration Notes

### Required Packages (Already Installed)

- `filament/filament` ^3.0
- `filament/spatie-laravel-media-library-plugin`
- `spatie/laravel-medialibrary`

### Environment Variables

```env
# Ensure these are set for full functionality
FILESYSTEM_DISK=public
MEDIA_DISK=public

# For production
DO_SPACES_KEY=
DO_SPACES_SECRET=
DO_SPACES_BUCKET=
DO_SPACES_REGION=
DO_SPACES_ENDPOINT=
```

### Panel Configuration

Update `AdminPanelProvider.php` to include navigation groups:

```php
->navigationGroups([
    NavigationGroup::make()
        ->label('Content')
        ->icon('heroicon-o-document-text'),
    NavigationGroup::make()
        ->label('Calendar')
        ->icon('heroicon-o-calendar'),
    NavigationGroup::make()
        ->label('Administration')
        ->icon('heroicon-o-cog-6-tooth')
        ->collapsed(),
    NavigationGroup::make()
        ->label('System')
        ->icon('heroicon-o-server')
        ->collapsed(),
])
```

---

## Success Criteria

- [ ] All resources accessible via `/admin`
- [ ] Full CRUD operations working
- [ ] Proper filtering and search
- [ ] Media Library integration complete
- [ ] Feature tests passing
- [ ] No PHPStan errors
- [ ] Navigation groups organized
- [ ] Dashboard widgets displaying stats
