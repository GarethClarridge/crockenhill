# Mary UI + Custom Livewire Admin Plan

This document outlines an alternative approach to the admin interface using Mary UI components and custom Livewire, replacing Filament entirely.

## Why This Approach?

1. **Consistent design** - Admin uses same Tailwind theme as public site
2. **Full control** - No framework abstractions to work around
3. **Lighter weight** - Mary UI is a component library, not a full framework
4. **Existing skills** - You already have sophisticated Livewire components (MediaUpload)
5. **Right-sized** - 5 resources don't need a heavyweight admin panel

## Current State

| Resource | Filament Status | Action |
|----------|-----------------|--------|
| **Pages** | Complete | Replace with Livewire |
| **Meetings** | Complete | Replace with Livewire |
| **Sermons** | Not started | Build with Livewire |
| **Calendar Events** | Not started | Build with Livewire |
| **Users** | Not started | Build with Livewire |

---

## Phase 0: Setup & Infrastructure

### 0.1 Install Mary UI

```bash
composer require robsontenorio/mary
php artisan mary:install
```

Mary UI requires:
- Livewire 3
- Tailwind CSS (already have)
- daisyUI (will be added)

### 0.2 Configure daisyUI Theme

Update `tailwind.config.js` to add daisyUI with a custom theme matching your site:

```js
// tailwind.config.js
module.exports = {
    // ... existing config
    plugins: [
        require('daisyui'),
    ],
    daisyui: {
        themes: [
            {
                crockenhill: {
                    "primary": "#0d9488",      // Teal (matching current Filament)
                    "secondary": "#6366f1",
                    "accent": "#f59e0b",
                    "neutral": "#1f2937",
                    "base-100": "#ffffff",
                    "info": "#3b82f6",
                    "success": "#10b981",
                    "warning": "#f59e0b",
                    "error": "#ef4444",
                },
            },
        ],
    },
}
```

### 0.3 Create Admin Layout

```php
// resources/views/components/layouts/admin.blade.php

<!DOCTYPE html>
<html lang="en" data-theme="crockenhill">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Admin' }} - Crockenhill</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200">
    {{-- Sidebar --}}
    <x-mary-main full-width>
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100">
            <x-mary-menu activate-by-route>
                <x-mary-menu-item title="Dashboard" icon="o-home" link="{{ route('admin.dashboard') }}" />

                <x-mary-menu-sub title="Content" icon="o-document-text">
                    <x-mary-menu-item title="Pages" link="{{ route('admin.pages.index') }}" />
                    <x-mary-menu-item title="Sermons" link="{{ route('admin.sermons.index') }}" />
                </x-mary-menu-sub>

                <x-mary-menu-sub title="Calendar" icon="o-calendar">
                    <x-mary-menu-item title="Meetings" link="{{ route('admin.meetings.index') }}" />
                    <x-mary-menu-item title="Events" link="{{ route('admin.calendar-events.index') }}" />
                </x-mary-menu-sub>

                <x-mary-menu-sub title="System" icon="o-cog-6-tooth">
                    <x-mary-menu-item title="Users" link="{{ route('admin.users.index') }}" />
                </x-mary-menu-sub>

                <x-mary-menu-separator />

                <x-mary-menu-item title="Upload Sermon" icon="o-cloud-arrow-up"
                    link="{{ route('sermon.upload') }}" />
                <x-mary-menu-item title="View Site" icon="o-arrow-top-right-on-square"
                    link="/" external />
            </x-mary-menu>
        </x-slot:sidebar>

        <x-slot:content>
            <x-mary-navbar sticky>
                <x-slot:brand>
                    <x-mary-button label="Menu" icon="o-bars-3" class="lg:hidden"
                        @click="$dispatch('toggle-drawer', 'main-drawer')" />
                    <span class="font-bold text-lg">Crockenhill Admin</span>
                </x-slot:brand>
                <x-slot:actions>
                    <span class="text-sm text-gray-500">{{ auth()->user()->name }}</span>
                    <x-mary-button label="Logout" icon="o-arrow-right-on-rectangle"
                        link="{{ route('logout') }}" class="btn-ghost btn-sm" />
                </x-slot:actions>
            </x-mary-navbar>

            <div class="p-4 lg:p-6">
                {{ $slot }}
            </div>
        </x-slot:content>
    </x-mary-main>

    <x-mary-toast />
</body>
</html>
```

### 0.4 Create Reusable Components

#### Resource Table Component

```php
// app/Livewire/Admin/Components/ResourceTable.php

namespace App\Livewire\Admin\Components;

use Livewire\Component;
use Livewire\WithPagination;

abstract class ResourceTable extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';
    public array $selected = [];

    protected $queryString = ['search', 'sortBy', 'sortDirection'];

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    abstract protected function getQuery();
    abstract protected function getHeaders(): array;

    public function deleteSelected(): void
    {
        $this->getModelClass()::whereIn('id', $this->selected)->delete();
        $this->selected = [];
        $this->dispatch('toast', type: 'success', message: 'Items deleted');
    }
}
```

