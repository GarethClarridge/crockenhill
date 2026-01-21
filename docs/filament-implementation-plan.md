# Filament Page Management Implementation Plan

This document outlines the complete implementation plan for migrating the page management system to Filament v3.

## Overview

**Goal:** Replace the custom page management admin with Filament, providing a modern editing experience while keeping the public-facing site unchanged.

**Scope:**
- New Filament admin panel at `/admin`
- Page resource with rich markdown editing
- Media library integration for images
- Revision history tracking
- Existing public routes remain untouched

**Estimated effort:** 2-3 days

---

## Phase 1: Foundation Setup

### 1.1 Install Filament Core

```bash
# Install Filament panel builder
composer require filament/filament:"^3.0"

# Run the installation command
php artisan filament:install --panels
```

This creates:
- `app/Providers/Filament/AdminPanelProvider.php`
- `app/Filament/` directory structure

### 1.2 Configure Admin Panel

Edit `app/Providers/Filament/AdminPanelProvider.php`:

```php
<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Crockenhill Admin')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

### 1.3 Configure User Model for Filament

Update `app/Models/User.php`:

```php
<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
// ... other imports

class User extends Authenticatable implements FilamentUser
{
    // ... existing code

    /**
     * Determine if the user can access the Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Use existing permission logic - users who can edit pages
        return $this->canEditPages();
    }
}
```

### 1.4 Publish and Configure Assets

```bash
# Publish Filament assets
php artisan filament:assets

# Add to .gitignore if not already present
echo "public/css/filament" >> .gitignore
echo "public/js/filament" >> .gitignore
```

---

## Phase 2: Media Library Integration

### 2.1 Install Spatie Media Library

```bash
# Install the base package
composer require spatie/laravel-medialibrary:"^11.0"

# Install Filament plugin
composer require filament/spatie-laravel-media-library-plugin:"^3.0"

# Publish and run migrations
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan migrate
```

### 2.2 Configure Page Model for Media

Update `app/Models/Page.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
// ... other imports

class Page extends Model implements HasMedia
{
    use InteractsWithMedia;

    // ... existing code

    /**
     * Register media collections for the page.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('headings')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Register media conversions.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('large')
            ->width(2000)
            ->height(1000)
            ->sharpen(10)
            ->format('jpg')
            ->quality(85);

        $this->addMediaConversion('small')
            ->width(300)
            ->height(200)
            ->sharpen(10)
            ->format('jpg')
            ->quality(80);
    }

    /**
     * Get the heading image URL (for backwards compatibility).
     */
    public function getHeadingImageUrlAttribute(): ?string
    {
        $media = $this->getFirstMedia('headings');

        if ($media) {
            return $media->getUrl('large');
        }

        // Fallback to legacy file-based images
        $legacyPath = "/images/headings/large/{$this->slug}.jpg";
        if (file_exists(public_path($legacyPath))) {
            return $legacyPath;
        }

        return null;
    }

    /**
     * Get the small heading image URL (for page cards).
     */
    public function getHeadingImageSmallUrlAttribute(): ?string
    {
        $media = $this->getFirstMedia('headings');

        if ($media) {
            return $media->getUrl('small');
        }

        // Fallback to legacy file-based images
        $legacyPath = "/images/headings/small/{$this->slug}.jpg";
        if (file_exists(public_path($legacyPath))) {
            return $legacyPath;
        }

        return null;
    }
}
```

### 2.3 Configure Media Library Storage

Update `config/media-library.php` (publish first if needed):

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-config"
```

```php
// config/media-library.php
return [
    'disk_name' => env('MEDIA_DISK', 'public'),

    'max_file_size' => 1024 * 1024 * 10, // 10MB

    // ... other config
];
```

---

## Phase 3: Activity Log (Revision History)

### 3.1 Install Spatie Activity Log

```bash
composer require spatie/laravel-activitylog:"^4.0"

php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```

