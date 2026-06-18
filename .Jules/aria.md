## 2026-08-11 - Reduced Motion Support
**Pattern:** Animations, transitions, and smooth scrolling can cause discomfort for users with vestibular disorders.
**Fix:** Use `motion-safe:` for animations (`animate-progress`, `animate-pulse`, `hover:-translate-y-1`) and check `window.matchMedia('(prefers-reduced-motion: reduce)')` in Alpine.js for scroll behavior.
