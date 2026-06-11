# 🔗 Pathfinder: Broken Admin Link and Stale Schema References

## 1. Broken Delete Action in Sermon Detail Page

**Artefact:** `resources/views/sermons/sermon.blade.php`

**Finding:**
The "Delete" button in the admin overlay of the public sermon detail page points to a dated URL that does not exist in the routing configuration.

**Evidence:**
Rendered HTML uses:
```html
<form method="POST" action="/christ/sermons/2024/12/the-birth-of-our-saviour/delete">
```
Route defined in `routes/web.php` (line 117):
```php
Route::post('/{sermon:slug}/delete', [SermonAdminController::class, 'destroy'])
    ->middleware(['auth', 'verified', 'admin'])
    ->name('sermons.destroy');
```
The dated prefix (`/{year}/{month}/`) is only supported for GET requests to `showDated`. The POST delete route only supports `/{sermon:slug}/delete`.

**Verification:**
`POST /christ/sermons/2024/12/the-birth-of-our-saviour/delete` returns **404 Not Found**.
`POST /christ/sermons/the-birth-of-our-saviour/delete` returns **302** (redirect to login if unauthenticated), confirming the route exists.

**Risk:**
Low — Only affects administrators using the delete button on the public-facing detail page. The main admin list delete function works correctly.

**Suggested Action:**
Update `resources/views/sermons/sermon.blade.php` to use the non-dated route:
```php
<form method="POST" action="/christ/sermons/{{ $sermon->slug }}/delete">
```
Or better, use the named route:
```php
<form method="POST" action="{{ route('sermons.destroy', $sermon->slug) }}">
```

---

## 2. Broken Fragment Links in Christmas Event Schema

**Artefact:** `resources/views/full-width-pages/christmas.blade.php`

**Finding:**
The JSON-LD structured data defines `@id` fragment links for events (e.g., `#event-preparing-room`), but the corresponding `<h3>` elements in the HTML lack these IDs, making the structured data technically invalid/unlinked to the content.

**Evidence:**
JSON-LD (lines 53-54):
```json
"@id": "http://localhost/christmas#event-preparing-room",
"name": "Preparing Room",
```
HTML (lines 98-100):
```html
<h3 class="font-display text-white mt-8 text-xl lg:text-2xl sm:px-16 lg:px-48">
  Preparing Room
</h3>
```
The `<h3>` tag has no `id` attribute.

**Risk:**
Minor — Affects SEO Rich Results data consistency. Fragment navigation to specific events from external links will also fail.

**Suggested Action:**
Update the `<h3>` tags in the loop or manually to include the matching IDs:
```html
<h3 id="event-preparing-room" class="...">Preparing Room</h3>
```

---

## 3. Redundant Stale Redirect in `config/redirects.php`

**Artefact:** `config/redirects.php`

**Finding:**
The typo redirect `'contacttus' => '/'` is still present at line 27, even though `'contactus' => '/'` is also present at line 26.

**Evidence:**
```php
'contactus' => '/',
'contacttus' => '/',
```

**Risk:**
Negligible — Already documented in a previous Mortician audit.

**Suggested Action:**
Remove the typoed entry if "Pathfinder" or "Mortician" is tasked with cleanup.
