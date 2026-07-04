# 🪦 Mortician: Possibly dead — Legacy Sermon Update Artefacts & Redundant Heading Assets

## 1. Dead Form Request: `App\Http\Requests\UpdateSermonRequest`

**Artefact:** `app/Http/Requests/UpdateSermonRequest.php`

**Evidence:**
Project-wide grep for `UpdateSermonRequest` in production directories (`app/`, `resources/`, `routes/`, `config/`) returns **zero** callers.

```bash
grep -rnE "UpdateSermonRequest|update-sermon-request|update_sermon_request" app/ routes/ resources/ config/
# Result: app/Http/Requests/UpdateSermonRequest.php:10:class UpdateSermonRequest extends FormRequest
```

**Reality:**
Sermon editing has been refactored into the `App\Livewire\Admin\Sermons\EditSermon` Livewire component, which uses `App\Livewire\Forms\SermonFormData`. Both the Livewire form and this legacy request depend on `Sermon::validationRules()`, but the request class itself is no longer bound to any route or injected into any controller action.

**Risk:**
Low — The class is unreferenced in production code. However, it is still referenced in several test files (`SermonIntegrityTest`, `UpdateSermonRequestTest`, `SermonValidationSecurityTest`) which would need to be retired alongside the class.

**Recommendation:**
Safe to remove once the corresponding tests are also retired or migrated.

---

## 2. Redundant Heading Assets — `.jpg` files in `public/images/headings/`

**Artefact:** `public/images/headings/large/*.jpg`, `public/images/headings/small/*.jpg`, `public/images/headings/links.jpg` (33 files total)

**Evidence:**
1. **No Code References:** A project-wide grep for specific filenames (e.g., `sermons.jpg`, `pastor.jpg`, `sunday-mornings.jpg`) returns no hits in Blade templates, CSS, or JS files.
2. **Service Logic:** `App\Services\Public\PageImageCacheService::resolveHeadingImageUrl` explicitly searches for `.webp` files and does not fall back to `.jpg`.
3. **Presence of Alternatives:** Every `.jpg` file in these directories has a corresponding `.webp` version which is the one actually served.

**Large files found:**
- `public/images/headings/large/sermons.jpg` (approx 744 KB)
- `public/images/headings/large/christmas.jpg` (approx 177 KB)

```bash
grep -rnE "sunday-mornings\.jpg|pastor\.jpg|sermons\.jpg|christmas\.jpg" resources/ public/ app/ config/
# Result: 0 matches in production code.
```

**Risk:**
Low — These appear to be legacy source files or uncompressed versions that were replaced by WebP for performance.

**Recommendation:**
Safe to remove. Retaining them adds nearly 2MB of redundant data to the repository.
