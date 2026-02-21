## 2026-02-21 - SQLite Compatibility and View Composers
**Learning:** Raw MySQL syntax like `DB::raw('RAND()')` or `SHOW INDEX FROM...` in migrations prevents tests from running in SQLite environments. Using `inRandomOrder()` is the standard Laravel way to ensure cross-database compatibility.
**Action:** Always prefer `inRandomOrder()` and wrap driver-specific migration logic in driver checks (`DB::getDriverName() === 'mysql'`).

## 2026-02-21 - Parallel Testing Artifacts
**Learning:** Running parallel tests with SQLite in Laravel creates local database files (e.g., `crockenhill_test_1`) in the root directory.
**Action:** Ensure these artifacts and any temporary `.env` files are removed before committing to avoid polluting the repository with binary data.
