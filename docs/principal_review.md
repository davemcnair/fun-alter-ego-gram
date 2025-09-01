# Principal Developer Review and Refactor Roadmap

Date: 2025-09-01

This document summarizes the current architecture, identifies opportunities to improve correctness, performance, maintainability, and UX, and proposes a prioritized roadmap of refactors and features.


## Executive summary
- The domain and data flow are clear and pragmatic: generate alter-ego phrases from a source name using pattern templates and token word pools.
- Core services are well-separated: Anagrammer, PhraseBuilderService, WordMatchService, PatternQueryService (consumer), plus a single orchestration controller (SourceNameController).
- The main pain point is the monolithic search page (resources/views/source_names/show.blade.php), which mixes HTML, complex client-side logic, and state management. This increases complexity and brittleness.
- Algorithmic correctness has been prioritized recently (e.g., conservative pruning in Anagrammer). Further performance work should move heavy work off the request thread into background queues and add caching/indexing.
- Data model enhancements (AnagramGroup, signatures) are solid foundations for deduplication and future generator features.


## Architecture overview
- Models: SourceName, SourceNamePattern, AlterEgo, Word, AnagramGroup
- Services:
  - WordMatchService: DB-driven word pool matching by token/list with anagram dedupe option (default on).
  - Anagrammer: generates phrases from slots and candidates with a DFS and a correctness-first fallback.
  - PhraseBuilderService: formats phrases, handling capitalization and hyphenation.
  - PatternQueryService: provides pattern catalog per source (not shown here, used by controller).
  - TokenWordsBuilderService: utilities to build token lists from source files.
- Controller: SourceNameController — orchestrates lifecycle (create source, start, run step, pause/resume, progress, enable patterns).
- View: resources/views/source_names/show.blade.php — displays progress, alter ego groups, token word matches, filtering and interactivity (JS heavy).

Data flow (search):
1) UI loads search page and auto-starts.
2) runStep picks next pending pattern, collects matches via WordMatchService, flattens by token, and feeds Anagrammer.
3) Generated phrases are persisted; progress returns grouped phrases for client rendering.
4) Client renders groups incrementally, manages filters, highlights, and token word table.


## Current strengths
- Clear separation of concerns in the back end.
- Efficient DB filtering using precomputed signatures and subset checks.
- AnagramGroup links prepare for richer generation strategies and UI explorations.
- Incremental UI rendering avoids full-page refreshes; UX is responsive.


## Key pain points and risks
1) Frontend complexity in show.blade.php
   - A large amount of custom JS in one Blade file handles state, rendering, filtering, and DOM diffing manually.
   - Risks: regressions, difficulty to extend, duplicated logic (e.g., parsing templates in multiple places), hard-to-test.

2) Generation workload on request thread
   - runStep performs generation synchronously. With large candidate pools, even a single step can be heavy.
   - Risk: timeouts under load; limited scalability.

3) Algorithmic performance vs correctness
   - Anagrammer currently disables aggressive narrowing to ensure correctness. The fallback Cartesian check can be costly.
   - Risk: exponential work for certain patterns/sources.

4) Observability and testing
   - Limited unit/integration tests visible. No metrics on step timings, candidate sizes, or pruning effectiveness.

5) Data normalization and i18n
   - ASCII-only normalization and signatures. Limits names/wordlists with diacritics.

6) UX polish
   - The token word table is powerful but dense. Mixed responsibilities (promote, filter, expand/collapse) increase cognitive load.


