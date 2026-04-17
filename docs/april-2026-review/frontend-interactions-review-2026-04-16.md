# Frontend Interaction Review

Date: 2026-04-16

Framework convention calls in this review were cross-checked against current official Livewire 3 and Alpine docs because Laravel Boost MCP and `search-docs` were not available in this session.

## Findings

### 1. [High] The media upload "drop zone" still does not handle dropped files

Refs: `resources/views/livewire/media-upload/form.blade.php:80-130`, `resources/js/livewire/media-upload-controller.js:147-164`

The UI explicitly invites users to "Drop your file here or click to browse", but the `drop` handler only clears `isDragOver`. There is no code path that reads `dataTransfer.files`, assigns the dropped file to the hidden input, or starts a Livewire upload from the drop interaction. In practice this is a no-op interaction wrapped in real drag-over affordances, so the interface promises behavior that the implementation does not deliver.

### 2. [High] `ProcessingLogsViewer` still splits auto-refresh ownership between Livewire and Alpine

Refs: `resources/views/livewire/processing-logs-viewer.blade.php:18-25`, `app/Livewire/ProcessingLogsViewer.php:150-158`, `resources/views/livewire/processing-logs-viewer.blade.php:248-289`

The auto-refresh checkbox both `wire:model.live`s `autoRefresh` and calls `toggleAutoRefresh()`, while `toggleAutoRefresh()` flips the same property again. That makes the final state depend on request ordering instead of one obvious owner. On top of that, the Alpine helper starts a polling interval but never defines a `destroy()` cleanup path, so the interval is free to outlive the rendered component under `wire:navigate` or DOM removal. This is still the clearest example of a small interaction becoming harder to reason about than it needs to be.

### 3. [Medium] The media upload control plane is still page-global instead of instance-scoped

Refs: `resources/js/livewire/media-upload-controller.js:39-45`, `resources/js/livewire/media-upload-controller.js:73-145`, `app/Livewire/MediaUpload/Progress.php:25-28`, `app/Livewire/MediaUpload/Status.php:40-48`, `app/Livewire/MediaUpload/Form.php:197-213`

Cancel and retry behavior is coordinated through generic window events like `media-upload:cancel-upload`, `media-upload:cancel-processing`, and `media-upload:retry-upload`. The Alpine controller listens on `window`, child Livewire components dispatch unscoped events, and the parent form listens with `#[On(...)]` handlers that are also unscoped. That is workable while there is exactly one upload stack on the page, but it creates hidden cross-talk risk the moment a second instance ever appears and makes the boundary between child, parent, and JS helper harder to follow.

### 4. [Medium] Shared switches and form helpers still default to entangled duplicate state

Refs: `resources/views/components/toggle.blade.php:12-31`, `resources/views/livewire/admin/pages/page-form.blade.php:1-27`, `resources/views/livewire/admin/meetings/meeting-form.blade.php:1-12`, `resources/views/livewire/admin/sermons/edit-sermon.blade.php:1-27`

The toggle primitive still creates Alpine state via `$wire.entangle(...)`, and several form views still mirror Livewire properties into Alpine just to derive slugs or clear dependent fields. None of these cases are individually huge, but together they keep spreading the same pattern: Livewire owns the data, Alpine owns a mirrored copy, and the mental model depends on both staying in sync. Livewire 3's lighter `$wire` access patterns make these interactions easier to follow when there is one clear owner.

### 5. [Medium] `x-admin.form-shell` implements save hotkeys by DOM guessing rather than by explicit contract

Refs: `resources/views/components/admin/form-shell.blade.php:6-20`

The shared form shell resolves Cmd/Ctrl+S by querying for `button[wire:click=save]`, then any submit button, then the last button in the actions slot, and simulates a click. That works today, but it means a shared interaction depends on markup order and selector trivia rather than a declared save target. As more forms gain extra action buttons, this gets increasingly brittle and harder to audit.

### 6. [Low] `page_editor.js` looks like orphaned legacy glue that still ships in the main bundle

Refs: `resources/js/app.js:1-5`, `resources/js/page_editor.js:1-51`

The global app bundle still imports `page_editor.js`, but the helper is wired to hard-coded IDs (`markdown-input`, `rendered-content`, `heading-image`, `headingpicture`) that no current Blade template appears to render. It also anchors itself to `DOMContentLoaded`, which is the wrong lifecycle for a `wire:navigate` application. Even if it is mostly inert at runtime, it is frontend standards drift sitting in the shared bundle.

## Open Questions

- Is the old markdown page-editor flow intentionally retired? If yes, `resources/js/page_editor.js` looks ready either for removal or isolation behind a page-specific entry point.
- Is the upload screen guaranteed to remain singleton-only? If not, the current global custom-event names need instance scoping before another upload surface is added.
- Should the logs viewer stop all client-side work when collapsed or navigated away from? The current implementation suggests "yes", but the Alpine side does not guarantee that teardown.

## What Improved Since March

- The admin composition layer is materially better now. `x-admin.page`, `x-admin.list-shell`, `x-admin.filter-bar`, and `x-admin.form-shell` all exist, and screens like `resources/views/livewire/admin/pages/list-pages.blade.php:1-40` and `resources/views/livewire/admin/pages/page-form.blade.php:29-88` are using them instead of rebuilding the whole shell manually.
- The project now has reusable dismissible feedback components. `resources/views/components/alert.blade.php` and `resources/views/components/session-message.blade.php` mean small Alpine-powered feedback interactions are less likely to be reimplemented ad hoc.
- `resources/js/scripture-fums.js:8-48` is aligned with a `wire:navigate` app now. It listens to `livewire:navigated` instead of `DOMContentLoaded`, which is exactly the kind of lifecycle cleanup the March review was pushing toward.
