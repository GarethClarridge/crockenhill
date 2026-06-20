# 🪦 Mortician: Possibly dead — Legacy Sermon Update Artefacts

## 1. Dead Job: `App\Jobs\UpdateSermonRecord`

**Artefact:** `app/Jobs/UpdateSermonRecord.php`

**Evidence:**
Project-wide grep for `UpdateSermonRecord` in production directories (`app/`, `resources/`, `routes/`, `config/`) returns **zero** callers.

```bash
grep -r "UpdateSermonRecord" app/ resources/ routes/ config/
# Result: 0 matches (excluding the file itself)
```

**Reality:**
This job appears to be a legacy orchestrator from an older version of the media processing pipeline. It has been superseded by the `UnifiedMediaProcessor` and the granular jobs coordinated by `ProcessingRunOrchestrator` (e.g., `ProcessTranscriptWithAI`, `CreateSermonRecord`). While several integration tests still exercise this job, they represent coverage for a legacy path that is no longer reachable in production.

**Risk:**
Low — The job is isolated and has no production callers.

**Recommendation:**
Safe to remove once the corresponding legacy tests are also retired or migrated.

---

## 2. Dead Form Request: `App\Http\Requests\UpdateSermonRequest`

**Artefact:** `app/Http/Requests/UpdateSermonRequest.php`

**Evidence:**
Project-wide grep for `UpdateSermonRequest` in production directories returns **zero** callers.

```bash
grep -r "UpdateSermonRequest" app/ resources/ routes/ config/
# Result: 0 matches
```

**Reality:**
This Form Request was likely used by a traditional controller-based sermon update action. Sermon editing has since been refactored into the `App\Livewire\Admin\Sermons\EditSermon` Livewire component, which uses its own internal validation logic or Form Objects.

**Risk:**
Low — Unreferenced class.

**Recommendation:**
Safe to remove.
