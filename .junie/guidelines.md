# Project Guidelines

Project: Alter-Ego Gram (Laravel)

Overview
- Purpose: Generate “alter ego” phrases from a person’s name by matching tokenized word lists to the name’s letter signature and expanding patterns into phrases.
- Core flow:
  1) TargetCreationService creates a Target from a display name, stores matched words for its letter signature, filters candidate Patterns, and enqueues fill/expand jobs.
  2) FillPatternSignaturesService generates signature-indexed patterns for each TargetPattern using WordMatchService + SignatureFillService.
  3) ExpandSignatureIndexedPatternService formats phrases from the signature-indexed patterns with PhraseBuilderService and stores AlterEgo rows.
- Key data concepts: Token, TokenSignature, TokenSignatureWord, Target, TargetPattern, TargetSignatureIndexedPattern, AlterEgo, TargetTokenSignatureWord (pivot).

Project structure (selected)
- app/Models: Eloquent models (Target, TargetPattern, TargetSignatureIndexedPattern, AlterEgo, Token, etc.)
- app/Services: Application services (WordMatchService, FillPatternSignaturesService, ExpandSignatureIndexedPatternService, etc.)
- app/Jobs: Queueable jobs (FillPatternSignaturesJob, ExpandSignatureIndexedPatternsJob)
- database/migrations: Schema (notably create_targets_tables migration)
- routes/web.php: HTTP routes for Targets, Patterns, Words and docs
- tests/Unit: PHPUnit tests covering services and flows
- .aiassistant/rules: Extra AI/self-review rules used by assistants
- .junie/guidelines.md: This file

Local development
- Requirements: PHP 8.2+, Composer, Node (optional for front-end), SQLite/MySQL for local dev. Tests default to in-memory SQLite via Laravel’s RefreshDatabase.
- Install: composer install; npm ci (optional).
- Environment: Copy .env.example to .env and adjust DB settings as needed.
- Database: php artisan migrate. Tests handle their own schema with RefreshDatabase.

Running tests
- Full test suite: php artisan test
- Unit tests only: php artisan test --testsuite=Unit
- Stop on first failure: php artisan test --testsuite=Unit --stop-on-failure
Note: CI and local runs should ensure all unit tests pass before merging. As of latest work, 44 unit tests pass (125 assertions).

Build/run
- Back end: No build step required beyond Composer autoload. Laravel app runs via php artisan serve or a web server.
- Front end: Vite config exists, but front-end is not required to run tests or back-end flows.
- Queues: Jobs respect config('search.queue'). If set, jobs dispatch to that queue name; otherwise default queue. For local dev without a worker, synchronous execution may be used where applicable.

Coding style and conventions
- Follow PSR-12 and Laravel conventions.
- Prefer service classes for domain logic; keep controllers thin.
- Write unit tests for new behaviors in tests/Unit.
- Migrations should be idempotent and reflect constraints used by services/tests (e.g., unique indexes, cascade deletes, pivot primary keys where needed).
- Favor idempotent inserts (firstOrCreate/upsert) for bulk operations that may repeat.

Junie (assistant) workflow
- Make minimal changes to satisfy the issue.
- Always review related models/migrations/services for coherence when changing schema-related code.
- Run relevant unit tests locally before submitting results.
- Use the project’s special tools (search_project, get_file_structure, open) to navigate code as needed.

Troubleshooting tips
- Unique constraint violations on pivot target_token_signature_words often indicate duplicate processing; use upsert/firstOrCreate semantics to avoid duplicates.
- If signature-indexed pattern expansion produces no rows, ensure Token and TokenSignatureWord data exists and that WordMatchService filters (include_boring, list_type) are set appropriately.
- Queue-related tests typically stub logs; failures may result from status transitions (pending → processing → done).