#### Media Upload Field Component

```php
// app/Livewire/Admin/Components/MediaUploadField.php

namespace App\Livewire\Admin\Components;

use Livewire\Component;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\HasMedia;

class MediaUploadField extends Component
{
    use WithFileUploads;

    public ?HasMedia $model = null;
    public string $collection = 'default';
    public $file;
    public bool $multiple = false;
    public string $accept = 'image/*';
    public int $maxSize = 2048; // KB

    public function updatedFile(): void
    {
        $this->validate([
            'file' => "max:{$this->maxSize}|mimes:jpg,jpeg,png,webp",
        ]);
    }

    public function upload(): void
    {
        if (!$this->file || !$this->model) return;

        $this->model
            ->addMedia($this->file->getRealPath())
            ->usingFileName($this->file->getClientOriginalName())
            ->toMediaCollection($this->collection);

        $this->file = null;
        $this->dispatch('media-uploaded');
    }

    public function remove(int $mediaId): void
    {
        $this->model->media()->find($mediaId)?->delete();
        $this->dispatch('media-removed');
    }

    public function render()
    {
        $existingMedia = $this->model?->getMedia($this->collection) ?? collect();

        return view('livewire.admin.components.media-upload-field', [
            'existingMedia' => $existingMedia,
        ]);
    }
}
```

```blade
{{-- resources/views/livewire/admin/components/media-upload-field.blade.php --}}

<div>
    {{-- Existing media --}}
    @if($existingMedia->isNotEmpty())
        <div class="flex flex-wrap gap-4 mb-4">
            @foreach($existingMedia as $media)
                <div class="relative group">
                    <img src="{{ $media->getUrl('thumbnail') }}"
                         alt=""
                         class="w-24 h-24 object-cover rounded-lg" />
                    <button type="button"
                            wire:click="remove({{ $media->id }})"
                            class="absolute -top-2 -right-2 btn btn-circle btn-error btn-xs opacity-0 group-hover:opacity-100 transition-opacity">
                        <x-mary-icon name="o-x-mark" class="w-3 h-3" />
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Upload area --}}
    <div class="border-2 border-dashed border-base-300 rounded-lg p-6 text-center hover:border-primary transition-colors">
        <input type="file"
               wire:model="file"
               accept="{{ $accept }}"
               @if($multiple) multiple @endif
               class="file-input file-input-bordered w-full max-w-xs" />

        @if($file)
            <div class="mt-4">
                <img src="{{ $file->temporaryUrl() }}" class="w-32 h-32 object-cover rounded-lg mx-auto" />
                <x-mary-button label="Upload" wire:click="upload" class="btn-primary btn-sm mt-2" />
            </div>
        @endif
    </div>

    @error('file')
        <p class="text-error text-sm mt-2">{{ $message }}</p>
    @enderror
</div>
```

### 0.5 Admin Routes

```php
// routes/web.php (add to existing)

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', App\Livewire\Admin\Dashboard::class)->name('dashboard');

    // Pages
    Route::get('/pages', App\Livewire\Admin\Pages\ListPages::class)->name('pages.index');
    Route::get('/pages/create', App\Livewire\Admin\Pages\CreatePage::class)->name('pages.create');
    Route::get('/pages/{page:slug}/edit', App\Livewire\Admin\Pages\EditPage::class)->name('pages.edit');

    // Meetings
    Route::get('/meetings', App\Livewire\Admin\Meetings\ListMeetings::class)->name('meetings.index');
    Route::get('/meetings/create', App\Livewire\Admin\Meetings\CreateMeeting::class)->name('meetings.create');
    Route::get('/meetings/{meeting:slug}/edit', App\Livewire\Admin\Meetings\EditMeeting::class)->name('meetings.edit');

    // Sermons
    Route::get('/sermons', App\Livewire\Admin\Sermons\ListSermons::class)->name('sermons.index');
    Route::get('/sermons/{sermon:slug}/edit', App\Livewire\Admin\Sermons\EditSermon::class)->name('sermons.edit');

    // Calendar Events
    Route::get('/calendar-events', App\Livewire\Admin\CalendarEvents\ListCalendarEvents::class)->name('calendar-events.index');
    Route::get('/calendar-events/{calendarEvent}/edit', App\Livewire\Admin\CalendarEvents\EditCalendarEvent::class)->name('calendar-events.edit');

    // Users
    Route::get('/users', App\Livewire\Admin\Users\ListUsers::class)->name('users.index');
    Route::get('/users/create', App\Livewire\Admin\Users\CreateUser::class)->name('users.create');
    Route::get('/users/{user}/edit', App\Livewire\Admin\Users\EditUser::class)->name('users.edit');
});
```

---

## Phase 1: Pages Resource

### 1.1 ListPages Component

