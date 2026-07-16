# Tidy 🧹 — Code Quality Journal

## 2025-07-04 - [Laravel Collection Pipelines for Data Aggregation]
**Learning:** In services that perform statistical analysis or log parsing (like `SermonProcessingLogger`), using Laravel Collection pipelines (`map`, `filter`, `countBy`, `mapWithKeys`) provides a more expressive and readable alternative to nested `foreach` loops. It also facilitates easier unit testing of the individual transformation steps.
**Action:** Prefer collection pipelines over `foreach` when aggregating data or transforming log arrays into structured results.

## 2024-03-20 - [Standardizing DTOs as Immutable Readonly Classes]
**Learning:** Transitioning DTOs (like `SermonCreationOptions`) to `final readonly class` prevents accidental state mutation and ensures data integrity throughout the processing pipeline. However, this change is breaking for any code that relies on post-instantiation property assignment (common in older tests).
**Action:** When converting DTOs to `readonly`, update any callers (including tests) to use the constructor with named arguments.
