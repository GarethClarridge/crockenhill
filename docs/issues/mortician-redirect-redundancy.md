# 🪦 Mortician: Redirect Redundancy in `config/redirects.php`

## 1. Redundant Entries in `config/redirects.php`

**Finding:**
Several redirects are defined multiple times with identical targets, or have slight variations that might be consolidated.

**Evidence:**
```php
'aboutus/pastor' => '/church/pastor', // Line 33
...
'about-us/pastor' => '/church/pastor', // Line 48
```
While having both `aboutus` and `about-us` is intentional for legacy support, there is a lot of duplication in the file that could be simplified if a pattern-based approach was used, though currently the system uses a simple key-value loop.

**Recommendation:**
Consolidate or keep as-is if explicit legacy support for both hyphenated and non-hyphenated versions is required.
