# Alter Ego Name Generator - Project Documentation

## Project Overview

### What It Does
A pattern-based anagram name generator that creates humorous alter ego names from a target name. Given a name like "John Smith", it:
1. Finds all words that are anagrams of each name component's letters
2. Applies creative patterns (templates) to combine these words
3. Generates hundreds of alter ego combinations like "Jots Mhin" or "Josh Mint"

### Why It Exists
Personal entertainment and creative wordplay exploration. Secondary goal: learning ground for LLM integration patterns applicable to day-job projects.

### Architecture Overview

```
┌─────────────┐
│   Target    │ (e.g., "John Smith")
└──────┬──────┘
       │
       ├──► Find matching token signatures (anagrams)
       │
       ├──► Apply patterns ({forename}{surname}, {surname}{forename}, etc.)
       │
       └──► Generate alter egos via DFS algorithm
            │
            └──► Filter by funniness/quality
```

**Key Components:**
- **Tokens**: Individual name parts (forename, surname, prefix, suffix)
- **Token Signatures**: Letter frequency signatures used for anagram matching
- **Patterns**: Templates defining how to combine tokens
- **DFS Service**: Depth-first search to fill patterns with valid anagram combinations
- **Word Lists**: Curated dictionaries marked as fun/ok/boring

## Technical Specification

### Data Model

**Core Entities:**
```
Target (the input name)
├── TargetPattern (pattern applied to target)
│   └── TargetSignaturedPattern (specific signature filling)
│       └── AlterEgo (final phrase)
├── TargetTokenSignature (matched anagram signatures)
└── TargetTokenSignatureWord (individual anagram words)
```

**Word Management:**
```
Token (forename, surname, etc.)
└── TokenSignature (letter frequency)
    └── TokenSignatureWord (actual word)
        ├── list_type: fun/ok/boring
        ├── language: en/fr/de/etc.
        ├── is_deferred: needs review
        └── is_nearly: intentional misspelling flag
```

### Key Algorithms

**1. DFS Pattern Filling** (Already Implemented)
- Given pattern positions and available signatures per position
- Recursively fills each position while maintaining letter count exactness
- Prunes branches that can't complete to valid anagrams
- Current performance: handles ~18 char names, times out on longer

**2. Word Matching** (Implemented)
- Signature-based lookup: O(1) for finding anagram candidates
- Filters by list_type to exclude boring words

**3. Quality Scoring** (Todo)
- Funniness detection via LLM prompts (copy/paste workflow)
- Gender consistency checking
- Dissonance detection (clashing sounds/meanings)

## 6. API Endpoints

### 6.1 Target Management

```
POST   /targets                  - Create target
GET    /targets                  - List targets
GET    /targets/{id}             - Show target details
DELETE /targets/{id}             - Delete target
POST   /targets/bulk-destroy     - Delete multiple targets
```

### 6.2 Target Processing

```
POST   /api/targets/{id}/process-new-matches  - Reprocess patterns
GET    /api/targets/{id}/progress             - Get processing status
GET    /api/targets/{id}/new-matches          - Get new word matches
POST   /api/targets/{id}/mark-matches-seen    - Mark matches as viewed
```

### 6.3 AlterEgo Management

```
POST   /api/targets/{id}/star      - Star an alter ego
POST   /api/targets/{id}/unstar    - Unstar an alter ego
POST   /api/targets/{id}/rephrase  - Regenerate phrase formatting
```

### 6.4 Word Curation

```
GET    /words                          - List words
POST   /words                          - Add word
PUT    /words/{id}                     - Update word
DELETE /words/{id}                     - Delete word
POST   /words/{id}/promote             - Promote word (ok → fun)
POST   /words/commit-resources         - Commit changes to files
```

### 6.5 Pattern Management

```
GET    /patterns               - List patterns
POST   /patterns               - Create pattern
PUT    /patterns/{id}          - Update pattern
DELETE /patterns/{id}          - Delete pattern
POST   /patterns/reorder       - Update popularity ranks
POST   /patterns/export        - Export patterns
```


