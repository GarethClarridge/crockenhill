# Admin Design Consistency Plan

## Core Principle: Reuse Existing Components

Rather than creating admin-specific versions of components, **use the existing site components directly**:

| Need | Solution |
|------|----------|
| Layout | Use `layouts/main.blade.php` |
| Header | Use `<x-layout.header>` with admin nav |
| Cards | Use `<x-card>` |
| Buttons | Use `<x-button>` and `<x-form-button>` |

---

## Phase 1: Use the Main Site Layout

### 1.1 Switch Admin to Extend Main Layout

**Current**: Custom Mary UI layout (`components/layouts/admin.blade.php`) with sidebar
**Target**: Extend `layouts/main.blade.php` like every other page

Create a simple admin layout that extends main:

**File**: `resources/views/layouts/admin.blade.php`

```blade
@extends('layouts.main')

@section('title')
{{ $title ?? 'Admin' }}
@stop

@section('content')
<main class="mb-3">
    <x-page-header heading="{{ $heading ?? 'Admin' }}" />

    <x-content-wrapper>
        {{ $slot }}
    </x-content-wrapper>
</main>
@stop
```

### 1.2 Add Admin Navigation to Existing Header

Modify `<x-layout.header>` to show admin navigation when in admin section:

```blade
{{-- In resources/views/components/layout/header.blade.php --}}

@if(request()->is('admin/*') || request()->is('admin'))
    {{-- Admin navigation items --}}
    <li><a class="px-8 py-2 flex items-center" href="{{ route('admin.pages.index') }}" wire:navigate>
        <x-heroicon-o-document-text class="h-5 w-5 mr-1" />Pages
    </a></li>
    <li><a class="px-8 py-2 flex items-center" href="{{ route('admin.sermons.index') }}" wire:navigate>
        <x-heroicon-o-microphone class="h-5 w-5 mr-1" />Sermons
    </a></li>
    <li><a class="px-8 py-2 flex items-center" href="{{ route('admin.meetings.index') }}" wire:navigate>
        <x-heroicon-o-calendar class="h-5 w-5 mr-1" />Meetings
    </a></li>
    <li><a class="px-8 py-2 flex items-center" href="{{ route('admin.users.index') }}" wire:navigate>
        <x-heroicon-o-users class="h-5 w-5 mr-1" />Users
    </a></li>
@else
    {{-- Normal site navigation (Christ, Church, Community) --}}
    ...existing code...
@endif
```

This gives you the **exact same header** with just different navigation items.

---

## Phase 2: Use Existing Card Component

### Replace `<x-mary-card>` with `<x-card>`

**Before**:
```blade
<x-mary-card title="Page Details">
    ...content...
</x-mary-card>
```

**After**:
```blade
<x-card heading="Page Details">
    ...content...
</x-card>
```

The existing `<x-card>` already provides:
- White background with gray border
- Shadow and rounded corners
- Oswald font heading (`font-display`)
- Prose styling for content

---

## Phase 3: Use Existing Button Components

### 3.1 Replace `<x-mary-button>` Links with `<x-button>`

**Before**:
```blade
<x-mary-button label="Create Page" icon="o-plus" link="{{ route('admin.pages.create') }}" class="btn-primary" />
```

**After**:
```blade
<x-button link="{{ route('admin.pages.create') }}" variant="primary">
    Create Page
</x-button>
```

### 3.2 Replace `<x-mary-button>` Form Actions with `<x-form-button>`

**Before**:
```blade
<x-mary-button label="Save" wire:click="save" class="btn-primary" spinner />
```

**After**:
```blade
<x-form-button variant="primary" wire:click="save">
    Save
</x-form-button>
```

### 3.3 Minor Enhancements Needed

The existing buttons may need small additions for admin use:

1. **Icon support** - Add optional icon slot/prop
2. **Smaller sizes** - Add `xs` and `sm` sizes for table actions

```blade
{{-- Enhanced button.blade.php --}}
@props(['link', 'variant' => 'default', 'size' => 'md', 'icon' => null])

@php
$sizeClasses = [
    'xs' => 'px-2 py-1 text-xs',
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'p-4',
];
@endphp
```

---

