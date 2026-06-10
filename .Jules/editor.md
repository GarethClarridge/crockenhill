# Editor Journal

## 2026-06-11 - Typo in config/redirects.php
**Learning:** Found a typo in `config/redirects.php` where `'contacttus' => '/'` was defined. This typo was also previously identified in a Mortician audit.
**Action:** Corrected `'contacttus'` to `'contactus'` to ensure the intended redirect works for users typing the correct URL. Added a feature test `tests/Feature/RedirectTest.php` to verify the fix.