## Implementation Roadmap

### Phase 1: Core UX Improvements (Priority - Next 2-4 weeks)

#### 1.1 Enhanced Word Matching UI
**Goal:** Better curation and filtering workflow

**Tasks:**
- [ ] Add word filtering controls
    - Show only used words toggle (already exists, enhance)
    - Filter by token type (forename/surname/etc.)
    - Filter by list_type (fun/ok/boring)
    - Filter by language
    - Save filter preferences to localStorage

- [ ] Click word to filter phrases
    - Highlight clicked word (already working via our changes)
    - Show only phrases containing that word
    - AND/OR toggle for multiple word selection
    - Clear filter button

- [ ] Visual indicators
    - Color-code: fun=green, ok=yellow, boring=red, deferred=gray
    - Show "used in X phrases" count per word
    - Distinguish "matched" vs "used in final phrases"

- [ ] Word curation actions
    - Promote word: ok → fun
    - Demote word: fun → ok → boring
    - Defer word: mark for later review
    - Bulk actions: select multiple, apply action

**Estimated effort:** 3-4 days

#### 1.2 Queued Processing with Live Updates
**Goal:** Non-blocking pattern processing with progress feedback

**Current problem:** Processing blocks, long names timeout

**Architecture:**
```
┌──────────────┐
│ User creates │
│    Target    │
└──────┬───────┘
       │
       ├──► Queue pattern processing jobs
       │
       ├──► Return immediately with "Processing..." status
       │
       └──► Poll for updates (simple approach)
            │
            └──► Update UI as patterns complete
```

**Tasks:**
- [ ] Add status fields to TargetPattern
    - `status`: pending/processing/filled/deferred/failed
    - Track: started_at, finished_at, elapsed_ms

- [ ] Job queue setup (simple DB-based)
    - Table: `pattern_processing_jobs`
    - States: queued → processing → completed/failed
    - No external queue system (Redis/etc) needed yet

- [ ] Background worker
    - Simple artisan command: `php artisan process:patterns`
    - Run via supervisor or cron (every minute)
    - Process one pattern at a time
    - Update status in DB

- [ ] Target show page updates
    - Display processing status per pattern
    - Progress indicator: "5/30 patterns complete"
    - Auto-refresh section every 2 seconds via Alpine.js
    - Button: "Set deferred patterns to pending"
    - Button: "Process pending now" (for immediate feedback)

**Alternative (simpler):** Long polling
```javascript
// In blade template
async function pollForUpdates() {
    const response = await fetch(`/targets/${targetId}/status`);
    const data = await response.json();
    updateUI(data);
    if (data.processing) {
        setTimeout(pollForUpdates, 2000);
    }
}
```

**Estimated effort:** 5-7 days

#### 1.3 Nearly Words Implementation
**Goal:** Support intentional misspellings for creative wordplay

**Concept Examples:**
- king → kin'g, k'ing
- horace → h'orace
- the → t'he, th'e

**Rules to implement:**
1. **Apostrophe insertion**: Split at any position
2. **Double consonants**: king → kinng (for surnames)
3. **Yoof speak substitutions**:
    - er → a (brother → brotha)
    - th → f (think → fink)
4. **Scots/dialect**:
    - n't → ae (isn't → isae)