## Phase 4: Keep Mary UI for Complex Components

Some Mary UI components are worth keeping - building equivalents is complex:

### Keep
| Component | Reason |
|-----------|--------|
| `<x-mary-table>` | Sorting, selection, scoped slots |
| `<x-mary-input>` | Icons, clearable, validation |
| `<x-mary-select>` | Searchable, multiple selection |
| `<x-mary-textarea>` | Auto-resize, character count |
| `<x-mary-toggle>` | Styled checkbox |
| `<x-mary-toast>` | Toast notifications |

### Replace
| Mary UI | Use Instead |
|---------|-------------|
| `<x-mary-card>` | `<x-card>` |
| `<x-mary-button>` | `<x-button>` / `<x-form-button>` |
| `<x-mary-header>` | Simple HTML with `font-display` |
| `<x-mary-stat>` | Custom with `<x-card>` |
| `<x-mary-badge>` | Simple span with Tailwind |
| `<x-mary-icon>` | `<x-heroicon-o-*>` |

---

## Phase 5: Replace Mary UI Icons with Heroicons

**Before**:
```blade
<x-mary-icon name="o-document-text" class="w-5 h-5" />
```

**After**:
```blade
<x-heroicon-o-document-text class="w-5 h-5" />
```

---

## Implementation Checklist

### Files to Modify

**Layout**:
- [ ] Create `resources/views/layouts/admin.blade.php` (extends main)
- [ ] Update `resources/views/components/layout/header.blade.php` (add admin nav detection)
- [ ] Delete `resources/views/components/layouts/admin.blade.php` (Mary UI version)

**Existing Components** (minor enhancements):
- [ ] `resources/views/components/button.blade.php` - Add icon support, sizes
- [ ] `resources/views/components/form-button.blade.php` - Add icon support, sizes

**Admin Views** (replace Mary UI components):
- [ ] `resources/views/livewire/admin/dashboard.blade.php`
- [ ] `resources/views/livewire/admin/pages/list-pages.blade.php`
- [ ] `resources/views/livewire/admin/pages/page-form.blade.php`
- [ ] `resources/views/livewire/admin/sermons/list-sermons.blade.php`
- [ ] `resources/views/livewire/admin/sermons/edit-sermon.blade.php`
- [ ] `resources/views/livewire/admin/meetings/list-meetings.blade.php`
- [ ] `resources/views/livewire/admin/meetings/meeting-form.blade.php`
- [ ] `resources/views/livewire/admin/users/list-users.blade.php`
- [ ] `resources/views/livewire/admin/calendar-events/list-calendar-events.blade.php`

**Livewire Components** (update layout reference):
- [ ] All `app/Livewire/Admin/*.php` files - change layout to `layouts.admin`

---

## Example: Converted List Page

**Before** (Mary UI):
```blade
<div>
    <x-mary-header title="Pages" subtitle="Manage website pages">
        <x-slot:actions>
            <x-mary-button label="Create Page" icon="o-plus"
                link="{{ route('admin.pages.create') }}" class="btn-primary" />
        </x-slot:actions>
    </x-mary-header>

    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$pages">
            @scope('actions', $page)
                <x-mary-button icon="o-pencil" class="btn-ghost btn-xs" />
            @endscope
        </x-mary-table>
    </x-mary-card>
</div>
```

**After** (Using Existing Components):
```blade
<div>
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="font-display text-3xl">Pages</h1>
            <p class="text-gray-600">Manage website pages</p>
        </div>
        <x-button link="{{ route('admin.pages.create') }}" variant="primary">
            Create Page
        </x-button>
    </div>

    <x-card>
        <x-mary-table :headers="$headers" :rows="$pages">
            @scope('actions', $page)
                <x-form-button variant="outline" size="xs">
                    <x-heroicon-o-pencil class="w-4 h-4" />
                </x-form-button>
            @endscope
        </x-mary-table>
    </x-card>
</div>
```

---

## Benefits

1. **Zero new components** - Reuse what exists
2. **Single source of truth** - One card, one button, one header
3. **Automatic consistency** - Site design changes automatically apply to admin
4. **Less code to maintain** - No admin-specific duplicates
5. **Familiar to users** - Same look everywhere