```php
// app/Livewire/Admin/Pages/ListPages.php

namespace App\Livewire\Admin\Pages;

use App\Enums\PageArea;
use App\Models\Page;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class ListPages extends Component
{
    use WithPagination, Toast;

    public string $search = '';
    public ?string $areaFilter = null;
    public ?bool $navigationFilter = null;
    public string $sortBy = 'updated_at';
    public string $sortDirection = 'desc';
    public array $selected = [];

    protected $queryString = ['search', 'areaFilter', 'navigationFilter'];

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function delete(Page $page): void
    {
        $page->delete();
        $this->success('Page deleted');
    }

    public function deleteSelected(): void
    {
        Page::whereIn('id', $this->selected)->delete();
        $this->selected = [];
        $this->success('Pages deleted');
    }

    public function render()
    {
        $pages = Page::query()
            ->when($this->search, fn ($q) => $q->where('heading', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%"))
            ->when($this->areaFilter, fn ($q) => $q->where('area', $this->areaFilter))
            ->when($this->navigationFilter !== null, fn ($q) => $q->where('navigation', $this->navigationFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(15);

        $headers = [
            ['key' => 'image', 'label' => '', 'sortable' => false],
            ['key' => 'heading', 'label' => 'Heading', 'sortable' => true],
            ['key' => 'area', 'label' => 'Area', 'sortable' => true],
            ['key' => 'navigation', 'label' => 'Nav', 'sortable' => true],
            ['key' => 'meeting', 'label' => 'Meeting', 'sortable' => false],
            ['key' => 'updated_at', 'label' => 'Updated', 'sortable' => true],
        ];

        return view('livewire.admin.pages.list-pages', [
            'pages' => $pages,
            'headers' => $headers,
            'areas' => PageArea::cases(),
        ])->layout('components.layouts.admin', ['title' => 'Pages']);
    }
}
```

```blade
{{-- resources/views/livewire/admin/pages/list-pages.blade.php --}}

<div>
    <x-mary-header title="Pages" subtitle="Manage website pages">
        <x-slot:actions>
            <x-mary-button label="Create Page" icon="o-plus" link="{{ route('admin.pages.create') }}" class="btn-primary" />
        </x-slot:actions>
    </x-mary-header>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <x-mary-input placeholder="Search pages..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable class="w-64" />

        <x-mary-select
            placeholder="All Areas"
            wire:model.live="areaFilter"
            :options="collect($areas)->map(fn($a) => ['id' => $a->value, 'name' => $a->label()])"
            class="w-48"
        />

        <x-mary-select
            placeholder="Navigation"
            wire:model.live="navigationFilter"
            :options="[['id' => '1', 'name' => 'In Nav'], ['id' => '0', 'name' => 'Not in Nav']]"
            class="w-40"
        />

        @if(count($selected) > 0)
            <x-mary-button label="Delete Selected ({{ count($selected) }})" icon="o-trash"
                wire:click="deleteSelected" wire:confirm="Delete {{ count($selected) }} pages?"
                class="btn-error btn-sm" />
        @endif
    </div>

    {{-- Table --}}
    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$pages" :sort-by="$sortBy" wire:model="selected" selectable
            @row-click="$wire.goto($event.detail.slug)">

            @scope('cell_image', $page)
                @if($page->hasMedia('heading'))
                    <x-mary-avatar :image="$page->getFirstMediaUrl('heading', 'thumbnail')" class="!w-10 !h-10 !rounded-lg" />
                @else
                    <div class="w-10 h-10 bg-base-200 rounded-lg flex items-center justify-center">
                        <x-mary-icon name="o-photo" class="w-5 h-5 text-base-content/30" />
                    </div>
                @endif
            @endscope

            @scope('cell_heading', $page)
                <div>
                    <p class="font-medium">{{ Str::limit($page->heading, 40) }}</p>
                    <p class="text-sm text-base-content/60">{{ Str::limit($page->description, 50) }}</p>
                </div>
            @endscope

            @scope('cell_area', $page)
                <x-mary-badge :value="$page->area->label()"
                    class="{{ match($page->area) {
                        \App\Enums\PageArea::CHRIST => 'badge-primary',
                        \App\Enums\PageArea::CHURCH => 'badge-secondary',
                        \App\Enums\PageArea::COMMUNITY => 'badge-accent',
                        \App\Enums\PageArea::MEMBERS => 'badge-info',
                        \App\Enums\PageArea::SERMONS => 'badge-warning',
                    } }}" />
            @endscope

            @scope('cell_navigation', $page)
                <x-mary-icon :name="$page->navigation ? 'o-check-circle' : 'o-x-circle'"
                    class="w-5 h-5 {{ $page->navigation ? 'text-success' : 'text-base-content/30' }}" />
            @endscope

            @scope('cell_meeting', $page)
                <x-mary-icon :name="$page->meeting ? 'o-calendar' : ''"
                    class="w-5 h-5 {{ $page->meeting ? 'text-info' : '' }}" />
            @endscope

            @scope('cell_updated_at', $page)
                <span class="text-sm text-base-content/60">{{ $page->updated_at->diffForHumans() }}</span>
            @endscope

            @scope('actions', $page)
                <div class="flex gap-1">
                    <x-mary-button icon="o-eye" link="{{ route('page.show', $page) }}" external class="btn-ghost btn-xs" />
                    <x-mary-button icon="o-pencil" link="{{ route('admin.pages.edit', $page) }}" class="btn-ghost btn-xs" />
                    <x-mary-button icon="o-trash" wire:click="delete({{ $page->id }})"
                        wire:confirm="Delete '{{ $page->heading }}'?" class="btn-ghost btn-xs text-error" />
                </div>
            @endscope
        </x-mary-table>

        <div class="mt-4">
            {{ $pages->links() }}
        </div>
    </x-mary-card>
</div>
```

