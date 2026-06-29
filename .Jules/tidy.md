# Tidy Journal

## 2026-06-06 - Flattening Logic with Early Returns
**Learning:** In both shared traits and services, deep nesting often occurs when validating preconditions or loading external assets. Converting these to guard clauses (early returns) significantly improves readability.
**Action:** Always look for `if (exists) { ... }` blocks and convert them to `if (!exists) { return; }` to reduce indentation levels.
