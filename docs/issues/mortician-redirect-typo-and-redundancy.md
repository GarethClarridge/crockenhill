# 🪦 Mortician: Redirect Typo & Redundancy in `config/redirects.php`

## 1. Redirect Typo in `config/redirects.php`

**Finding:**
Likely typo in key: `'contacttus' => '/'` (line 27).

**Evidence:**
```php
// config/redirects.php:27
'contacttus' => '/',
```
The key `'contactus'` (one 't') exists earlier (or should exist) if it's a common legacy URL. Search for "contacttus" in the rest of the codebase returns zero results.

**Risk:**
Low — Correcting the typo improves potential legacy URL handling.

**Recommendation:**
Correct 'contacttus' to 'contactus' (or remove if 'contactus' already exists and is sufficient).

---

## 2. Redundant Entries in `config/redirects.php`

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
Consolidate or keep as-is if explicit legacy support for both hyphenated and non-hyphenated versions is required. The typo in 'contacttus' is the most actionable item.
