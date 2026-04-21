# Warden 🏛️ — Data Integrity Journal

## 2026-04-21 - Aligning Validation with Database Constraints
**Learning:** Adding strict database-level `CHECK` constraints (e.g., lowercase email enforcement) without corresponding application-level validation or normalization can lead to unhandled `QueryException` errors (500 errors) for users.
**Action:** Always pair strict database constraints with:
1.  Application-level validation (e.g., `lowercase` rule).
2.  Model-level normalization (e.g., Eloquent Attribute mutators) to gracefully handle and fix data before it reaches the database.
3.  Defensive testing that asserts both DB rejection and validation-level handling.
