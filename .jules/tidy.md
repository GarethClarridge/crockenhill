## 2025-05-15 - [Long Method Extraction]
**Learning:** Core services with complex retry loops (like `SermonAnalysisService::performAiAnalysis`) become difficult to maintain when API logic, parsing, and error handling are all inlined.
**Action:** Extract API request execution and response parsing into dedicated private helper methods to improve readability while preserving error bubbling to the main retry loop.
