# Mortician Audit Report - 2026-06-02

This report documents dead code and unused assets identified during the archaeology session on June 2nd, 2026.

## 🪦 Dead Legacy Meeting Admin Actions

**Artefact:**
- `App\Http\Controllers\MeetingController` (methods: `index`, `update`, `destroy`)
- `resources/views/meetings/index.blade.php`
- `meetings.*` routes in `routes/web.php` (excluding `meetings.show`)

**Evidence:**
- **Superseded by Livewire**: The admin interface has been migrated to modern Livewire components: `ListMeetings`, `CreateMeeting`, and `EditMeeting`. These components use the `admin.meetings.*` route namespace.
- **Zero UI References**: `grep -rn "meetings.index" app resources --exclude-dir=tests` returns zero references in the active UI. The only remaining link to the legacy index is within the legacy index view itself.
- **Invalid Redirects**: `MeetingController@update` and `destroy` redirect to `/church/members/meetings`, a path that does not exist in `routes/web.php`.
- **Legacy Test Coverage**: These methods are currently only exercised by older feature tests (e.g., `tests/Feature/MeetingControllerTest.php`). Modern functionality is verified by `tests/Feature/Livewire/Admin/Meetings/*`.

**Recommendation:** Safe to remove once legacy tests are reconciled.

---

## 🪦 Deprecated Service Shim

**Artefact:** `App\Services\ServiceSectionReviewTriggerEvaluator`

**Evidence:**
- **Deprecated**: The class is marked `@deprecated` and explicitly recommends using `AlignmentTriggerCalculator` and `UnmatchedSongReviewApplicator` instead.
- **No Callers**: `grep -rn "ServiceSectionReviewTriggerEvaluator" app/ --exclude=ServiceSectionReviewTriggerEvaluator.php` returns zero results.
- **Refactored**: Functionality was fully migrated in a previous refactoring session (Ref: `docs/reviews/oos-alignment-refactor-proposal.md`).

**Recommendation:** Safe to remove after deleting its associated unit test.

---

## 🪦 Legacy Upload Endpoint

**Artefact:** `SermonAdminController@processMedia` and route `admin.sermon-upload.store`.

**Evidence:**
- **Superseded**: Media uploads are now handled by the `media-upload` Livewire component.
- **No Callers**: The `processMedia` method is no longer invoked by the UI.

**Recommendation:** Worth a human review before removal.

---

## 🖼️ Unused Assets

**Asset:** `public/images/pattern-wide.png` (Removed in PR #xxx)
- **Evidence**: Zero references in code, views, or CSS.

**Asset:** `public/images/default-sermon-thumbnail.webp` (12KB)
- **Evidence**: The application exclusively uses the `.jpg` version as a fallback in `docs/` and tests.

**Recommendation:** Safe to remove.
