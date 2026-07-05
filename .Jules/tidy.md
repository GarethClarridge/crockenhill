# Tidy 🧹 — Code Quality Journal

## 2025-07-04 - [Laravel Collection Pipelines for Data Aggregation]
**Learning:** In services that perform statistical analysis or log parsing (like `SermonProcessingLogger`), using Laravel Collection pipelines (`map`, `filter`, `countBy`, `mapWithKeys`) provides a more expressive and readable alternative to nested `foreach` loops. It also facilitates easier unit testing of the individual transformation steps.
**Action:** Prefer collection pipelines over `foreach` when aggregating data or transforming log arrays into structured results.