### 3.2 Configure Page Model for Activity Logging

Update `app/Models/Page.php`:

```php
<?php

namespace App\Models;

use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
// ... other imports

class Page extends Model implements HasMedia
{
    use InteractsWithMedia;
    use LogsActivity;

    // ... existing code

    /**
     * Configure activity logging options.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['heading', 'slug', 'markdown', 'body', 'area', 'navigation', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Page {$eventName}");
    }
}
```

### 3.3 Install Filament Activity Log Plugin (Optional)

For a visual activity timeline in the admin:

```bash
composer require pxlrbt/filament-activity-log
```

---

## Phase 4: Create Page Resource

### 4.1 Generate Resource

```bash
php artisan make:filament-resource Page --generate
```

### 4.2 Configure PageResource

Replace `app/Filament/Resources/PageResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Enums\PageArea;
use App\Filament\Resources\PageResource\Pages;
use App\Models\Page;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'heading';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Page Details')
                    ->description('Basic information about the page')
                    ->schema([
                        TextInput::make('heading')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                                if (($get('slug') ?? '') !== Str::slug($old)) {
                                    return;
                                }
                                $set('slug', Str::slug($state));
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->rules(['alpha_dash'])
                            ->helperText('URL-friendly version of the heading'),

                        Select::make('area')
                            ->label('Website Section')
                            ->enum(PageArea::class)
                            ->options(PageArea::class)
                            ->required()
                            ->native(false),

                        Toggle::make('navigation')
                            ->label('Show in navigation menu')
                            ->helperText('Enable to display this page in the site navigation')
                            ->default(false),

                        Textarea::make('description')
                            ->label('SEO Description')
                            ->maxLength(155)
                            ->rows(2)
                            ->helperText(fn (?string $state): string =>
                                'Used in search results and page cards. ' . strlen($state ?? '') . '/155 characters'
                            )
                            ->live(),
                    ])
                    ->columns(2),

                Section::make('Heading Image')
                    ->description('The main image displayed at the top of the page')
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('heading_image')
                            ->collection('headings')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios([
                                '16:9',
                                '4:3',
                                '2:1',
                            ])
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('Recommended: 2000x1000px, max 2MB. Will be automatically resized.'),
                    ])
                    ->collapsible(),

                Section::make('Content')
                    ->description('Page content in Markdown format')
                    ->schema([
                        MarkdownEditor::make('markdown')
                            ->required()
                            ->toolbarButtons([
                                'heading',
                                'bold',
                                'italic',
                                'strike',
                                'link',
                                'bulletList',
                                'orderedList',
                                'blockquote',
                                'codeBlock',
                                'redo',
                                'undo',
                            ])
                            ->fileAttachmentsDisk('public')
                            ->fileAttachmentsDirectory('page-attachments')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('heading_image')
                    ->collection('headings')
                    ->conversion('small')
                    ->circular()
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=No+Image&background=e5e7eb&color=9ca3af'),

                TextColumn::make('heading')
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                TextColumn::make('area')
                    ->badge()
                    ->color(fn (PageArea $state): string => match ($state) {
                        PageArea::CHRIST => 'success',
                        PageArea::CHURCH => 'info',
                        PageArea::COMMUNITY => 'warning',
                        PageArea::MEMBERS => 'danger',
                        PageArea::SERMONS => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('navigation')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('area')
                    ->label('Section')
                    ->options(PageArea::class),

                TernaryFilter::make('navigation')
                    ->label('In Navigation')
                    ->placeholder('All pages')
                    ->trueLabel('In navigation')
                    ->falseLabel('Not in navigation'),
            ])
            ->actions([
                ViewAction::make()
                    ->url(fn (Page $record): string => $record->route())
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-eye')
                    ->label('View'),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No pages yet')
            ->emptyStateDescription('Create your first page to get started.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function getRelations(): array
    {
        return [
            // ActivityLogRelationManager can be added here if using the plugin
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['heading', 'description', 'markdown'];
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        return [
            'Section' => $record->area->label(),
        ];
    }
}
```

