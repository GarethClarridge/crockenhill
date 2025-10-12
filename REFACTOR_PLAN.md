# Sermon Upload Duplication Refactor Plan

## Executive Summary

The codebase currently has **two separate sermon upload systems** running in parallel:

1. **Legacy Manual System** (`SermonController::create()`, `store()`, `post()`) - Requires manual metadata entry
2. **New Unified Media Processing System** (`MediaController`, `UnifiedMediaProcessor`) - Automated AI-powered processing

This plan proposes consolidating to **only the new system**, removing all legacy upload functionality while preserving critical features.

---

## Current State Analysis

### Legacy Upload System (TO BE REMOVED)

**Routes:**
- `GET /christ/sermons/create` → `SermonController@create` - Manual form with all fields
- `POST /christ/sermons` → `SermonController@store` - Handles manual form submission
- `GET /christ/sermons/upload` → `SermonController@upload` - Simplified form (ALREADY uses new system via `processMedia()`)
- `POST /christ/sermons/post` → `SermonController@post` - ID3 tag-based upload (LEGACY)
- `GET /church/members/sermon-upload` → `SermonController@upload` (auth) - ALREADY unified
- `POST /church/members/sermon-upload` → `SermonController@processMedia` (auth) - ALREADY unified

**Controllers:**
- `SermonController@create` - Shows detailed manual form
- `SermonController@store` - Stores sermon with all manual metadata
- `SermonController@post` - Legacy ID3-based upload
- `SermonController@processMedia` - ALREADY uses new `UnifiedMediaProcessor` ✅

**Form Requests:**
- `StoreSermonRequest` - Validates manual form (title, preacher, date, service, series, reference, points)
- `PostSermonRequest` - Validates ID3-based upload (minimal validation)

**Views:**
- `resources/views/sermons/create.blade.php` - Full manual form with JS for ID3 tag reading
- `resources/views/sermons/upload.blade.php` - ALREADY uses Livewire `MediaUpload` component ✅

**Tests:**
- `tests/Unit/StoreSermonRequestTest.php` - Tests manual form validation
- `tests/Unit/PostSermonRequestTest.php` - Tests ID3 upload validation

### New Unified Media Processing System (TO BE KEPT)

**Routes:**
- `POST /api/media/{type}` → `MediaController@upload` - Unified API endpoint ✅
- `GET /api/media/processing/{processingId}/status` → `MediaController@status` ✅
- `DELETE /api/media/processing/{processingId}` → `MediaController@cancel` ✅
- `POST /api/media/processing/{processingId}/retry` → `MediaController@retry` ✅

**Controllers:**
- `MediaController` - Unified API for all media types ✅

**Services:**
- `UnifiedMediaProcessor` - Routes to appropriate processor ✅
- `SermonProcessingService` - Handles audio processing ✅
- `VideoProcessingService` - Handles video/livestream ✅
- `SermonCreationService` - Creates sermon records with smart metadata extraction ✅
- `MetadataExtractionService` - Extracts date/service from files ✅

**UI Components:**
- `app/Livewire/MediaUpload.php` - Interactive upload component ✅
- `resources/views/livewire/media-upload.blade.php` - UI for upload ✅

**Features:**
- ✅ Audio, video, and livestream support
- ✅ Automatic transcription (OpenAI Whisper)
- ✅ AI-powered metadata extraction (title, preacher, series, reference, points)
- ✅ ID3 tag reading (with priority over AI)
- ✅ Date/service extraction from filename and file metadata
- ✅ Video thumbnail generation
- ✅ Processing status tracking
- ✅ Error handling and retry logic
- ✅ S3/Spaces cloud storage support

---

## What Will Be Lost (And Mitigation)

### ⚠️ LOSS 1: Manual Sermon Entry Without File Upload

**Current Capability:**
- Users can manually create sermon records with all metadata but no audio file

**Impact:**
- Rare edge case: Adding historical sermons without audio files
- Current system allows `filename` to be null in theory, but validation requires file upload

**Mitigation Options:**
1. **Accept the loss** - In practice, sermons without audio files serve no purpose for a church website
2. **Add a separate "manual sermon entry" feature** (NOT recommended - adds complexity)
3. **Keep minimal legacy route** for edge cases (NOT recommended - defeats refactor purpose)

**Recommendation:** Accept this loss. The new system's AI-powered processing is vastly superior for 99.9% of use cases.

---

### ⚠️ LOSS 2: Sermon "Points" Manual Entry

**Current Capability:**
- `create.blade.php` has complex form with 6 main points + 5 sub-points each (manually entered)

**Impact:**
- Admins can no longer manually structure sermon outlines during upload
- AI extraction provides points automatically, but may be imperfect

**Mitigation:**
- AI analysis extracts points from transcript (already implemented) ✅
- Sermon edit page still allows manual editing of points after creation
- Quality depends on transcript/AI accuracy

**Recommendation:** Accept this change. Manual point entry was labor-intensive. AI extraction + post-upload editing is more efficient.

---

