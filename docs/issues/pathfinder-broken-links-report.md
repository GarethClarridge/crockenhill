# 🔗 Pathfinder: Diagnostic Report - Broken Administrative Link

**Summary:** A broken administrative link was found in the sermon detail view.

## Broken Administrative Delete Link
- **Surface:** Sermon Detail Page (`/christ/sermons/{year}/{month}/{slug}`)
- **File:** `resources/views/sermons/sermon.blade.php`
- **What's broken:** The "Delete" button form action is hardcoded to a dated URL structure that does not exist in the application's route list.
- **Evidence:**
    - Code snippet: `<form method="POST" action="/christ/sermons/{{ date('Y', strtotime($sermon->date)) }}/{{ date('m', strtotime($sermon->date)) }}/{{ $sermon->slug }}/delete" ...>`
    - Actually Registered Route: `POST /christ/sermons/{sermon:slug}/delete` (named `sermons.destroy`)
- **Impact:** Administrators cannot delete sermons from the public detail page; clicking the button results in a 404 error.
- **Suggested Action:** Update the form action to use the named route: `action="{{ route('sermons.destroy', $sermon->slug) }}"`.

---
**Verification performed by:** Pathfinder 🔗
**Date:** 2026-06-14