## Refactor proposals
Short-term (low risk):
- Extract front-end helpers into namespaced modules (e.g., /resources/js/source_search/*) and import via Vite.
- Consolidate template parsing logic into a single JS module; reuse for highlighting, filtering, and used-word computation.
- Introduce small JSON endpoints for token word matches and progress to decouple template from data shaping (optional; Blade currently passes enough data).
- Add PHP unit tests:
  - HelpsMatchWords::isSubset / makeSignature
  - PhraseBuilderService formatting cases (hyphen runs, capitalization)
  - WordMatchService subset logic and anagram dedupe
  - Anagrammer: small, deterministic cases by pattern
- Add a feature flag to cap per-pattern phrases per step (env var), so we can tune responsiveness; currently commented in controller.

Mid-term:
- Move runStep generation to queued jobs:
  - Controller enqueues a job per pattern (or batch of patterns).
  - Use Redis queue; report progress via cache and polling endpoint.
  - This removes heavy compute from web requests and enables scalability.
- Caching layers:
  - Cache WordMatchService results per source signature (and include_boring flag) with a short TTL/invalidation on wordlist changes.
  - Cache flattened candidate token arrays for Anagrammer per pattern category.
- DB indexing:
  - Existing words(token_type, signature) index is good; add list_type composite where beneficial: (token_type, list_type, signature) to speed filtering per list.
  - Consider partial indexes or covering indexes depending on DB.
- Anagrammer optimization:
  - Re-enable safe narrowing by indexing candidates by rare letters per slot.
  - Maintain union histograms per slot type to prune early.
  - Memoize DFS states keyed by (slotIndex, needHistogramHash) for repeated subproblems.

Long-term:
- Frontend architecture:
  - Adopt a lightweight component model (Alpine.js or Vue with Inertia/Laravel Livewire) for stateful UI. Split token table and phrase list into components with clear props/events.
  - Replace manual DOM building with templating, keeping Blade for SSR and JS for hydration.
- Internationalization and richer normalization:
  - Support Unicode normalization (diacritics folding) optionally; keep ASCII-only fast path.
- Authoring tools:
  - Admin UI to manage word lists by token/list type with validations, conflicts, and anagram group views.
- Search experience:
  - Saved filters, starred phrases, export (CSV/JSON), and shareable URLs.


## Proposed roadmap (prioritized)
1) Stabilize and de-risk (1–2 weeks)
   - Test suite for helpers/services and a few controller integration paths.
   - Extract JS helpers to modules; reduce duplication; add eslint/prettier.
   - Reinstate phrases-per-step cap via env; make it configurable.

2) Scale and performance (1–2 weeks)
   - Queue-based generation; background workers; progress polling.
   - Cache WordMatchService results per signature.
   - Add metrics (per-step timings, candidates per slot, phrases emitted) via logs and Telescope.

3) UX iteration (1–2 weeks)
   - Componentize the search page; persist user toggles; refine token word table interactions; accessibility pass.
   - Add ‘Only used’ defaulting rules and confirm suffix/other tokens highlighting is comprehensive.

4) Data & i18n (future)
   - Optional Unicode support path; more curated lists; authoring UI for words/groups.


## Testing strategy
- Unit: services and helpers with small, deterministic inputs.
- Integration: runStep end-to-end with an in-memory DB, a tiny word pool, and a tiny pattern set.
- Browser (Dusk/Playwright): verify search UI toggles, filtering, and incrementally appended groups.
- Performance tests: synthetic large pools to monitor step times; guardrails on response times.


## Operational considerations
- Jobs & queues: Redis recommended; Horizon for monitoring.
- Idempotency: ensure alter ego inserts and job re-runs are idempotent (already using firstOrCreate for phrases).
- Migrations: new anagram groups table added; provide backfill command (words:backfill-anagram-groups).


## Quick wins already applied
- Added AnagramGroup model/table and wired it into imports/backfill.
- Enabled anagram dedupe in WordMatchService by default.
- UI enhancements for token word highlighting and filtering; ‘Only used’ toggle.
- Removed stray debug code in runStep.


## Appendix: suggested indexes
- words: index(token_type, signature)
- words: index(token_type, list_type, signature)
- alter_egos: index(source_name_id, source_name_pattern_id)
- source_name_patterns: index(source_name_id, status, popularity_rank)
