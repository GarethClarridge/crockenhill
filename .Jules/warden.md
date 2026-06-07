# Warden 🏛️ — Data Integrity Journal

## 2026-05-26 - CHECK Constraints Require Data Cleanup First
**Learning:** MySQL validates ALL existing rows when `ALTER TABLE ... ADD CONSTRAINT ... CHECK` runs. A single dirty row (e.g. trailing whitespace in `sermons.reference`) causes the entire migration to fail in production with `SQLSTATE HY000 3819 Check constraint is violated`. This was confirmed in CI deploy. Local development databases seeded from factories never exhibit the problem because factories produce clean data.
**Action:** Every integrity migration that adds a `CHECK` constraint MUST first run a `DB::table()->update()` to normalise existing data into compliance. The pattern: trim → convert empty strings to NULL (if nullable) or a fallback value (if NOT NULL) → then `ALTER TABLE`. Never rely on the migrate failing loudly to surface dirty data — that breaks production deploys.

## 2026-04-21 - Aligning Validation with Database Constraints
**Learning:** Adding strict database-level `CHECK` constraints (e.g., lowercase email enforcement) without corresponding application-level validation or normalization can lead to unhandled `QueryException` errors (500 errors) for users.
**Action:** Always pair strict database constraints with:
1.  Application-level validation (e.g., `lowercase` rule).
2.  Model-level normalization (e.g., Eloquent Attribute mutators) to gracefully handle and fix data before it reaches the database.
3.  Defensive testing that asserts both DB rejection and validation-level handling.