### 1.2 CreatePage / EditPage Component

```php
// app/Livewire/Admin/Pages/PageForm.php (shared trait)

namespace App\Livewire\Admin\Pages;

use App\Enums\PageArea;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;

trait PageForm
{
    public string $heading = '';
    public string $slug = '';
    public string $area = 'church';
    public bool $navigation = false;
    public string $description = '';
    public string $markdown = '';

    protected function rules(): array
    {
        return [
            'heading' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:pages,slug,' . ($this->page?->id ?? ''),
            'area' => 'required|string',
            'navigation' => 'boolean',
            'description' => 'required|string|max:500',
            'markdown' => 'nullable|string',
        ];
    }

    public function updatedHeading(): void
    {
        if (empty($this->slug) || $this->slug === Str::slug($this->heading)) {
            $this->slug = Str::slug($this->heading);
        }
    }

    protected function convertMarkdown(): string
    {
        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        return $converter->convert($this->markdown)->getContent();
    }

    protected function getAreaOptions(): array
    {
        return collect(PageArea::cases())
            ->map(fn ($area) => ['id' => $area->value, 'name' => $area->label()])
            ->toArray();
    }
}
```

```php
// app/Livewire/Admin/Pages/CreatePage.php

namespace App\Livewire\Admin\Pages;

use App\Models\Page;
use Livewire\Component;
use Mary\Traits\Toast;

class CreatePage extends Component
{
    use Toast, PageForm;

    public ?Page $page = null;

    public function save(): void
    {
        $validated = $this->validate();

        $page = Page::create([
            ...$validated,
            'body' => $this->convertMarkdown(),
        ]);

        $this->success('Page created', redirectTo: route('admin.pages.index'));
    }

    public function render()
    {
        return view('livewire.admin.pages.page-form', [
            'title' => 'Create Page',
            'areas' => $this->getAreaOptions(),
        ])->layout('components.layouts.admin', ['title' => 'Create Page']);
    }
}
```

```php
// app/Livewire/Admin/Pages/EditPage.php

namespace App\Livewire\Admin\Pages;

use App\Models\Page;
use Livewire\Component;
use Mary\Traits\Toast;

class EditPage extends Component
{
    use Toast, PageForm;

    public Page $page;

    public function mount(Page $page): void
    {
        $this->page = $page;
        $this->heading = $page->heading;
        $this->slug = $page->slug;
        $this->area = $page->area->value;
        $this->navigation = $page->navigation;
        $this->description = $page->description;
        $this->markdown = $page->markdown ?? '';
    }

    public function save(): void
    {
        $validated = $this->validate();

        $this->page->update([
            ...$validated,
            'body' => $this->convertMarkdown(),
        ]);

        $this->success('Page updated');
    }

    public function render()
    {
        return view('livewire.admin.pages.page-form', [
            'title' => 'Edit Page',
            'areas' => $this->getAreaOptions(),
        ])->layout('components.layouts.admin', ['title' => 'Edit: ' . $this->page->heading]);
    }
}
```

```blade
{{-- resources/views/livewire/admin/pages/page-form.blade.php --}}

<div>
    <x-mary-header :title="$title">
        <x-slot:actions>
            <x-mary-button label="Cancel" link="{{ route('admin.pages.index') }}" />
            <x-mary-button label="Save" wire:click="save" class="btn-primary" spinner />
        </x-slot:actions>
    </x-mary-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">
            <x-mary-card title="Page Details">
                <div class="space-y-4">
                    <x-mary-input label="Heading" wire:model.live.debounce="heading" required />

                    <x-mary-input label="Slug" wire:model="slug" required
                        hint="URL-friendly identifier (auto-generated from heading)" />

                    <x-mary-textarea label="Description" wire:model="description" rows="3" required
                        hint="{{ 500 - strlen($description) }} characters remaining" />
                </div>
            </x-mary-card>

            <x-mary-card title="Content">
                {{-- Simple textarea for markdown - could enhance with editor later --}}
                <x-mary-textarea
                    label="Content (Markdown)"
                    wire:model="markdown"
                    rows="20"
                    class="font-mono text-sm"
                    hint="Supports Markdown formatting" />
            </x-mary-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-mary-card title="Settings">
                <div class="space-y-4">
                    <x-mary-select label="Area" wire:model="area" :options="$areas" required />

                    <x-mary-toggle label="Show in Navigation" wire:model="navigation" />
                </div>
            </x-mary-card>

            @if(isset($page) && $page->exists)
                <x-mary-card title="Heading Image">
                    <livewire:admin.components.media-upload-field
                        :model="$page"
                        collection="heading"
                        accept="image/jpeg,image/png,image/webp" />
                </x-mary-card>
            @else
                <x-mary-card title="Heading Image">
                    <p class="text-sm text-base-content/60">Save the page first to upload an image.</p>
                </x-mary-card>
            @endif
        </div>
    </div>
</div>
```

