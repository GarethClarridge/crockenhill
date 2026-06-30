
## 2025-05-14 - Removed unused icon-button component
**Finding:** The `<x-icon-button>` component (`resources/views/components/icon-button.blade.php`) was identified as a dead asset.
**Reality:** It was only referenced in the developer component gallery (`resources/views/dev/components.blade.php`) and nowhere in the production codebase.
**Lesson:** Always check the dev gallery when searching for components, as it might be the last remaining reference to an otherwise dead primitive.
