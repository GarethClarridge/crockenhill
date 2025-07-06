# Bootstrap to Tailwind Migration Implementation Plan

## Overview
This plan outlines the systematic removal of remaining Bootstrap code and completion of the migration to Tailwind CSS. The migration is nearly complete, with only a few specific Bootstrap classes remaining.

## Phase 1: Create Reusable Components (Priority: High)

### 1.1 Extend Existing Button Component
Extend the existing button component to support additional variants while maintaining backward compatibility.

**File: `resources/views/components/button.blade.php`**
```blade
@props(['link', 'variant' => 'default'])

@php
$baseClasses = 'no-underline mx-auto block max-w-md p-4 text-center text-white rounded-md focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all';
$variantClasses = [
    'default' => 'bg-cbc-pattern bg-cover',
    'primary' => 'bg-green-500 hover:bg-green-600',
    'secondary' => 'bg-gray-500 hover:bg-gray-600',
    'outline' => 'border border-gray-300 bg-white hover:bg-gray-50 text-gray-700',
    'danger' => 'bg-red-600 hover:bg-red-700'
];
$classes = $baseClasses . ' ' . $variantClasses[$variant];
@endphp

<div>
  <a class="{{ $classes }}" href="{{ $link }}">
    {{ $slot }}
  </a>
</div>
```

### 1.2 Create Form Button Component
Create a separate component for form buttons that don't use the link pattern.

**File: `resources/views/components/form-button.blade.php`**
```blade
@props(['variant' => 'primary', 'size' => 'md', 'type' => 'submit'])

@php
$baseClasses = 'inline-flex items-center justify-center font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors duration-200';
$sizeClasses = [
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2 text-base',
    'lg' => 'px-6 py-3 text-lg',
    'xl' => 'px-8 py-4 text-xl'
];
$variantClasses = [
    'primary' => 'bg-green-500 hover:bg-green-600 focus:ring-green-500 text-white',
    'secondary' => 'bg-gray-500 hover:bg-gray-600 focus:ring-gray-500 text-white',
    'outline' => 'border border-gray-300 bg-white hover:bg-gray-50 focus:ring-indigo-500 text-gray-700',
    'danger' => 'bg-red-600 hover:bg-red-700 focus:ring-red-500 text-white'
];
$classes = $baseClasses . ' ' . $sizeClasses[$size] . ' ' . $variantClasses[$variant];
@endphp

<button {{ $attributes->merge(['class' => $classes, 'type' => $type]) }}>
    {{ $slot }}
</button>
```

### 1.2 Create Form Components
Create reusable form components for consistent styling.

**File: `resources/views/components/form/input.blade.php`**
```blade
@props(['label', 'name', 'type' => 'text', 'required' => false])

<div class="mb-4">
    @if($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-gray-700 mb-1">
            {{ $label }}
            @if($required)
                <span class="text-red-500">*</span>
            @endif
        </label>
    @endif
    
    <input 
        type="{{ $type }}" 
        name="{{ $name }}" 
        id="{{ $name }}"
        {{ $attributes->merge(['class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50']) }}
    >
</div>
```

## Phase 2: Update Page Templates (Priority: High)

### 2.1 Update Pages Edit Template
**File: `resources/views/pages/edit.blade.php`**

**Changes needed:**
- Replace `d-grid gap-2 mb-3` with `grid gap-2 mb-3`
- Replace `btn-outline` with proper Tailwind classes
- Replace `ps-4` with `pl-4`
- Replace `ms-2` with `ml-2`

**Updated code:**
```blade
<!-- Line 114: Replace d-grid -->
<div class="grid gap-2 mb-3">
    <x-form-button variant="primary" size="xl">Save</x-form-button>
</div>

<!-- Line 118: Replace btn-outline -->
<div class="text-center">
    <x-button link="/church/members/pages/" variant="outline">Cancel</x-button>
</div>

<!-- Lines 54, 66: Replace ps-4 with pl-4 -->
<div class="h-full flex items-center pl-4 bg-gray-300 rounded">

<!-- Lines 58, 68: Replace ms-2 with ml-2 -->
<label for="navigation-radio-1" class="w-full py-4 ml-2 text-sm font-medium text-gray-900">
```

### 2.2 Update Pages Create Template
**File: `resources/views/pages/create.blade.php`**

**Changes needed:**
- Replace `d-grid gap-2 mb-3` with `grid gap-2 mb-3`
- Replace `ps-4` with `pl-4`
- Replace `ms-2` with `ml-2`

**Updated code:**
```blade
<!-- Line 79: Replace d-grid -->
<div class="grid gap-2 mb-3">
    <x-form-button variant="primary" size="xl">Save</x-form-button>
</div>

<!-- Lines 38, 44: Replace ps-4 with pl-4 -->
<div class="h-full flex items-center pl-4 bg-gray-300 rounded">

<!-- Lines 40, 46: Replace ms-2 with ml-2 -->
<label for="navigation-radio-1" class="w-full py-4 ml-2 text-sm font-medium text-gray-900">
```

### 2.3 Update Songs Service Record Template
**File: `resources/views/songs/service-record.blade.php`**