---

## Phase 2: Meetings Resource

Similar structure to Pages but with:
- Time pickers for StartTime/EndTime
- Recurring toggle with conditional frequency
- Inline Page creation (simplified - link to create page separately)
- MeetingType enum badge

```php
// app/Livewire/Admin/Meetings/ListMeetings.php

namespace App\Livewire\Admin\Meetings;

use App\Enums\MeetingType;
use App\Models\Meeting;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class ListMeetings extends Component
{
    use WithPagination, Toast;

    public string $search = '';
    public ?string $typeFilter = null;
    public ?bool $recurringFilter = null;
    public string $sortBy = 'updated_at';
    public string $sortDirection = 'desc';

    public function delete(Meeting $meeting): void
    {
        $meeting->delete();
        $this->success('Meeting deleted');
    }

    public function render()
    {
        $meetings = Meeting::query()
            ->with(['page', 'calendarEvents'])
            ->when($this->search, fn ($q) => $q->whereHas('page', fn ($q2) =>
                $q2->where('heading', 'like', "%{$this->search}%"))
                ->orWhere('day', 'like', "%{$this->search}%"))
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->recurringFilter !== null, fn ($q) => $q->where('is_recurring', $this->recurringFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(15);

        return view('livewire.admin.meetings.list-meetings', [
            'meetings' => $meetings,
            'types' => MeetingType::cases(),
        ])->layout('components.layouts.admin', ['title' => 'Meetings']);
    }
}
```

### Meeting Form with Time Pickers

```blade
{{-- Excerpt from meeting form --}}

<div class="grid grid-cols-2 gap-4">
    <x-mary-input type="time" label="Start Time" wire:model="startTime" />
    <x-mary-input type="time" label="End Time" wire:model="endTime" />
</div>

<x-mary-toggle label="Recurring Meeting" wire:model.live="isRecurring" />

@if($isRecurring)
    <x-mary-select
        label="Frequency"
        wire:model="frequency"
        :options="collect(\App\Enums\MeetingFrequency::cases())->map(fn($f) => ['id' => $f->value, 'name' => $f->label()])" />
@endif

<x-mary-datetime label="First Occurrence" wire:model="meetingDate"
    :hint="$isRecurring ? 'Date of first occurrence' : 'Date of meeting'" />
```

---

## Phase 3: Sermons Resource (CRUD Only)

No upload in admin - link to existing MediaUpload component.

```php
// app/Livewire/Admin/Sermons/ListSermons.php

namespace App\Livewire\Admin\Sermons;

use App\Enums\SermonService;
use App\Models\Sermon;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class ListSermons extends Component
{
    use WithPagination, Toast;

    public string $search = '';
    public ?string $serviceFilter = null;
    public ?string $preacherFilter = null;
    public ?string $seriesFilter = null;
    public bool $hasVideoFilter = false;
    public bool $last12Months = true;
    public string $sortBy = 'date';
    public string $sortDirection = 'desc';

    public function delete(Sermon $sermon): void
    {
        $sermon->delete();
        $this->success('Sermon deleted');
    }

    public function render()
    {
        $query = Sermon::query()
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%")
                ->orWhere('preacher', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%"))
            ->when($this->serviceFilter, fn ($q) => $q->where('service', $this->serviceFilter))
            ->when($this->preacherFilter, fn ($q) => $q->where('preacher', $this->preacherFilter))
            ->when($this->seriesFilter, fn ($q) => $q->where('series', $this->seriesFilter))
            ->when($this->hasVideoFilter, fn ($q) => $q->withVideo())
            ->when($this->last12Months, fn ($q) => $q->last12Months())
            ->orderBy($this->sortBy, $this->sortDirection);

        $sermons = $query->paginate(20);

        // Get filter options
        $preachers = Sermon::distinct()->pluck('preacher')->filter();
        $series = Sermon::whereNotNull('series')->distinct()->pluck('series');

        return view('livewire.admin.sermons.list-sermons', [
            'sermons' => $sermons,
            'services' => SermonService::cases(),
            'preachers' => $preachers,
            'seriesList' => $series,
        ])->layout('components.layouts.admin', ['title' => 'Sermons']);
    }
}
```