### 4.3 Create Custom Page Classes

Create `app/Filament/Resources/PageResource/Pages/CreatePage.php`:

```php
<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Resources\PageResource;
use Filament\Resources\Pages\CreateRecord;
use League\CommonMark\CommonMarkConverter;

class CreatePage extends CreateRecord
{
    protected static string $resource = PageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Convert markdown to HTML body
        $converter = app(CommonMarkConverter::class);
        $data['body'] = (string) $converter->convert($data['markdown'] ?? '');

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
```

Create `app/Filament/Resources/PageResource/Pages/EditPage.php`:

```php
<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Resources\PageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use League\CommonMark\CommonMarkConverter;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view')
                ->label('View Page')
                ->icon('heroicon-o-eye')
                ->url(fn () => $this->record->route())
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Convert markdown to HTML body
        $converter = app(CommonMarkConverter::class);
        $data['body'] = (string) $converter->convert($data['markdown'] ?? '');

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
```

Create `app/Filament/Resources/PageResource/Pages/ListPages.php`:

```php
<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Resources\PageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
```

---

## Phase 5: Migration & Backwards Compatibility

### 5.1 Create Image Migration Command

Create `app/Console/Commands/MigratePageImagesToMediaLibrary.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Page;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MigratePageImagesToMediaLibrary extends Command
{
    protected $signature = 'pages:migrate-images
                            {--dry-run : Show what would be migrated without making changes}';

    protected $description = 'Migrate legacy page heading images to Spatie Media Library';

    public function handle(): int
    {
        $pages = Page::all();
        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->info("Found {$pages->count()} pages to check.");
        $this->newLine();

        foreach ($pages as $page) {
            $largePath = public_path("images/headings/large/{$page->slug}.jpg");

            // Skip if already has media library image
            if ($page->getFirstMedia('headings')) {
                $this->line("  [SKIP] {$page->heading} - Already has media library image");
                $skipped++;
                continue;
            }

            // Skip if no legacy image exists
            if (!File::exists($largePath)) {
                $this->line("  [SKIP] {$page->heading} - No legacy image found");
                $skipped++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->info("  [DRY-RUN] Would migrate: {$page->heading}");
                $migrated++;
                continue;
            }

            try {
                $page->addMedia($largePath)
                    ->preservingOriginal()
                    ->toMediaCollection('headings');

                $this->info("  [OK] Migrated: {$page->heading}");
                $migrated++;
            } catch (\Exception $e) {
                $this->error("  [ERROR] {$page->heading}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Migration complete:");
        $this->line("  Migrated: {$migrated}");
        $this->line("  Skipped: {$skipped}");
        $this->line("  Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

### 5.2 Update Public Views for Backwards Compatibility

Update `resources/views/components/page-card.blade.php` to use new accessor:

```php
{{-- Replace direct image path references with the new accessor --}}
@if ($page->heading_image_small_url)
    <img src="{{ $page->heading_image_small_url }}" alt="{{ $page->heading }}">
@endif
```

Update `resources/views/layouts/page.blade.php`:

```php
{{-- Replace direct image path references --}}
@if ($page->heading_image_url ?? $headingpicture ?? null)
    <img src="{{ $page->heading_image_url ?? $headingpicture }}" alt="{{ $heading }}">