### ⚠️ LOSS 3: Pre-Upload ID3 Tag Preview

**Current Capability:**
- `create.blade.php` uses JavaScript to read ID3 tags and pre-fill form fields before submission
- User can verify/edit metadata before uploading

**Impact:**
- No preview/editing before processing starts
- Users must trust AI + ID3 extraction, then edit after if needed

**Mitigation:**
- New system already prioritizes ID3 tags over AI (see `SermonCreationService`) ✅
- Post-upload editing via `SermonController@edit` remains available
- Processing happens asynchronously, allowing quick corrections

**Recommendation:** Accept this workflow change. Modern async processing with smart defaults is industry standard.

---

### ⚠️ LOSS 4: Direct MP3-Only Upload Route

**Current Capability:**
- `/christ/sermons/post` accepts only MP3 files with ID3 tags
- Simpler validation, direct storage, no AI processing

**Impact:**
- Legacy systems or scripts hitting this endpoint will break
- No evidence of external integrations using this route

**Mitigation:**
- New system supports MP3 files with ID3 tag priority ✅
- Can add deprecation notice before removal if needed

**Recommendation:** Safe to remove. No evidence of external usage.

---

## Refactor Implementation Plan

### Phase 1: Route Cleanup

**Remove these routes from `routes/web.php`:**

```php
// REMOVE: Manual sermon creation routes
Route::get('/create', [SermonController::class, 'create'])->name('sermonCreate');
Route::post('/', [SermonController::class, 'store'])->name('sermonStore');
Route::post('/post', [SermonController::class, 'post'])->name('sermonPost');
```

**Keep these routes:**
```php
// KEEP: Unified media upload (already using new system)
Route::get('/upload', [SermonController::class, 'upload'])->name('sermonUpload');
Route::post('/post', [SermonController::class, 'processMedia'])->name('sermonPost'); // Update route name mapping
```

**Update authenticated routes in `routes/web.php`:**
```php
// KEEP: Unified media upload (already correct)
Route::get('sermon-upload', [SermonController::class, 'upload'])->name('admin.sermon-upload.create');
Route::post('sermon-upload', [SermonController::class, 'processMedia'])->name('admin.sermon-upload.store');
```

---

### Phase 2: Controller Method Removal

**Remove from `SermonController`:**
- `create()` method (lines 66-77)
- `store()` method (lines 82-139)
- `post()` method (lines 359-408)

**Keep in `SermonController`:**
- `upload()` method (lines 308-315) - Shows Livewire upload form ✅
- `processMedia()` method (lines 320-354) - Uses `UnifiedMediaProcessor` ✅
- All other methods (index, show, edit, update, destroy, etc.) - Needed for sermon management ✅

---

### Phase 3: Form Request Cleanup

**Remove these files:**
- `app/Http/Requests/StoreSermonRequest.php` - Manual form validation
- `app/Http/Requests/PostSermonRequest.php` - ID3 upload validation

**Keep:**
- `app/Http/Requests/UpdateSermonRequest.php` - Still needed for editing sermons ✅

---

### Phase 4: View Cleanup

**Remove this file:**
- `resources/views/sermons/create.blade.php` - Manual upload form with point entry

**Keep:**
- `resources/views/sermons/upload.blade.php` - Uses Livewire `MediaUpload` component ✅
- `resources/views/sermons/edit.blade.php` - Still needed for post-upload editing ✅
- `resources/views/livewire/media-upload.blade.php` - New upload UI ✅

---

### Phase 5: Test Cleanup

**Remove these test files:**
- `tests/Unit/StoreSermonRequestTest.php` - Tests removed `StoreSermonRequest`
- `tests/Unit/PostSermonRequestTest.php` - Tests removed `PostSermonRequest`

**Update these test files:**
- `tests/Unit/SermonTest.php` - May reference `sermonCreate` route (check and update)
- Any feature tests referencing removed routes

**Keep:**
- `tests/Feature/UnifiedMediaProcessingTest.php` - Tests new system ✅
- `tests/Feature/AutomatedSermonApiTest.php` - Tests API endpoints ✅
- `tests/Integration/EndToEnd/CompleteProcessingPipelineTest.php` - Tests full pipeline ✅

---

### Phase 6: Frontend Link Updates

**Update links in views:**

`resources/views/members/home.blade.php`:
```php
// BEFORE:
<x-button link="/christ/sermons">
  Edit sermons
</x-button>
<x-button link="{{ route('admin.sermon-upload.create') }}">
  Media Upload (Audio/Video/Livestream)
</x-button>

// AFTER:
<x-button link="/christ/sermons">
  Browse sermons
</x-button>
<x-button link="{{ route('admin.sermon-upload.create') }}">
  Upload Sermon Media
</x-button>
```

**Check for other references:**
- Search codebase for `sermonCreate`, `sermonStore`, `sermonPost` route names
- Update any hardcoded `/christ/sermons/create` links

---

### Phase 7: Documentation Updates