```blade
{{-- resources/views/livewire/admin/sermons/list-sermons.blade.php --}}

<div>
    <x-mary-header title="Sermons" subtitle="Manage sermon recordings">
        <x-slot:actions>
            <x-mary-button label="Upload Sermon" icon="o-cloud-arrow-up"
                link="{{ route('sermon.upload') }}" class="btn-primary" />
        </x-slot:actions>
    </x-mary-header>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <x-mary-input placeholder="Search..." wire:model.live.debounce="search"
            icon="o-magnifying-glass" clearable class="w-64" />

        <x-mary-select placeholder="Service" wire:model.live="serviceFilter"
            :options="collect($services)->map(fn($s) => ['id' => $s->value, 'name' => $s->label()])"
            class="w-40" />

        <x-mary-select placeholder="Preacher" wire:model.live="preacherFilter"
            :options="$preachers->map(fn($p) => ['id' => $p, 'name' => $p])"
            class="w-48" />

        <x-mary-select placeholder="Series" wire:model.live="seriesFilter"
            :options="$seriesList->map(fn($s) => ['id' => $s, 'name' => $s])"
            class="w-48" />

        <x-mary-toggle label="Has Video" wire:model.live="hasVideoFilter" />
        <x-mary-toggle label="Last 12 Months" wire:model.live="last12Months" />
    </div>

    {{-- Table --}}
    <x-mary-card>
        <x-mary-table :rows="$sermons" striped>
            @scope('cell_thumbnail', $sermon)
                @if($sermon->hasMedia('thumbnails'))
                    <x-mary-avatar :image="$sermon->getFirstMediaUrl('thumbnails', 'thumbnail')" class="!w-12 !h-12 !rounded" />
                @else
                    <div class="w-12 h-12 bg-base-200 rounded flex items-center justify-center">
                        <x-mary-icon name="o-microphone" class="w-5 h-5 text-base-content/30" />
                    </div>
                @endif
            @endscope

            @scope('cell_title', $sermon)
                <div>
                    <p class="font-medium">{{ Str::limit($sermon->title, 50) }}</p>
                    @if($sermon->reference)
                        <p class="text-sm text-base-content/60">{{ $sermon->reference }}</p>
                    @endif
                </div>
            @endscope

            @scope('cell_date', $sermon)
                <span>{{ $sermon->date->format('j M Y') }}</span>
            @endscope

            @scope('cell_service', $sermon)
                <x-mary-badge :value="$sermon->service->label()"
                    class="{{ match($sermon->service) {
                        \App\Enums\SermonService::MORNING => 'badge-primary',
                        \App\Enums\SermonService::EVENING => 'badge-warning',
                        default => 'badge-ghost',
                    } }}" />
            @endscope

            @scope('cell_preacher', $sermon)
                <span class="text-sm">{{ $sermon->preacher }}</span>
            @endscope

            @scope('cell_media', $sermon)
                <div class="flex gap-1">
                    @if($sermon->audio_file_path)
                        <x-mary-icon name="o-musical-note" class="w-4 h-4 text-success" />
                    @endif
                    @if($sermon->video_file_path)
                        <x-mary-icon name="o-video-camera" class="w-4 h-4 text-info" />
                    @endif
                </div>
            @endscope

            @scope('actions', $sermon)
                <div class="flex gap-1">
                    <x-mary-button icon="o-eye" link="{{ route('sermons.show', $sermon) }}" external class="btn-ghost btn-xs" />
                    <x-mary-button icon="o-pencil" link="{{ route('admin.sermons.edit', $sermon) }}" class="btn-ghost btn-xs" />
                    <x-mary-button icon="o-trash" wire:click="delete({{ $sermon->id }})"
                        wire:confirm="Delete this sermon?" class="btn-ghost btn-xs text-error" />
                </div>
            @endscope
        </x-mary-table>

        <div class="mt-4">
            {{ $sermons->links() }}
        </div>
    </x-mary-card>
</div>
```

### Sermon Edit Form

