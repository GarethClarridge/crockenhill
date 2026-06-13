# Tidy Journal 🧹

## 2026-06-10 - Standardizing Nullable String Attribute Setters
**Learning:** Nullable string attribute setters across models were inconsistent, using a mix of multi-line closures, `trim($value) ?: null` (which fails on "0"), and the `filled()` helper.
**Action:** Standardized on the pattern `set: fn (?string $value): ?string => filled($value) ? trim($value) : null`. This pattern is concise, safe for "0", and ensures consistent nullification of empty or whitespace-only strings across the domain.

## 2026-06-13 - Detailed PHPDoc Array Shapes for Complex Returns
**Learning:** Standard `array<string, mixed>` return hints hide the internal structure of complex service results, making them brittle and hard to maintain without deep file exploration.
**Action:** Use detailed array shape annotations (e.g., `array{key: string, sub: array{...}}`) in PHPDocs for private/internal methods that initialize or return complex structures. This provides immediate clarity for developers and enhances PHPStan's ability to catch property access errors.
