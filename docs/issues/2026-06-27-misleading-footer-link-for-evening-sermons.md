# 🔗 Pathfinder: [1] broken [links] on [footer]

## Summary
The link labeled "Listen to evening sermons" in the site footer currently points to the general sermons archive (`/christ/sermons`) rather than the intended filtered view for evening services (`/christ/sermons/evening`).

## Affected items
- **Surface:** `resources/views/components/layout/footer.blade.php` (Line 15)
- **Label:** "Listen to evening sermons"
- **Current URL:** `/christ/sermons`
- **Expected URL:** `/christ/sermons/evening`

## Verification
1. **Manual Check:**
   - Navigating to `/christ/sermons` shows all sermons (Morning, Evening, and Other).
   - Navigating to `/christ/sermons/evening` correctly filters to only show "Sunday Evening Services".
2. **Commands:**
   ```bash
   # Confirm valid route exists
   curl -I http://127.0.0.1:8000/christ/sermons/evening
   # Confirm current link target
   grep -C 2 "Listen to evening sermons" resources/views/components/layout/footer.blade.php
   ```

## Likely cause
Likely a copy-paste error or a placeholder link that was never updated after the service-specific routes were implemented.

## Suggested action
Update the `href` attribute in the footer Blade component to point to the more specific route.

```blade
<<<<<<< SEARCH
        <a class="{{ $linkClasses }}" href="/christ/sermons" wire:navigate>
          Listen to evening sermons
        </a>
=======
        <a class="{{ $linkClasses }}" href="/christ/sermons/evening" wire:navigate>
          Listen to evening sermons
        </a>
>>>>>>> REPLACE
```

## Risk note
Minimal. This is a UX improvement that fulfills a "broken promise" to the user. Both routes exist and are functioning.