```php
// app/Livewire/Admin/Sermons/EditSermon.php

namespace App\Livewire\Admin\Sermons;

use App\Enums\SermonService;
use App\Models\Sermon;
use Illuminate\Support\Str;
use Livewire\Component;
use Mary\Traits\Toast;

class EditSermon extends Component
{
    use Toast;

    public Sermon $sermon;

    public string $title = '';
    public string $slug = '';
    public string $date = '';
    public string $service = '';
    public string $preacher = '';
    public ?string $reference = null;
    public ?string $series = null;
    public ?string $summary = null;
    public array $points = [];
    public bool $showSummary = true;
    public bool $showPoints = true;

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:sermons,slug,' . $this->sermon->id,
            'date' => 'required|date',
            'service' => 'required|string',
            'preacher' => 'required|string|max:255',
            'reference' => 'nullable|string|max:255',
            'series' => 'nullable|string|max:255',
            'summary' => 'nullable|string',
            'points' => 'array',
            'showSummary' => 'boolean',
            'showPoints' => 'boolean',
        ];
    }

    public function mount(Sermon $sermon): void
    {
        $this->sermon = $sermon;
        $this->title = $sermon->title;
        $this->slug = $sermon->slug;
        $this->date = $sermon->date->format('Y-m-d');
        $this->service = $sermon->service->value;
        $this->preacher = $sermon->preacher;
        $this->reference = $sermon->reference;
        $this->series = $sermon->series;
        $this->summary = $sermon->summary;
        $this->points = $sermon->points ?? [];
        $this->showSummary = $sermon->show_summary;
        $this->showPoints = $sermon->show_points;
    }

    public function updatedTitle(): void
    {
        $this->slug = Str::slug($this->title);
    }

    public function addPoint(): void
    {
        $this->points[] = '';
    }

    public function removePoint(int $index): void
    {
        unset($this->points[$index]);
        $this->points = array_values($this->points);
    }

    public function save(): void
    {
        $validated = $this->validate();

        $this->sermon->update([
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'date' => $validated['date'],
            'service' => $validated['service'],
            'preacher' => $validated['preacher'],
            'reference' => $validated['reference'],
            'series' => $validated['series'],
            'summary' => $validated['summary'],
            'points' => array_filter($this->points),
            'show_summary' => $validated['showSummary'],
            'show_points' => $validated['showPoints'],
        ]);

        $this->success('Sermon updated');
    }

    public function render()
    {
        return view('livewire.admin.sermons.edit-sermon', [
            'services' => SermonService::cases(),
        ])->layout('components.layouts.admin', ['title' => 'Edit: ' . $this->sermon->title]);
    }
}
```

---

## Phase 4: Calendar Events (Read + Categorize)

Mostly read-only, with ability to assign meeting association.

```php
// app/Livewire/Admin/CalendarEvents/ListCalendarEvents.php

namespace App\Livewire\Admin\CalendarEvents;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class ListCalendarEvents extends Component
{
    use WithPagination, Toast;

    public string $search = '';
    public ?string $meetingFilter = null;
    public bool $uncategorizedOnly = false;
    public bool $upcomingOnly = true;

    public function categorize(int $eventId, ?string $meetingSlug): void
    {
        CalendarEvent::find($eventId)?->update([
            'meeting_slug' => $meetingSlug,
            'is_categorized_automatically' => false,
        ]);
        $this->success('Event categorized');
    }

    public function render()
    {
        $events = CalendarEvent::query()
            ->with('meeting.page')
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->when($this->meetingFilter, fn ($q) => $q->where('meeting_slug', $this->meetingFilter))
            ->when($this->uncategorizedOnly, fn ($q) => $q->whereNull('meeting_slug'))
            ->when($this->upcomingOnly, fn ($q) => $q->upcoming())
            ->orderBy('start_datetime', 'desc')
            ->paginate(20);

        $meetings = Meeting::with('page')->get()
            ->mapWithKeys(fn ($m) => [$m->slug => $m->page?->heading ?? $m->slug]);

        return view('livewire.admin.calendar-events.list-calendar-events', [
            'events' => $events,
            'meetings' => $meetings,
        ])->layout('components.layouts.admin', ['title' => 'Calendar Events']);
    }
}
```

---

## Phase 5: Users Resource

```php
// app/Livewire/Admin/Users/ListUsers.php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class ListUsers extends Component
{
    use WithPagination, Toast;

    public string $search = '';
    public ?bool $verifiedFilter = null;
    public ?bool $adminFilter = null;

    public function delete(User $user): void
    {
        if ($user->id === auth()->id()) {
            $this->error('Cannot delete yourself');
            return;
        }

        $user->delete();
        $this->success('User deleted');
    }

    public function toggleAdmin(User $user): void
    {
        if ($user->id === auth()->id()) {
            $this->error('Cannot modify your own admin status');
            return;
        }

        $user->update(['is_admin' => !$user->is_admin]);
        $this->success($user->is_admin ? 'Admin granted' : 'Admin revoked');
    }

    public function render()
    {
        $users = User::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%"))
            ->when($this->verifiedFilter !== null, fn ($q) =>
                $this->verifiedFilter
                    ? $q->whereNotNull('email_verified_at')
                    : $q->whereNull('email_verified_at'))
            ->when($this->adminFilter !== null, fn ($q) => $q->where('is_admin', $this->adminFilter))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('livewire.admin.users.list-users', [
            'users' => $users,
        ])->layout('components.layouts.admin', ['title' => 'Users']);
    }
}
```

---

## Phase 6: Remove Filament

### 6.1 Update Routes