**Changes needed:**
- Replace `form-horizontal` with `space-y-4`
- Replace `control-label` with proper Tailwind classes
- Replace `d-grid gap-2 m-6` with `grid gap-2 m-6`

**Updated code:**
```blade
<!-- Line 20: Replace form-horizontal -->
<form action="/church/members/songs/service-record" method="post" class="space-y-4">

<!-- Lines 24, 36: Replace control-label -->
<label for="date" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
<label for="{{$key.$i}}" class="block text-sm font-medium text-gray-700 mb-1">{{$i}}</label>

<!-- Line 56: Replace d-grid -->
<div class="grid gap-2 m-6">
    <x-form-button variant="primary" size="xl">Save</x-form-button>
</div>
```

## Phase 3: Update Layout Components (Priority: Medium)

### 3.1 Update Footer Component
**File: `resources/views/components/layout/footer.blade.php`**

**Changes needed:**
- Replace `row-cols-1 row-cols-md-3 g-1` with `grid grid-cols-1 md:grid-cols-3 gap-1`

**Updated code:**
```blade
<!-- Line 2: Replace Bootstrap grid classes -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-1 my-5">
```

## Phase 4: Update Error Pages (Priority: Medium)

### 4.1 Update Error Pages
**Files: `resources/views/errors/404.blade.php`, `resources/views/errors/500.blade.php`, `resources/views/errors/503.blade.php`**

**Changes needed:**
- Replace `d-grid gap-2 m-6` with `grid gap-2 m-6`

**Updated code:**
```blade
<!-- Replace d-grid in all error pages -->
<div class="grid gap-2 m-6">
    <x-button link="/" variant="primary">Go to the homepage</x-button>
</div>
```

## Phase 5: Testing and Validation (Priority: High)

### 5.1 Visual Testing
- [ ] Test all updated pages in browser
- [ ] Verify responsive behavior
- [ ] Check form functionality
- [ ] Validate button interactions

### 5.2 Cross-browser Testing
- [ ] Test in Chrome, Firefox, Safari, Edge
- [ ] Verify mobile responsiveness
- [ ] Check accessibility features

### 5.3 Performance Testing
- [ ] Verify CSS bundle size
- [ ] Check for any unused CSS classes
- [ ] Validate Tailwind purge is working correctly

## Phase 6: Cleanup and Documentation (Priority: Low)

### 6.1 Remove Unused CSS
- [ ] Run Tailwind purge to remove unused classes
- [ ] Verify no Bootstrap CSS is being loaded
- [ ] Check for any remaining Bootstrap references

### 6.2 Update Documentation
- [ ] Update component documentation
- [ ] Document new button variants
- [ ] Update style guide

## Implementation Timeline

**Week 1:**
- Extend existing button component and create form-button component
- Create form input components
- Update pages/edit.blade.php
- Update pages/create.blade.php

**Week 2:**
- Update songs/service-record.blade.php
- Update footer component
- Update error pages

**Week 3:**
- Testing and validation
- Bug fixes and refinements
- Performance optimization

**Week 4:**
- Documentation updates
- Final cleanup
- Deployment

## Risk Mitigation

1. **Backup Strategy**: Create git branches for each phase
2. **Rollback Plan**: Keep Bootstrap classes commented out initially
3. **Testing Strategy**: Test each component individually before integration
4. **Performance Monitoring**: Monitor CSS bundle size throughout migration

## Success Criteria

- [ ] No Bootstrap classes remain in the codebase
- [ ] All forms maintain their functionality
- [ ] All buttons have consistent styling
- [ ] Responsive design works correctly
- [ ] CSS bundle size is optimized
- [ ] No visual regressions

## Files Requiring Updates

### High Priority
1. `resources/views/pages/edit.blade.php` - Lines 114, 118, 54, 66, 58, 68
2. `resources/views/pages/create.blade.php` - Lines 79, 38, 44, 40, 46
3. `resources/views/songs/service-record.blade.php` - Lines 20, 24, 36, 56

### Medium Priority
4. `resources/views/components/layout/footer.blade.php` - Line 2
5. `resources/views/errors/404.blade.php` - Line 22
6. `resources/views/errors/500.blade.php` - Line 22
7. `resources/views/errors/503.blade.php` - Line 22

### New Components to Create
8. `resources/views/components/form-button.blade.php`
9. `resources/views/components/form/input.blade.php`

## Bootstrap Classes to Replace

### Grid Classes
- `d-grid gap-2` → `grid gap-2`
- `row-cols-1 row-cols-md-3 g-1` → `grid grid-cols-1 md:grid-cols-3 gap-1`

### Spacing Classes
- `ps-4` → `pl-4` (padding-start → padding-left)
- `ms-2` → `ml-2` (margin-start → margin-left)
- `pe-` → `pr-` (padding-end → padding-right)
- `me-` → `mr-` (margin-end → margin-right)

### Form Classes
- `form-horizontal` → `space-y-4`
- `control-label` → `block text-sm font-medium text-gray-700 mb-1`

### Button Classes
- `btn-outline` → Use `<x-button link="..." variant="outline">` for links
- `btn-save` → Use `<x-form-button variant="primary">` for form submissions

This plan ensures a systematic approach to completing the Bootstrap to Tailwind migration while maintaining functionality and visual consistency throughout the application. 