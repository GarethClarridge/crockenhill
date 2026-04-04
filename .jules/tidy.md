# Tidy Journal

## 2026-03-30 - Refactoring Long Methods with External APIs
**Learning:** Long methods that mix business logic with external API interactions (like OpenAI), complex retry loops, and multi-stage response parsing/validation create high cognitive load and are difficult to test or modify.
**Action:** Extract API request logic into a private `executeAiRequest` method and response parsing/validation into a private `parseAiResponse` method. This separates the "how" of the API communication from the "what" of the business process.

## 2026-04-03 - Refactoring Enums to TitleCase Keys
**Learning:** Enums in this project must use TitleCase keys (e.g., `case Pending = 'pending'`). Using `UPPER_SNAKE_CASE` is an inconsistency that should be refactored by updating both the enum definition and all call-sites, ensuring backed values remain unchanged to avoid database migrations.
**Action:** Always check and enforce TitleCase keys for enums, performing a project-wide search and replace for any identified inconsistencies.