@endif
```

---

## Phase 6: Deprecate Old Admin

### 6.1 Add Redirect from Old Admin Routes

Update `routes/web.php`:

```php
// Redirect old page admin routes to Filament
Route::middleware('auth')->group(function () {
    Route::get('/church/members/pages', fn () => redirect('/admin/pages'));
    Route::get('/church/members/pages/create', fn () => redirect('/admin/pages/create'));
    Route::get('/church/members/pages/{page}/edit', fn (Page $page) => redirect("/admin/pages/{$page->id}/edit"));
});
```

### 6.2 Keep Old Controller for Public Routes

The `PageController::show()` method is still needed for public page viewing. Only the admin CRUD methods become obsolete.

### 6.3 Update Members Home Page

The members dashboard at `resources/views/members/home.blade.php` has links to the old admin routes. Update these to point to the new Filament admin.

**Current code (line 97):**
```html
<x-button link="/church/members/pages">
```

**Updated code:**
```html
<x-button link="/admin/pages">
```

**Full context of the change:**
```php
{{-- Content Section --}}
@can('edit-pages')
<div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
  <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
    <h3 class="text-lg font-medium text-gray-900 flex items-center">
      <x-heroicon-o-document-text class="h-5 w-5 mr-2 text-gray-500" />
      Content
    </h3>
  </div>
  <div class="p-4 space-y-2">
    <x-button link="/admin/pages">
      <div class="flex items-center justify-center">
        <x-heroicon-s-pencil-square class="h-5 w-5 mr-2" />
        Edit pages
      </div>
    </x-button>
  </div>
</div>
@endcan
```

**Note:** The redirects in 6.1 provide a fallback if any other views or bookmarks still reference the old URLs, but updating the link directly is cleaner and avoids an unnecessary redirect hop.

### 6.4 Consider Future Admin Consolidation

Once Filament is working well for Pages, consider migrating other admin sections to Filament for a unified experience:

| Current Location | Future Filament Resource |
|------------------|-------------------------|
| `/church/members/pages` | `/admin/pages` (this plan) |
| `/church/members/meetings` | `/admin/meetings` (future) |
| Sermon upload | `/admin/sermons` (future) |
| Calendar patterns | `/admin/calendar-patterns` (future) |

This would allow you to eventually replace the custom members home dashboard with Filament's built-in dashboard, providing a consistent admin experience.

**Optional: Add link to Filament from members home**

If you want to keep the members home page as an entry point but link to the full Filament panel:

```php
{{-- Admin Panel Link --}}
@can('edit-pages')
<div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
  <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
    <h3 class="text-lg font-medium text-gray-900 flex items-center">
      <x-heroicon-o-cog-6-tooth class="h-5 w-5 mr-2 text-gray-500" />
      Administration
    </h3>
  </div>
  <div class="p-4 space-y-2">
    <x-button link="/admin">
      <div class="flex items-center justify-center">
        <x-heroicon-s-squares-2x2 class="h-5 w-5 mr-2" />
        Open Admin Panel
      </div>
    </x-button>
  </div>
</div>
@endcan
```

---

## Phase 7: Testing

### 7.1 Create Filament Resource Tests

Create `tests/Feature/Filament/PageResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PageResource;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PageResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        // Ensure user can access admin panel
    }

    public function test_can_render_page_list(): void
    {
        $this->actingAs($this->admin);

        $this->get(PageResource::getUrl('index'))
            ->assertSuccessful();
    }

    public function test_can_render_create_page(): void
    {
        $this->actingAs($this->admin);

        $this->get(PageResource::getUrl('create'))
            ->assertSuccessful();
    }

    public function test_can_create_page(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(PageResource\Pages\CreatePage::class)
            ->fillForm([
                'heading' => 'Test Page',
                'slug' => 'test-page',
                'area' => 'church',
                'markdown' => '# Hello World',
                'navigation' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pages', [
            'heading' => 'Test Page',
            'slug' => 'test-page',
        ]);
    }

    public function test_can_render_edit_page(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();

        $this->get(PageResource::getUrl('edit', ['record' => $page]))
            ->assertSuccessful();
    }

    public function test_can_update_page(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();

        Livewire::test(PageResource\Pages\EditPage::class, ['record' => $page->id])
            ->fillForm([
                'heading' => 'Updated Heading',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'heading' => 'Updated Heading',
        ]);
    }

    public function test_can_delete_page(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();

        Livewire::test(PageResource\Pages\EditPage::class, ['record' => $page->id])
            ->callAction('delete');

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }
}
```

### 7.2 Run Tests

```bash
# Run all tests
sail artisan test --parallel

