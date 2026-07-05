# 🪦 Mortician: Investigation of MediaProcessingRequest & Redundant Assets

## 1. Investigation: `App\Http\Requests\MediaProcessingRequest`
**Artefact:** `app/Http/Requests/MediaProcessingRequest.php` (Abstract Class)

**Verdict: ALIVE**

**Evidence:**
While the class itself is `abstract` and has no direct callers, it serves as the vital base class for the entire media processing API surface. It centralizes:
1.  **Authorization:** Uses `MediaProcessingAccess` for defense-in-depth API protection.
2.  **ID Validation:** `assertProcessingIdShape()` enforces a strict UUID format with a 400 Bad Request response, preserving backward compatibility with external uploader tools.

**Sub-classes (all active):**
*   `ProcessMediaRequest` (Handles `POST /api/sermons/{type}`)
*   `MediaStatusRequest` (Handles `GET /api/sermons/processing/{id}/status`)
*   `MediaStreamRequest` (Handles `GET /api/sermons/processing/{id}/stream`)
*   `CancelMediaProcessingRequest` (Handles `DELETE /api/sermons/processing/{id}`)
*   `ConfirmMediaSegmentRequest` (Handles `POST /api/sermons/processing/{id}/confirm`)
*   `RetryMediaProcessingRequest` (Handles `POST /api/sermons/processing/{id}/retry`)

**Recommendation:** **Leave alone.** It is an active and necessary structural component of the API.


## 2. Redundant Podcast Assets
**Artefacts:**
* `public/images/podcast/EveningArtwork.webp` (approx 58 KB)
* `public/images/podcast/MorningArtwork.webp` (approx 58 KB)

**Evidence:**
Project-wide grep for these filenames returns **zero** callers in production code. `config/podcast.php` explicitly uses the `.jpg` versions of these artworks for compatibility with podcast directories (which often require JPEG).

```bash
grep -rn "EveningArtwork.webp" app/ resources/ config/
grep -rn "MorningArtwork.webp" app/ resources/ config/
# Result: 0 matches
```

**Recommendation:** Safe to remove. The `.jpg` versions are correctly configured and used.


## 3. Unused Presenter Method: `PageImagePresenter::headingImageSrcset()`
**Artefact:** `App\Presenters\PageImagePresenter::headingImageSrcset()`

**Evidence:**
Grep for `headingImageSrcset` returns only the method definition and its own unit test. No Blade views use this method; instead, they use `headingImageUrl`, `headingImageMobileUrl`, and `headingImageTabletUrl` or resolve from the `PublicPageReadModel`.

```bash
grep -rn "headingImageSrcset" app/ resources/
# Result:
# app/Presenters/PageImagePresenter.php:27:    public function headingImageSrcset(Page $page): ?string
# tests/Integration/Presenters/PageImagePresenterTest.php:71:        $this->assertNull($this->presenter->headingImageSrcset($page));
```

**Recommendation:** Safe to remove. The application has moved toward explicit mobile/tablet URL properties in its read models and component props.