**Tasks:**
- [ ] Add `nearly` column to `token_signature_words`
- [ ] Add `original_word` reference (what it's derived from)
- [ ] Service: `NearlyService::generateVariants(word, rules)`
- [ ] UI toggle: "Include nearly words"
- [ ] Visual indicator: different color/styling for nearly words
- [ ] Generate variants on demand vs. pre-compute (decide based on perf)

**Estimated effort:** 3-4 days

**Phase 1 Total:** ~2-3 weeks

---

### Phase 2: Quality & Intelligence (4-6 weeks)

#### 2.1 Funniness Scoring
**Approach:** LLM-assisted curation workflow

**Implementation:**
```php
class FunninessService
{
    // Generate prompt for user to paste into ChatGPT
    public function generatePrompt(array $phrases): string
    {
        return "Rate these fake names for humor (1-10):\n" 
            . implode("\n", $phrases);
    }
    
    // Parse pasted results
    public function parseScores(string $llmOutput): array
    {
        // Parse "John Smith: 8" format
    }
}
```

**Tasks:**
- [ ] Bulk export phrases to clipboard
- [ ] Textarea to paste LLM scores back
- [ ] Parse and apply scores
- [ ] Filter UI: "Funniness > 7" slider
- [ ] Auto-star phrases with score > 8

**Estimated effort:** 2-3 days

#### 2.2 Gender Consistency Checking
**Goal:** Flag phrases where gender-mismatched words combine

**Examples:**
- "King Sarah" (masculine + feminine)
- "Duchess Patrick" (feminine + masculine)

**Tasks:**
- [ ] Add gender tags to words: masculine/feminine/neutral
- [ ] Tag common gendered words (via LLM prompt or manual)
- [ ] Service: detect gender conflicts in phrases
- [ ] UI: flag inconsistent phrases, allow toggle to hide

**Estimated effort:** 3-4 days

#### 2.3 Dissonance Detection
**Goal:** Flag awkward sound combinations

**Examples:**
- Repeated sounds: "Bob Bobby"
- Hard-to-pronounce: "Gngst Trgth"

**Tasks:**
- [ ] Phonetic analysis (simple heuristics first)
- [ ] Flag phrases with:
    - Repeated syllables
    - 3+ consecutive consonants
    - Unpronounceable combinations
- [ ] Allow user override (some are intentionally funny)

**Estimated effort:** 4-5 days

---

### Phase 3: Advanced Features (6-8 weeks)

#### 3.1 Adjacency Rules
**Goal:** Context-aware pattern validation

**Example Rules:**
```php
// If pattern has d'{surname}, surname must start with vowel
[
    'pattern' => "d'{surname}",
    'rule' => 'starts_with_vowel',
    'applies_to' => 'surname'
]
```

**Tasks:**
- [ ] Define rule schema
- [ ] Implement rule engine
- [ ] Pre-filter pattern candidates based on rules
- [ ] Add custom rules via UI

**Estimated effort:** 5-6 days

#### 3.2 Bio Generation
**Goal:** Create character bios for alter egos

**Approach:** Agentic chaining
1. Generate character bio from name
2. Generate image prompt from bio
3. User copies prompt to DALL-E/Midjourney
4. Attach result to alter ego

**Tasks:**
- [ ] Bio generation prompts
- [ ] Image prompt generation
- [ ] Storage for generated content
- [ ] UI for managing bios/images

**Estimated effort:** 1 week

#### 3.3 Multi-language Support
**Goal:** Support non-English wordlists

**Priority order:** French > German > Spanish > Italian

**Tasks:**
- [ ] Import foreign word lists
- [ ] Language-specific nearly rules
- [ ] UI language filter
- [ ] Franglais mode (mix English + French)

**Estimated effort:** 2-3 weeks (per language)

---

### Phase 4: Data Management (Ongoing)

#### 4.1 Word List Management
**Current issue:** Changes to word list_type not tracked

**Solution: Committed/Uncommitted workflow**

```
User curates words (ok → fun, etc.)
    ↓
Words marked as uncommitted
    ↓
"Commit Resources" button appears
    ↓
1. Backup current resources/token_words (zip)
2. Generate changelog.txt
3. Merge uncommitted changes
4. Mark as committed
```

**Tasks:**
- [ ] Add `committed` boolean to token_signature_words
- [ ] Track changes in changelog
- [ ] Backup system
- [ ] Import/export personal wordlists
- [ ] Merge tool for integrating new sources

**Estimated effort:** 1 week

#### 4.2 Resource Expansion
- [ ] Import rude words list
- [ ] Import everyday funny foreign words
- [ ] Add anagram-dense word lists
- [ ] Multi-anagram word import tool

**Estimated effort:** Ongoing curation

---

### Phase 5: Architecture & Quality (8-12 weeks)

#### 5.1 Testing
- [ ] Unit tests for DFS algorithm
- [ ] Integration tests for pattern processing
- [ ] Test coverage: aim for 70%+

**Estimated effort:** 2-3 weeks

#### 5.2 API Layer
- [ ] RESTful API for targets/patterns
- [ ] Consider GraphQL for complex queries
- [ ] API documentation (OpenAPI/Swagger)

**Estimated effort:** 2-3 weeks

#### 5.3 Frontend Refactor
**Current:** Blade templates + Alpine.js  
**Future:** Consider Vue/Nuxt + Tailwind

**Questions before committing:**
- Does current approach feel limiting?
- Would SPA improve UX significantly?
- Time investment worth it for solo project?

**Estimated effort:** 4-6 weeks (if pursued)

---

## Performance Optimization Notes

**Current bottleneck:** DFS times out on long names (>18 chars)

**Potential optimizations:**

1. **Pre-filter by pattern slot length**
    - If pattern needs 5-letter word, skip signatures for 3-letter words
    - Estimate: 30-50% speedup

2. **Eliminate duplicate orders for repeated tokens**
    - `{surname}{surname}` generates duplicates
    - Track seen combinations
    - Estimate: 20-40% reduction in output

3. **Lazy evaluation**
    - Don't generate all combinations upfront
    - Generate first N, paginate on demand
    - Estimate: Feels instant for user

4. **Caching**
    - Cache signature → words lookup
    - Cache pattern → signature combinations
    - Estimate: 2-3x speedup on repeated patterns

**Recommendation:** Implement #3 (lazy evaluation) in Phase 1.2 since it pairs with queued processing.

---

## Monetization Ideas

Given this is a personal project with niche appeal, monetization is challenging but possible:

### Option 1: Freemium SaaS
- Free: 5 targets/month, 10 patterns each
- Pro ($5/mo): Unlimited targets, all patterns
- Premium ($15/mo): AI features (funniness scoring, bio generation)

**Pros:** Recurring revenue  
**Cons:** Requires multi-user support, auth, billing

### Option 2: One-Time Purchase Tool
- Desktop app (Electron): $20-30
- Includes all features, runs locally
- No ongoing hosting costs

**Pros:** Simple, no server needed  
**Cons:** Limited market, single payment

### Option 3: API Access
- Developers integrate into their apps
- $0.01 per alter ego generated
- Target: game developers, creative tools

**Pros:** B2B potential  
**Cons:** Requires rock-solid API, docs, support

### Option 4: Content Creation
- YouTube: "100 Funny Fake Names for [Celebrity]"
- TikTok: Short-form name generation content
- Monetize via ads, affiliate links to naming services

**Pros:** Builds audience first  
**Cons:** Competitive space, time-intensive

### Recommendation
Start with **Option 4** to validate interest while building. If traction exists, move to **Option 1** (freemium SaaS) since it aligns with your LLM learning goals and has recurring revenue potential.

---

## Next Actions

### This Week
1. Implement word-to-phrase mapping (we already started this)
2. Add client-side filtering to target show page
3. Deploy locally and test with a few targets

### Next Sprint (2 weeks)
1. Complete Phase 1.1 (Word Matching UI)
2. Start Phase 1.2 (Queued Processing)

### Questions to Resolve
1. **Breaking changes:** Can we refactor DFS to return generators instead of arrays? Would enable lazy evaluation.
2. **Deployment:** Will this stay local forever, or eventual public deployment?
3. **Data volume:** How many words in your current token_signature_words table?

Would you like me to:
1. **Drill into any specific phase** with more implementation details?
2. **Create database migration files** for the queued processing system?
3. **Write the NearlyService** with the rule engine?
4. **Design the API endpoints** if that's a priority?