```php
// routes/web.php

// Remove or update old redirects
// Route::permanentRedirect('/church/members/pages', '/admin/pages'); // Remove
// Route::permanentRedirect('/church/members/meetings', '/admin/meetings'); // Remove

// Keep sermon upload route as-is since we're not replacing it
```

### 6.2 Remove Filament Files

```bash
# Remove Filament resources
rm -rf app/Filament/Resources/PageResource.php
rm -rf app/Filament/Resources/PageResource/
rm -rf app/Filament/Resources/MeetingResource.php
rm -rf app/Filament/Resources/MeetingResource/

# Remove Filament provider (or keep if using for something else)
# rm app/Providers/Filament/AdminPanelProvider.php

# Remove from config/app.php providers array if needed
```

### 6.3 Remove Filament Packages (Optional)

```bash
# If completely removing Filament
composer remove filament/filament filament/spatie-laravel-media-library-plugin

# Keep spatie/laravel-medialibrary - we still use it
```

### 6.4 Update Members Dashboard

Update `/church/members` to link to new admin routes:

```blade
{{-- resources/views/members/home.blade.php --}}

{{-- Update links --}}
<x-button href="{{ route('admin.pages.index') }}">Edit Pages</x-button>
<x-button href="{{ route('admin.meetings.index') }}">Manage Meetings</x-button>
<x-button href="{{ route('admin.sermons.index') }}">Manage Sermons</x-button>
```

---

## File Structure Summary

```
app/Livewire/Admin/
├── Dashboard.php
├── Components/
│   ├── ResourceTable.php (abstract base)
│   └── MediaUploadField.php
├── Pages/
│   ├── ListPages.php
│   ├── CreatePage.php
│   ├── EditPage.php
│   └── PageForm.php (trait)
├── Meetings/
│   ├── ListMeetings.php
│   ├── CreateMeeting.php
│   ├── EditMeeting.php
│   └── MeetingForm.php (trait)
├── Sermons/
│   ├── ListSermons.php
│   └── EditSermon.php
├── CalendarEvents/
│   ├── ListCalendarEvents.php
│   └── EditCalendarEvent.php
└── Users/
    ├── ListUsers.php
    ├── CreateUser.php
    └── EditUser.php

resources/views/
├── components/layouts/admin.blade.php
└── livewire/admin/
    ├── dashboard.blade.php
    ├── components/
    │   └── media-upload-field.blade.php
    ├── pages/
    │   ├── list-pages.blade.php
    │   └── page-form.blade.php
    ├── meetings/
    │   ├── list-meetings.blade.php
    │   └── meeting-form.blade.php
    ├── sermons/
    │   ├── list-sermons.blade.php
    │   └── edit-sermon.blade.php
    ├── calendar-events/
    │   ├── list-calendar-events.blade.php
    │   └── edit-calendar-event.blade.php
    └── users/
        ├── list-users.blade.php
        └── user-form.blade.php
```

---

## Implementation Order

1. **Phase 0: Setup** (1 day)
   - Install Mary UI
   - Configure daisyUI theme
   - Create admin layout
   - Create reusable components

2. **Phase 1: Pages** (1-2 days)
   - List, Create, Edit components
   - Media upload integration
   - Test thoroughly

3. **Phase 2: Meetings** (1-2 days)
   - Similar to Pages
   - Time picker handling
   - Recurring logic

4. **Phase 3: Sermons** (1 day)
   - List with filters
   - Edit form (no upload)
   - Link to existing upload

5. **Phase 4: Calendar Events** (0.5 days)
   - List with categorization
   - Meeting assignment

6. **Phase 5: Users** (0.5 days)
   - List with admin toggle
   - Create/Edit forms

7. **Phase 6: Remove Filament** (0.5 days)
   - Remove files
   - Update routes
   - Test everything

**Total: ~7-8 days**

---

## Comparison: Filament vs Mary UI

| Aspect | Filament | Mary UI + Livewire |
|--------|----------|-------------------|
| Setup complexity | Low | Medium |
| Customization | Limited | Full |
| Design consistency | Separate theme | Matches site |
| Learning curve | Filament concepts | Just Livewire |
| Bundle size | Larger | Smaller |
| Features out of box | Many | Build what you need |
| Long-term maintenance | Follow Filament updates | Your code, your pace |
| Image editor | Built-in | Need separate solution |
| Global search | Built-in | Build if needed |
| Bulk actions | Built-in | ~20 lines to add |

---

## What You Lose

1. **Image editor** - Crop/rotate in browser (use external tool or skip)
2. **Global search** - Could add later with simple Livewire component
3. **Automatic form validation display** - Mary UI handles this well though
4. **Resource auto-discovery** - Must register routes manually

## What You Gain

1. **Single design system** - Admin looks like your site
2. **Full control** - No framework constraints
3. **Simpler mental model** - Just Livewire, no Filament concepts
4. **Smaller bundle** - No Filament JS/CSS
5. **Easier debugging** - Your code, not framework code
