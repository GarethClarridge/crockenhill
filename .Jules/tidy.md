# Tidy Journal 🧹

## 2026-06-10 - Standardizing Nullable String Attribute Setters
**Learning:** Nullable string attribute setters across models were inconsistent, using a mix of multi-line closures, `trim($value) ?: null` (which fails on "0"), and the `filled()` helper.
**Action:** Standardized on the pattern `set: fn (?string $value): ?string => filled($value) ? trim($value) : null`. This pattern is concise, safe for "0", and ensures consistent nullification of empty or whitespace-only strings across the domain.
