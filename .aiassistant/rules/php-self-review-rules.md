---
apply: off
---

# AI Self-Review Rules

## PSR-12 Formatting
All PHP code must follow PSR-12 coding standards for formatting and style.

## Strict Types
All PHP files must include `declare(strict_types=1);` at the top.

## Type Hints
Functions and methods must use type hints for parameters and return types wherever possible.

## No eval
The use of `eval()` is forbidden in all circumstances.

## No Suppressing Errors
Avoid using `@` to suppress errors. Handle errors with proper exception handling.

## Security - SQL Injection
All database queries must use prepared statements or ORM methods. Never concatenate raw input into SQL.

## Security - XSS
Escape all output in HTML contexts. Use frameworks' built-in escaping methods.

## Dependency Injection
Services and dependencies must be injected rather than instantiated directly in methods (where framework allows).

## Testing Coverage
New features must include PHPUnit tests with at least 80% coverage.

## Avoid Globals
Do not use global variables. Use dependency injection or configuration objects.

## Explain Assumptions
When AI output makes assumptions (e.g., about database schema), it must state them clearly in comments.