# Run only Filament tests
sail artisan test --filter=Filament
```

---

## Phase 8: Deployment Checklist

### 8.1 Pre-Deployment

- [ ] Run all tests locally
- [ ] Test image migration command with `--dry-run`
- [ ] Verify Filament admin accessible locally
- [ ] Check all page CRUD operations work
- [ ] Verify public pages still display correctly
- [ ] Test with existing page data

### 8.2 Deployment Steps

```bash
# 1. Deploy code
git push origin main

# 2. Run migrations
php artisan migrate

# 3. Publish Filament assets
php artisan filament:assets

# 4. Clear caches
php artisan optimize:clear

# 5. Migrate images (optional - can keep legacy images working)
php artisan pages:migrate-images --dry-run
php artisan pages:migrate-images
```

### 8.3 Post-Deployment

- [ ] Verify admin login works at `/admin`
- [ ] Test creating a new page
- [ ] Test editing an existing page
- [ ] Verify images display correctly
- [ ] Check public pages render properly
- [ ] Confirm old admin URLs redirect correctly

---

## Phase 9: Optional Enhancements

### 9.1 Add Dashboard Widget

Create `app/Filament/Widgets/PagesOverview.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Models\Page;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PagesOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Pages', Page::count())
                ->icon('heroicon-o-document-text'),
            Stat::make('In Navigation', Page::where('navigation', true)->count())
                ->icon('heroicon-o-bars-3'),
            Stat::make('Recently Updated', Page::where('updated_at', '>=', now()->subDays(7))->count())
                ->description('Last 7 days')
                ->icon('heroicon-o-clock'),
        ];
    }
}
```

### 9.2 Add Quick Actions

Add to the dashboard for quick page creation/editing.

### 9.3 Add Sermon Resource

Once Pages work well, create a similar resource for Sermons.

---

## File Summary

### New Files to Create

```
app/
├── Filament/
│   ├── Resources/
│   │   └── PageResource.php
│   │   └── PageResource/
│   │       └── Pages/
│   │           ├── CreatePage.php
│   │           ├── EditPage.php
│   │           └── ListPages.php
│   └── Widgets/
│       └── PagesOverview.php (optional)
├── Console/
│   └── Commands/
│       └── MigratePageImagesToMediaLibrary.php
└── Providers/
    └── Filament/
        └── AdminPanelProvider.php (generated by installer)

tests/
└── Feature/
    └── Filament/
        └── PageResourceTest.php
```

### Files to Modify

```
app/Models/User.php                          # Add FilamentUser interface
app/Models/Page.php                          # Add HasMedia, LogsActivity traits
routes/web.php                               # Add redirects from old admin
config/media-library.php                     # Configure storage (if needed)
resources/views/members/home.blade.php       # Update "Edit pages" link to /admin/pages
resources/views/components/page-card.blade.php  # Use new image accessors
resources/views/layouts/page.blade.php       # Use new image accessors
```

### Files to Deprecate (keep for reference)

```
resources/views/pages/create.blade.php
resources/views/pages/edit.blade.php
resources/views/pages/index.blade.php
resources/js/page_editor.js (Showdown preview code)
```

---

## Summary

This implementation provides:

1. **Modern admin UI** - Professional Filament panel at `/admin`
2. **Better editing** - Markdown editor with toolbar and formatting
3. **Media management** - Spatie Media Library with automatic resizing
4. **Revision history** - Activity logging for all changes
5. **Backwards compatibility** - Legacy images continue working
6. **Searchable** - Global search across all pages
7. **Filterable** - Filter by section, navigation status
8. **Testable** - Livewire test helpers for all operations

The public-facing site remains completely unchanged - only the admin experience is upgraded.
