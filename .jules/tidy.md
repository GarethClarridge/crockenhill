# Tidy Journal

## 2026-03-30 - Refactoring Long Methods with External APIs
**Learning:** Long methods that mix business logic with external API interactions (like OpenAI), complex retry loops, and multi-stage response parsing/validation create high cognitive load and are difficult to test or modify.
**Action:** Extract API request logic into a private `executeAiRequest` method and response parsing/validation into a private `parseAiResponse` method. This separates the "how" of the API communication from the "what" of the business process.
