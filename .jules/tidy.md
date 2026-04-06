# Tidy Journal

## 2026-03-30 - Refactoring Long Methods with External APIs
**Learning:** Long methods that mix business logic with external API interactions (like OpenAI), complex retry loops, and multi-stage response parsing/validation create high cognitive load and are difficult to test or modify.
**Action:** Extract API request logic into a private `executeAiRequest` method and response parsing/validation into a private `parseAiResponse` method. This separates the "how" of the API communication from the "what" of the business process.

## 2026-04-03 - [Centralizing Configuration Logic]
**Learning:** Repetitive configuration or path resolution logic across multiple class methods introduces technical debt and increases the risk of inconsistent behavior when the underlying logic changes.
**Action:** Centralize common configuration or path resolution into a single private method (e.g., `getResolvedPath`) and update all callers to utilize it, simplifying method signatures and improving maintainability.
