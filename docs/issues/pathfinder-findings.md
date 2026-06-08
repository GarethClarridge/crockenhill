# 🔗 Pathfinder Finding: Broken Redirect Typo - 2026-06-08

## Summary
Pathfinder has identified a broken redirect in `config/redirects.php` caused by a slug mismatch. This results in a 404 error for visitors attempting to access the church's "Carols at the Chequers" event via legacy URLs.

## Affected URL
- **Source:** `whats-on/carolsatthechequers` (and `whatson/carolsatthechequers`)
- **Current Target:** `/community/carols-at-the-chequers`
- **Actual Status:** 404 Not Found
- **Correct Target:** `/community/carols-in-the-chequers`

## Evidence
- **Database Check:**
  ```bash
  php artisan tinker --execute 'echo App\Models\Meeting::where("slug", "carols-in-the-chequers")->exists() ? "Exists" : "Missing";'
  # Output: Exists

  php artisan tinker --execute 'echo App\Models\Meeting::where("slug", "carols-at-the-chequers")->exists() ? "Exists" : "Missing";'
  # Output: Missing
  ```
- **HTTP Verification:**
  ```bash
  curl -I http://127.0.0.1:8000/community/carols-at-the-chequers
  # Output: HTTP/1.1 404 Not Found
  ```

## Likely Cause
A typo in the redirect target configuration where "at" was used instead of "in", likely due to inconsistent naming between the legacy URL and the new system's slug convention.

## Suggested Action
Update `config/redirects.php` to point to the correct slug:
```php
'whats-on/carolsatthechequers' => '/community/carols-in-the-chequers',
// ...
'whatson/carolsatthechequers' => '/community/carols-in-the-chequers',
```

---

## Additional Diagnostic Notes (Triage required)

While investigating the above, the following minor issues were also noted for human review:

1.  **Dead Redirect:** The `reopening` path redirects to `/attending-in-person`, which returns a 404 as the page does not exist in the current system.
2.  **Sitemap Duplication:** The `Buzz Club` entry appears twice in the sitemap (once as a Page, once as a Meeting).
3.  **Sitemap URL Host:** Sitemap generation is defaulting to `http://localhost` due to `APP_URL` configuration.
