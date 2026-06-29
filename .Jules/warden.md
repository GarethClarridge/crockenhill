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

## 2026-06-21 - Centralized Model Validation Synchronization
**Learning:** Validation rules for models (e.g., Meeting) were incomplete and duplicated between the model and Livewire form objects. Centralizing these in a static `validationRules()` method on the model and consuming them in form objects ensures consistency and reduces technical debt.
**Action:** When fortifying model validation, always check corresponding Livewire forms or Form Requests and refactor them to use the model's `validationRules()`. Ensure rules that reference other fields (e.g. `after_or_equal:start_time`) are correctly mapped to their form property equivalents (e.g. `after_or_equal:startTime`).

## 2026-07-01 - Decoupling Model Integrity from UI-specific Defaults
**Learning:** Adding a `required` validation rule to a model to mirror a database `NOT NULL` constraint can break UI components that intentionally allow empty inputs (which are later populated with application-level defaults during submission).
**Action:** When a UI component needs to allow blank input for a required model field, dynamically filter the rule list in the component's `rules()` method (e.g. using `array_filter` to strip the `required` rule). This ensures the model remains the source of truth for schema-level integrity while allowing the UI to remain flexible.
