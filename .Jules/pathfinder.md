# Pathfinder Journal: Broken Link & Asset Reporter

## 2026-05-28 - Hardcoded meeting routes returning 404
**Pattern:** Hardcoded links to `/meetings/{slug}` in Blade templates.
**Cause:** The public route for meetings was moved to `/community/{slug}` in `routes/web.php`, but several templates still reference the old `/meetings/` prefix.
**Action:** Always verify meeting links against both `/meetings/` and `/community/` prefixes during crawls.

## 2026-05-28 - Stale redirect to missing privacy page
**Pattern:** `config/redirects.php` entry pointing to a non-existent slug.
**Cause:** `about-us/privacy-policy` redirects to `/church/privacy-policy`, but the page slug in the database is `privacy-notice`.
**Action:** Cross-reference redirect targets against the `pages` table and `route:list`.

## 2026-06-10 - Redirect typo causing 404
**Pattern:** Typo in redirect configuration key (e.g. `'contacttus'` instead of `'contactus'`).
**Cause:** Manual entry error in `config/redirects.php`.
**Action:** Verify redirect keys by hitting both the intended and spelled-in-config URLs with `curl -I`.
