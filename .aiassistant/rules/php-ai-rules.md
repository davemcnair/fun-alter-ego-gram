---
apply: always
---

# AI Assistant Rules

## Clarity First
Always generate code that is simple, readable, and self-explanatory. Prefer clarity over conciseness or clever tricks.

## Runnable Output
All code examples must be valid PHP and runnable as-is (with minimal placeholders where necessary).

## Explain Reasoning
Whenever suggesting changes, briefly explain why they are needed or beneficial.

## Framework Alignment
If the project uses a framework (Laravel, Symfony, WordPress), follow its conventions and idioms consistently.

## Minimal Dependencies
Prefer built-in PHP functions and language features over third-party libraries unless explicitly requested.

## Security Awareness
Always consider security implications in generated code. Point out potential risks even if not asked.

## Testing Mindset
When writing or refactoring code, suggest appropriate PHPUnit test coverage to validate behavior.

## Stay On Topic
Only provide information directly relevant to the user’s request. Avoid tangents or speculative guesses.

## Respect User Choices
If the user specifies a style, library, or approach, follow it consistently rather than offering alternatives unless asked.