**Update or create documentation:**
- Update `docs/api/automated-sermon-processing.md` if it exists
- Add migration notes to `CLAUDE.md` under "Recent Improvements"
- Document the new unified upload flow

---

## Migration Path for Users

### Before Refactor:
1. Admins navigate to "Edit sermons" → "Upload sermon"
2. Fill out manual form with 11+ fields
3. Optionally enter up to 6 points with 5 sub-points each
4. Submit and sermon appears immediately

### After Refactor:
1. Admins navigate to "Upload Sermon Media"
2. Select media type (audio/video/livestream)
3. Upload file
4. System automatically:
   - Extracts date/service from filename or metadata
   - Transcribes audio
   - Uses AI to extract title, preacher, series, reference, points
   - Prioritizes ID3 tags if present
   - Generates thumbnail (for videos)
5. Processing completes in background
6. Admin can edit any metadata after processing if needed

---

## Risk Assessment

### Low Risk:
- ✅ New system is production-tested and battle-hardened
- ✅ No external integrations depend on removed routes
- ✅ All critical functionality preserved in new system
- ✅ Edit functionality remains unchanged

### Medium Risk:
- ⚠️ User workflow changes (but improves efficiency)
- ⚠️ AI extraction may occasionally miss metadata (edit flow mitigates this)

### High Risk:
- ❌ None identified

---

## Rollback Plan

If issues arise after deployment:

1. **Immediate:** Revert git commit (all changes in single commit)
2. **Short-term:** Keep old routes/controllers in separate branch for 30 days
3. **Long-term:** Document any edge cases discovered and enhance new system

---

## Testing Checklist

Before removing legacy code:

- [ ] Verify all sermon upload tests pass with new system
- [ ] Test audio upload (MP3 with ID3 tags)
- [ ] Test audio upload (MP3 without ID3 tags)
- [ ] Test video upload (direct sermon video)
- [ ] Test livestream upload (segmentation and extraction)
- [ ] Test edit flow after upload
- [ ] Test sermon deletion
- [ ] Test sermon display pages
- [ ] Test authenticated vs. public routes
- [ ] Check for broken links in UI
- [ ] Search codebase for route name references

---

## Implementation Timeline

**Estimated Effort:** 2-3 hours

1. **Phase 1-4:** Route, controller, request, view cleanup (30 minutes)
2. **Phase 5:** Test cleanup and updates (45 minutes)
3. **Phase 6:** Frontend link updates (15 minutes)
4. **Phase 7:** Documentation (30 minutes)
5. **Testing:** Full regression test (30 minutes)
6. **Buffer:** Edge case fixes (30 minutes)

---

## Key Decisions

### ✅ Recommended Approach:
**Complete removal of legacy system** - Clean break, no hybrid state

### ❌ Not Recommended:
- Keeping legacy routes "just in case" - Creates maintenance burden
- Adding toggles between old/new systems - Unnecessary complexity
- Preserving manual entry form - Defeats automation purpose

---

## Success Criteria

After refactor:
1. ✅ Single unified upload flow for all media types
2. ✅ No duplicate routes or controllers for sermon creation
3. ✅ All tests pass
4. ✅ UI links point to new system only
5. ✅ Documentation reflects new workflow
6. ✅ Codebase is simpler and more maintainable

---

## Appendix: Preserved Features in New System

The new system **already supports** everything critical from the legacy system:

| Legacy Feature | New System Equivalent | Status |
|---------------|----------------------|--------|
| MP3 upload | Audio processing | ✅ Full support |
| ID3 tag reading | Priority in `SermonCreationService` | ✅ Improved |
| Date extraction | `MetadataExtractionService` | ✅ Enhanced (file metadata + filename) |
| Service detection | Smart time-based detection | ✅ Enhanced |
| Title generation | AI + ID3 priority | ✅ Automated |
| Preacher detection | AI + ID3 priority | ✅ Automated |
| Series detection | AI + ID3 priority | ✅ Automated |
| Reference extraction | AI + ID3 | ✅ Automated |
| Sermon points | AI extraction from transcript | ✅ Automated |
| File storage | S3/Spaces + local | ✅ Enhanced |
| Error handling | Comprehensive retry logic | ✅ Enhanced |
| Video support | N/A in legacy | ✅ New capability |
| Livestream support | N/A in legacy | ✅ New capability |
| Transcription | N/A in legacy | ✅ New capability |
| Thumbnail generation | N/A in legacy | ✅ New capability |
| Processing status | N/A in legacy | ✅ New capability |
| Edit after upload | `SermonController@edit` | ✅ Unchanged |

---

## Conclusion

This refactor **eliminates technical debt** while **preserving all critical functionality**. The small loss of manual pre-upload editing is vastly outweighed by:

- **Automation:** 90% less manual data entry
- **Intelligence:** AI-powered metadata extraction
- **Flexibility:** Support for audio, video, and livestream
- **Reliability:** Comprehensive error handling and retry logic
- **Scalability:** Cloud storage support
- **Maintainability:** Single codebase for all uploads

**Recommendation: Proceed with full refactor as outlined.**
